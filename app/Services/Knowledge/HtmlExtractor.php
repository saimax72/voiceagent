<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\Http;
use App\Core\Str;

/**
 * Turns an HTML page into readable text (headings preserved) plus metadata and links.
 */
final class HtmlExtractor
{
    private const SKIP_TAGS = ['script', 'style', 'noscript', 'svg', 'canvas', 'iframe', 'template', 'nav', 'footer', 'header', 'aside', 'form', 'button', 'select', 'option', 'input', 'textarea', 'video', 'audio', 'picture', 'source', 'map', 'object', 'embed', 'dialog'];
    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'main', 'br', 'hr', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'tr', 'blockquote', 'pre', 'figure', 'figcaption', 'address', 'details', 'summary', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'thead', 'tbody'];
    private const SKIP_CLASS_PATTERN = '/(^|\s|-|_)(cookie|consent|gdpr|popup|modal|sidebar|breadcrumb|share|social|comment|advert|banner|newsletter|menu|navbar|nav-|pagination|skip-link)/i';

    /**
     * @return array{title:string,description:string,text:string,links:string[],lang:string,noindex:bool,canonical:string}
     */
    public static function extract(string $html, string $url): array
    {
        $result = ['title' => '', 'description' => '', 'text' => '', 'links' => [], 'lang' => '', 'noindex' => false, 'canonical' => ''];
        if (trim($html) === '') {
            return $result;
        }
        $html = self::toUtf8($html);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $titleNode = $xpath->query('//title')->item(0);
        $result['title'] = $titleNode ? Str::normalizeWhitespace($titleNode->textContent) : '';
        foreach ($xpath->query('//meta[@name or @property]') as $meta) {
            /** @var \DOMElement $meta */
            $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
            $content = trim($meta->getAttribute('content'));
            if ($name === 'description' && $result['description'] === '') {
                $result['description'] = Str::normalizeWhitespace($content);
            } elseif ($name === 'og:title' && $result['title'] === '') {
                $result['title'] = Str::normalizeWhitespace($content);
            } elseif ($name === 'robots' && preg_match('/noindex/i', $content)) {
                $result['noindex'] = true;
            }
        }
        $htmlNode = $xpath->query('//html')->item(0);
        if ($htmlNode instanceof \DOMElement) {
            $result['lang'] = substr(strtolower($htmlNode->getAttribute('lang')), 0, 5);
        }
        $canonical = $xpath->query('//link[@rel="canonical"]')->item(0);
        if ($canonical instanceof \DOMElement) {
            $result['canonical'] = Http::resolveUrl($url, trim($canonical->getAttribute('href')));
        }

        // Links (before pruning navigation so the crawler can discover all pages)
        $links = [];
        foreach ($xpath->query('//a[@href]') as $a) {
            /** @var \DOMElement $a */
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(javascript|mailto|tel|sms|data):/i', $href)) {
                continue;
            }
            if (preg_match('/nofollow/i', $a->getAttribute('rel'))) {
                continue;
            }
            $links[] = Http::resolveUrl($url, $href);
        }
        $result['links'] = array_values(array_unique($links));

        // Prefer the main content region when present
        $root = $xpath->query('//main')->item(0)
            ?? $xpath->query('//*[@role="main"]')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//body')->item(0);
        if (!$root) {
            return $result;
        }
        $h1 = $xpath->query('.//h1', $root)->item(0);
        $text = '';
        self::walk($root, $text);
        $text = Str::normalizeWhitespace($text);
        // Drop a leading duplicated title
        if ($result['title'] === '' && $h1) {
            $result['title'] = Str::normalizeWhitespace($h1->textContent);
        }
        $result['text'] = $text;
        return $result;
    }

    private static function walk(\DOMNode $node, string &$out): void
    {
        if ($node instanceof \DOMText) {
            $out .= preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '';
            return;
        }
        if (!($node instanceof \DOMElement)) {
            if ($node->hasChildNodes()) {
                foreach ($node->childNodes as $child) {
                    self::walk($child, $out);
                }
            }
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::SKIP_TAGS, true)) {
            return;
        }
        if ($node->getAttribute('aria-hidden') === 'true' || $node->getAttribute('hidden') !== '' || in_array($node->getAttribute('role'), ['navigation', 'banner', 'contentinfo', 'dialog', 'menu'], true)) {
            return;
        }
        $classId = $node->getAttribute('class') . ' ' . $node->getAttribute('id');
        if (trim($classId) !== '' && preg_match(self::SKIP_CLASS_PATTERN, $classId) && !in_array($tag, ['main', 'article', 'body'], true)) {
            return;
        }
        $style = $node->getAttribute('style');
        if ($style && preg_match('/display\s*:\s*none/i', $style)) {
            return;
        }

        $isHeading = (bool) preg_match('/^h[1-6]$/', $tag);
        $isBlock = in_array($tag, self::BLOCK_TAGS, true);
        if ($isBlock) {
            $out .= "\n";
        }
        if ($isHeading) {
            $out .= "\n## ";
        } elseif ($tag === 'li') {
            $out .= '- ';
        } elseif ($tag === 'td' || $tag === 'th') {
            $out .= ' ';
        }
        if ($tag === 'img') {
            $alt = trim($node->getAttribute('alt'));
            if ($alt !== '' && strlen($alt) > 3) {
                $out .= '(' . $alt . ') ';
            }
            return;
        }
        foreach ($node->childNodes as $child) {
            self::walk($child, $out);
        }
        if ($tag === 'td' || $tag === 'th') {
            $out .= ' |';
        }
        if ($isBlock || $isHeading) {
            $out .= "\n";
        }
        if ($tag === 'a' || $tag === 'span' || $tag === 'b' || $tag === 'strong' || $tag === 'em' || $tag === 'i') {
            $out .= ' ';
        }
    }

    public static function toUtf8(string $html): string
    {
        $encoding = null;
        if (preg_match('/<meta[^>]+charset=["\']?\s*([a-z0-9_-]+)/i', $html, $m)) {
            $encoding = strtoupper($m[1]);
        }
        if ($encoding && $encoding !== 'UTF-8' && $encoding !== 'UTF8') {
            $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
            if ($converted !== false && $converted !== '') {
                $html = $converted;
            }
        }
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        return $html;
    }

    /** Best-effort title fallback derived from a URL path. */
    public static function titleFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return Str::host($url) ?: 'Home';
        }
        $last = basename($path);
        $last = preg_replace('/\.(html?|php|aspx?)$/i', '', $last) ?? $last;
        return ucwords(str_replace(['-', '_'], ' ', $last));
    }
}
