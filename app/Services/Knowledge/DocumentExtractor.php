<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\Logger;
use App\Core\Str;
use App\Services\AI\LLM;
use App\Services\Settings;

/**
 * Extracts plain text from uploaded documents (PDF, DOCX, PPTX, TXT, MD, CSV, HTML, JSON).
 */
final class DocumentExtractor
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'pptx', 'txt', 'md', 'markdown', 'csv', 'html', 'htm', 'json', 'rtf'];
    public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    public static function extract(string $path, string $mime, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = strtolower($mime);
        if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
            return self::pdf($path);
        }
        if ($ext === 'docx' || str_contains($mime, 'wordprocessingml')) {
            return self::officeXml($path, ['word/document.xml'], ['w:p', 'w:tab', 'w:br']);
        }
        if ($ext === 'pptx' || str_contains($mime, 'presentationml')) {
            return self::pptx($path);
        }
        if ($ext === 'html' || $ext === 'htm' || str_contains($mime, 'html')) {
            $page = HtmlExtractor::extract((string) file_get_contents($path), 'https://local/' . $filename);
            return ($page['title'] !== '' ? '## ' . $page['title'] . "\n" : '') . $page['text'];
        }
        if ($ext === 'json') {
            $data = json_decode((string) file_get_contents($path), true);
            return self::flattenJson(is_array($data) ? $data : []);
        }
        if ($ext === 'rtf') {
            return self::rtf((string) file_get_contents($path));
        }
        if ($ext === 'csv') {
            return self::csv($path);
        }
        // Plain text / markdown
        $text = (string) file_get_contents($path);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }
        // Convert markdown headings to the heading marker used by the chunker
        $text = preg_replace('/^#{1,6}\s+/m', '## ', $text) ?? $text;
        return Str::normalizeWhitespace($text);
    }

    private static function pdf(string $path): string
    {
        $mode = (string) Settings::get('pdf_extraction', 'auto');
        $text = '';
        if ($mode !== 'ai') {
            try {
                $text = PdfTextExtractor::extract($path);
            } catch (\Throwable $e) {
                Logger::warning('Native PDF extraction failed: ' . $e->getMessage());
            }
        }
        $useful = self::isUsefulText($text);
        if (!$useful && $mode !== 'native') {
            $ai = self::pdfWithClaude($path);
            if ($ai !== '') {
                return $ai;
            }
        }
        return Str::normalizeWhitespace($text);
    }

    /** Heuristic: enough letters and words per character to be real text. */
    private static function isUsefulText(string $text): bool
    {
        $t = trim($text);
        if (mb_strlen($t) < 200) {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $t);
        $spaces = substr_count($t, ' ');
        return $letters / max(1, mb_strlen($t)) > 0.45 && $spaces > 20;
    }

    /** Ask Claude to read the PDF (handles scanned documents and complex layouts). */
    private static function pdfWithClaude(string $path): string
    {
        $provider = LLM::anthropic();
        if ($provider === null) {
            return '';
        }
        $size = filesize($path) ?: 0;
        if ($size > 30 * 1024 * 1024) {
            return '';
        }
        $result = $provider->complete([
            'system' => 'You convert documents to clean text for a knowledge base. Output only the document content.',
            'messages' => [['role' => 'user', 'content' => 'Extract the complete text content of this document. Preserve the reading order, keep headings as lines starting with "## ", render tables as simple lines, and skip page headers, footers and page numbers. Do not summarise or add commentary.']],
            'documents' => [['media_type' => 'application/pdf', 'data' => base64_encode((string) file_get_contents($path))]],
            'max_tokens' => 16000,
            'effort' => 'low',
            'timeout' => 300,
        ]);
        if (!$result->ok()) {
            Logger::error('AI PDF extraction failed: ' . $result->error);
            return '';
        }
        return Str::normalizeWhitespace($result->text);
    }

    private static function officeXml(string $path, array $entries, array $breakTags): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is required to read Office documents.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the document.');
        }
        $out = '';
        foreach ($entries as $entry) {
            $xml = $zip->getFromName($entry);
            if ($xml === false) {
                continue;
            }
            $out .= self::xmlToText($xml, $breakTags) . "\n";
        }
        $zip->close();
        return Str::normalizeWhitespace($out);
    }

    private static function pptx(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('The PHP zip extension is required to read presentations.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the presentation.');
        }
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $slides[(int) $m[1]] = $name;
            }
        }
        ksort($slides);
        $out = '';
        foreach ($slides as $n => $name) {
            $xml = (string) $zip->getFromName($name);
            $out .= "## Slide {$n}\n" . self::xmlToText($xml, ['a:p']) . "\n\n";
        }
        $zip->close();
        return Str::normalizeWhitespace($out);
    }

    private static function xmlToText(string $xml, array $breakTags): string
    {
        foreach ($breakTags as $tag) {
            $xml = preg_replace('#</' . preg_quote($tag, '#') . '>#', "\n", $xml) ?? $xml;
            $xml = preg_replace('#<' . preg_quote($tag, '#') . '(\s[^>]*)?/>#', "\n", $xml) ?? $xml;
        }
        // Headings in Word are paragraphs with a Heading style
        $xml = preg_replace_callback('#<w:p\b[^>]*>(.*?)</w:p>#s', static function (array $m): string {
            $isHeading = preg_match('#<w:pStyle w:val="(Heading|Title)#i', $m[1]);
            return ($isHeading ? '## ' : '') . $m[1];
        }, $xml) ?? $xml;
        $text = strip_tags(str_replace(['</w:t>', '</a:t>'], ['', ''], $xml));
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function csv(string $path): string
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return '';
        }
        $out = '';
        $header = null;
        $rows = 0;
        while (($row = fgetcsv($fh)) !== false && $rows < 5000) {
            $row = array_map(static fn($v) => trim((string) $v), $row);
            if ($header === null) {
                $header = $row;
                continue;
            }
            $pairs = [];
            foreach ($row as $i => $value) {
                if ($value === '') {
                    continue;
                }
                $pairs[] = (isset($header[$i]) && $header[$i] !== '' ? $header[$i] . ': ' : '') . $value;
            }
            if ($pairs) {
                $out .= implode('; ', $pairs) . "\n\n";
                $rows++;
            }
        }
        fclose($fh);
        return Str::normalizeWhitespace($out);
    }

    private static function flattenJson(array $data, string $prefix = ''): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            $label = $prefix === '' ? (string) $key : $prefix . ' ' . $key;
            if (is_array($value)) {
                $out .= self::flattenJson($value, $label);
            } else {
                $out .= $label . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) . "\n";
            }
        }
        return $out;
    }

    private static function rtf(string $rtf): string
    {
        $text = preg_replace('/\\\\par[d]?/', "\n", $rtf) ?? $rtf;
        $text = preg_replace("/\\\\'([0-9a-f]{2})/i", '', $text) ?? $text;
        $text = preg_replace('/\\\\[a-z]+-?\d* ?/i', '', $text) ?? $text;
        $text = str_replace(['{', '}'], '', $text);
        return Str::normalizeWhitespace($text);
    }
}
