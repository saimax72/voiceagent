<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

/**
 * Splits document text into overlapping chunks that respect headings and paragraphs.
 */
final class Chunker
{
    /**
     * @return array<int, array{heading: ?string, content: string}>
     */
    public static function split(string $text, int $size = 1600, int $overlap = 200): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return [];
        }
        $size = max(400, $size);
        $overlap = max(0, min($overlap, (int) ($size / 3)));

        // Split into sections by markdown-style headings produced by the extractor
        $sections = [];
        $current = ['heading' => null, 'body' => ''];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^##\s+(.{2,200})$/', trim($line), $m)) {
                if (trim($current['body']) !== '') {
                    $sections[] = $current;
                }
                $current = ['heading' => trim($m[1]), 'body' => ''];
                continue;
            }
            $current['body'] .= $line . "\n";
        }
        if (trim($current['body']) !== '') {
            $sections[] = $current;
        }

        // Pack adjacent sections together up to the target size (page builders emit many tiny headed sections);
        // sections larger than the target are split on paragraph/sentence boundaries with overlap.
        $chunks = [];
        $buffer = ['heading' => null, 'content' => ''];
        $flush = static function () use (&$chunks, &$buffer): void {
            if (trim($buffer['content']) !== '') {
                $chunks[] = ['heading' => $buffer['heading'] !== null ? mb_substr($buffer['heading'], 0, 280) : null, 'content' => trim($buffer['content'])];
            }
            $buffer = ['heading' => null, 'content' => ''];
        };
        foreach ($sections as $section) {
            $body = trim($section['body']);
            $heading = $section['heading'];
            // Keep the heading inside the text so merged sections stay understandable
            $text = ($heading !== null ? $heading . "\n" : '') . $body;
            if (trim($text) === '') {
                continue;
            }
            if (mb_strlen($text) > $size) {
                $flush();
                foreach (self::splitBody($body, $size, $overlap) as $piece) {
                    $chunks[] = ['heading' => $heading !== null ? mb_substr($heading, 0, 280) : null, 'content' => $piece];
                }
                continue;
            }
            if ($buffer['content'] !== '' && mb_strlen($buffer['content']) + mb_strlen($text) + 2 > $size) {
                $flush();
            }
            if ($buffer['content'] === '') {
                $buffer['heading'] = $heading;
                $buffer['content'] = $text;
            } else {
                $buffer['content'] .= "\n\n" . $text;
            }
        }
        $flush();
        return $chunks;
    }

    private static function splitBody(string $body, int $size, int $overlap): array
    {
        if (mb_strlen($body) <= $size) {
            return [$body];
        }
        $paragraphs = preg_split("/\n{2,}/", $body) ?: [$body];
        // Further split very long paragraphs into sentences
        $units = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (mb_strlen($p) <= $size) {
                $units[] = $p;
                continue;
            }
            $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'(\p{Lu}])/u', $p) ?: [$p];
            $buf = '';
            foreach ($sentences as $s) {
                if (mb_strlen($s) > $size) {
                    // Hard wrap extremely long sentences
                    foreach (mb_str_split($s, $size - 50) as $part) {
                        $units[] = $part;
                    }
                    continue;
                }
                if (mb_strlen($buf) + mb_strlen($s) + 1 > $size && $buf !== '') {
                    $units[] = $buf;
                    $buf = '';
                }
                $buf .= ($buf === '' ? '' : ' ') . $s;
            }
            if ($buf !== '') {
                $units[] = $buf;
            }
        }

        $chunks = [];
        $current = '';
        foreach ($units as $unit) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($unit) + 2 > $size) {
                $chunks[] = trim($current);
                $tail = $overlap > 0 ? self::tail($current, $overlap) : '';
                $current = $tail !== '' ? $tail . "\n\n" . $unit : $unit;
            } else {
                $current .= ($current === '' ? '' : "\n\n") . $unit;
            }
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }
        return $chunks;
    }

    /** Last ~N characters of text, cut at a sentence/word boundary. */
    private static function tail(string $text, int $chars): string
    {
        if (mb_strlen($text) <= $chars) {
            return $text;
        }
        $tail = mb_substr($text, -$chars);
        $pos = mb_strpos($tail, '. ');
        if ($pos !== false && $pos < $chars - 40) {
            return trim(mb_substr($tail, $pos + 2));
        }
        $space = mb_strpos($tail, ' ');
        return trim($space !== false ? mb_substr($tail, $space + 1) : $tail);
    }
}
