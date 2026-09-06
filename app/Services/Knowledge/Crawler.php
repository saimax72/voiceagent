<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\DB;
use App\Core\Http;
use App\Core\Logger;
use App\Core\Str;
use App\Services\Jobs\JobQueue;
use App\Services\Settings;
use App\Services\Usage;

/**
 * Resumable website crawler. State lives in the crawl_urls table and the job's result column,
 * so a crawl can span many short worker runs (shared hosting friendly).
 */
final class Crawler
{
    private const MAX_DEPTH = 6;
    private const SKIP_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'css', 'js', 'json', 'xml', 'zip', 'rar', 'gz', 'tar', 'mp3', 'mp4', 'avi', 'mov', 'wmv', 'webm', 'woff', 'woff2', 'ttf', 'eot', 'exe', 'dmg', 'apk', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'rss', 'atom'];
    private const SKIP_PATH = '~(/wp-admin|/wp-login|/wp-json|/xmlrpc|/cart|/checkout|/my-account|/account|/login|/logout|/signin|/signup|/register|/search|/feed/?$|/tag/|/author/|/page/\d+|/wp-content/uploads|/cdn-cgi/|replytocom=|/basket|/print/|/share)~i';

    /** @return bool true when the job is finished */
    public static function run(array $job, float $deadline): bool
    {
        $db = DB::instance();
        $jobId = (int) $job['id'];
        $sourceId = (int) ($job['payload']['source_id'] ?? 0);
        $source = $db->fetch('SELECT * FROM knowledge_sources WHERE id = ?', [$sourceId]);
        if (!$source) {
            JobQueue::fail($jobId, 'Knowledge source no longer exists.');
            return true;
        }
        $settings = json_field($source['settings']);
        $state = json_field($job['result'] ?? null);
        $maxPages = max(1, (int) ($job['payload']['max_pages'] ?? $settings['max_pages'] ?? Settings::int('crawler_max_pages_default', 100)));
        $startUrl = self::normalize((string) $source['url']);
        if ($startUrl === null) {
            JobQueue::fail($jobId, 'The website URL is invalid.');
            $db->update('knowledge_sources', ['status' => 'error', 'error_message' => 'Invalid URL', 'updated_at' => now()], 'id = :id', ['id' => $sourceId]);
            return true;
        }

        if (empty($state['seeded'])) {
            $db->update('knowledge_sources', ['status' => 'processing', 'error_message' => null, 'updated_at' => now()], 'id = :id', ['id' => $sourceId]);
            $state = self::seed($job, $source, $startUrl, $maxPages, $settings);
            self::saveState($jobId, $state);
            JobQueue::progress($jobId, 3, 'Discovering pages...');
        }

        $host = Str::host($startUrl);
        $pathPrefix = (string) ($state['path_prefix'] ?? '');
        $disallow = (array) ($state['disallow'] ?? []);
        $userAgent = (string) Settings::get('crawler_user_agent', 'VoiceAgentBot/1.0');
        $timeout = max(5, Settings::int('crawler_timeout', 15));

        // Phase 1: fetch pages until the queue is empty or time is up
        while (microtime(true) < $deadline - 4) {
            $done = (int) $db->fetchColumn("SELECT COUNT(*) FROM crawl_urls WHERE job_id = ? AND status IN ('done','failed','skipped')", [$jobId]);
            $indexedCount = (int) ($state['pages_indexed'] ?? 0);
            if ($indexedCount >= $maxPages) {
                $db->query("UPDATE crawl_urls SET status = 'skipped', error = 'Page limit reached' WHERE job_id = ? AND status = 'queued'", [$jobId]);
                break;
            }
            $next = $db->fetch("SELECT * FROM crawl_urls WHERE job_id = ? AND status = 'queued' ORDER BY depth ASC, id ASC LIMIT 1", [$jobId]);
            if (!$next) {
                break;
            }
            $db->update('crawl_urls', ['status' => 'skipped'], 'id = :id', ['id' => $next['id']]); // provisional; updated below
            $url = (string) $next['url'];
            try {
                $outcome = self::fetchPage($url, $userAgent, $timeout);
            } catch (\Throwable $e) {
                $outcome = ['status' => 'failed', 'error' => $e->getMessage(), 'http' => 0];
            }
            $update = ['status' => $outcome['status'], 'http_status' => $outcome['http'] ?? null, 'error' => isset($outcome['error']) ? mb_substr((string) $outcome['error'], 0, 250) : null];
            if ($outcome['status'] === 'done') {
                $page = $outcome['page'];
                $title = $page['title'] !== '' ? $page['title'] : HtmlExtractor::titleFromUrl($url);
                $doc = Indexer::upsertDocument($source, $title, $url, $page['text'], [
                    'description' => $page['description'], 'lang' => $page['lang'], 'crawled_at' => now(),
                ]);
                $update['document_id'] = $doc['id'];
                $state['pages_indexed'] = $indexedCount + 1;
                $state['seen_documents'][] = (int) $doc['id'];
                // Discover links
                if ((int) $next['depth'] < self::MAX_DEPTH) {
                    $discovered = 0;
                    foreach ($page['links'] as $link) {
                        $norm = self::normalize($link);
                        if ($norm === null || !self::inScope($norm, $host, $pathPrefix, $disallow)) {
                            continue;
                        }
                        if (self::addUrl($jobId, $norm, (int) $next['depth'] + 1)) {
                            $discovered++;
                        }
                        if ($discovered > 400) {
                            break;
                        }
                    }
                }
            }
            $db->update('crawl_urls', $update, 'id = :id', ['id' => $next['id']]);
            $total = (int) $db->fetchColumn('SELECT COUNT(*) FROM crawl_urls WHERE job_id = ?', [$jobId]);
            $expected = min($maxPages, max($total, 1));
            $pct = 3 + (int) round(min(1, ($done + 1) / $expected) * 62);
            self::saveState($jobId, $state);
            JobQueue::progress($jobId, $pct, 'Scanned ' . ($state['pages_indexed'] ?? 0) . ' page' . (($state['pages_indexed'] ?? 0) === 1 ? '' : 's') . ' - ' . str_limit($title ?? $url, 60));
        }

        $remaining = (int) $db->fetchColumn("SELECT COUNT(*) FROM crawl_urls WHERE job_id = ? AND status = 'queued'", [$jobId]);
        if ($remaining > 0 && (int) ($state['pages_indexed'] ?? 0) < $maxPages) {
            return false; // continue on the next run
        }

        // Phase 2: remove pages that disappeared since the last scan (re-scan) and index new content
        if (empty($state['pruned'])) {
            $seen = array_values(array_unique(array_map('intval', (array) ($state['seen_documents'] ?? []))));
            if ($seen) {
                $placeholders = implode(',', array_fill(0, count($seen), '?'));
                $stale = $db->fetchAll('SELECT id FROM knowledge_documents WHERE source_id = ? AND id NOT IN (' . $placeholders . ')', array_merge([$sourceId], $seen));
                foreach ($stale as $row) {
                    Indexer::deleteDocument((int) $row['id']);
                }
            }
            $state['pruned'] = true;
            self::saveState($jobId, $state);
        }

        $finished = Indexer::indexPending((int) $source['agent_id'], $deadline, static function (int $donePages, int $totalPages) use ($jobId): void {
            $pct = 65 + (int) round(($totalPages > 0 ? $donePages / $totalPages : 1) * 33);
            JobQueue::progress($jobId, min(99, $pct), "Training the assistant ({$donePages}/{$totalPages} pages)...");
        }, $sourceId);
        if (!$finished) {
            return false;
        }

        $stats = Indexer::sourceStats($sourceId);
        $failed = (int) $db->fetchColumn("SELECT COUNT(*) FROM crawl_urls WHERE job_id = ? AND status = 'failed'", [$jobId]);
        $db->update('knowledge_sources', [
            'status' => $stats['documents'] > 0 ? 'ready' : 'error',
            'error_message' => $stats['documents'] > 0 ? null : 'No readable pages were found on this website.',
            'stats' => json_encode(array_merge($stats, ['pages_failed' => $failed, 'pages_found' => (int) $db->fetchColumn('SELECT COUNT(*) FROM crawl_urls WHERE job_id = ?', [$jobId])])),
            'last_synced_at' => now(), 'updated_at' => now(),
        ], 'id = :id', ['id' => $sourceId]);
        $db->update('agents', ['last_trained_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $source['agent_id']]);
        Usage::increment((int) $source['tenant_id'], (int) $source['agent_id'], 'pages_crawled', (int) ($state['pages_indexed'] ?? 0));
        JobQueue::complete($jobId, array_merge($state, $stats), 'Scanned ' . $stats['documents'] . ' pages, ' . $stats['chunks'] . ' knowledge chunks ready.');
        return true;
    }

    private static function seed(array $job, array $source, string $startUrl, int $maxPages, array $settings): array
    {
        $jobId = (int) $job['id'];
        $state = ['seeded' => true, 'pages_indexed' => 0, 'seen_documents' => [], 'disallow' => [], 'path_prefix' => '', 'sitemap_urls' => 0];
        $path = (string) parse_url($startUrl, PHP_URL_PATH);
        if ($path !== '' && $path !== '/' && !empty($settings['restrict_to_path'])) {
            $state['path_prefix'] = rtrim($path, '/');
        }
        $origin = self::origin($startUrl);
        $userAgent = (string) Settings::get('crawler_user_agent', 'VoiceAgentBot/1.0');
        $sitemaps = [];
        try {
            $robots = Http::get($origin . '/robots.txt', ['timeout' => 8, 'user_agent' => $userAgent, 'public_only' => true, 'max_bytes' => 200000]);
            if ($robots->ok() && str_contains($robots->contentType(), 'text')) {
                $parsed = self::parseRobots($robots->body);
                $state['disallow'] = $parsed['disallow'];
                $sitemaps = $parsed['sitemaps'];
            }
        } catch (\Throwable $e) {
            Logger::warning('robots.txt fetch failed: ' . $e->getMessage());
        }
        self::addUrl($jobId, $startUrl, 0);
        if (empty($settings['ignore_sitemap'])) {
            if (!$sitemaps) {
                $sitemaps = [$origin . '/sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/wp-sitemap.xml'];
            }
            $host = Str::host($startUrl);
            $added = 0;
            foreach (array_slice($sitemaps, 0, 5) as $sitemapUrl) {
                foreach (self::readSitemap($sitemapUrl, $userAgent, 0) as $url) {
                    $norm = self::normalize($url);
                    if ($norm && self::inScope($norm, $host, $state['path_prefix'], $state['disallow']) && self::addUrl($jobId, $norm, 1)) {
                        $added++;
                    }
                    if ($added >= $maxPages * 2) {
                        break 2;
                    }
                }
            }
            $state['sitemap_urls'] = $added;
        }
        return $state;
    }

    private static function readSitemap(string $url, string $userAgent, int $depth): array
    {
        if ($depth > 2) {
            return [];
        }
        try {
            $res = Http::get($url, ['timeout' => 12, 'user_agent' => $userAgent, 'public_only' => true, 'max_bytes' => 5_000_000]);
        } catch (\Throwable) {
            return [];
        }
        if (!$res->ok() || trim($res->body) === '') {
            return [];
        }
        $body = $res->body;
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = (string) @gzdecode($body);
        }
        if (!str_contains($body, '<urlset') && !str_contains($body, '<sitemapindex')) {
            return [];
        }
        $urls = [];
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$xml) {
            return [];
        }
        $name = $xml->getName();
        if ($name === 'sitemapindex') {
            $count = 0;
            foreach ($xml->sitemap as $entry) {
                $loc = trim((string) $entry->loc);
                if ($loc !== '' && $count++ < 20) {
                    $urls = array_merge($urls, self::readSitemap($loc, $userAgent, $depth + 1));
                }
                if (count($urls) > 5000) {
                    break;
                }
            }
        } elseif ($name === 'urlset') {
            foreach ($xml->url as $entry) {
                $loc = trim((string) $entry->loc);
                if ($loc !== '') {
                    $urls[] = $loc;
                }
                if (count($urls) > 5000) {
                    break;
                }
            }
        }
        return $urls;
    }

    private static function parseRobots(string $body): array
    {
        $disallow = [];
        $sitemaps = [];
        $applies = false;
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                $applies = $value === '*' || stripos($value, 'voiceagent') !== false;
            } elseif ($key === 'disallow' && $applies && $value !== '') {
                $disallow[] = $value;
            } elseif ($key === 'sitemap' && preg_match('~^https?://~i', $value)) {
                $sitemaps[] = $value;
            }
        }
        return ['disallow' => array_slice(array_unique($disallow), 0, 200), 'sitemaps' => array_slice(array_unique($sitemaps), 0, 10)];
    }

    private static function fetchPage(string $url, string $userAgent, int $timeout): array
    {
        $res = Http::get($url, [
            'timeout' => $timeout, 'user_agent' => $userAgent, 'public_only' => true, 'max_bytes' => 4_000_000,
            'headers' => ['Accept' => 'text/html,application/xhtml+xml,application/pdf;q=0.8,*/*;q=0.5', 'Accept-Language' => 'en,*;q=0.5'],
        ]);
        if ($res->error !== '') {
            return ['status' => 'failed', 'error' => $res->error, 'http' => $res->status];
        }
        if ($res->status >= 400 || $res->status === 0) {
            return ['status' => 'failed', 'error' => 'HTTP ' . $res->status, 'http' => $res->status];
        }
        $type = $res->contentType();
        if ($type === 'application/pdf' || str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf')) {
            $tmp = tempnam(APP_ROOT . '/storage/tmp', 'pdf');
            file_put_contents($tmp, $res->body);
            try {
                $text = DocumentExtractor::extract($tmp, 'application/pdf', basename($url));
            } finally {
                @unlink($tmp);
            }
            if (mb_strlen(trim($text)) < 80) {
                return ['status' => 'skipped', 'error' => 'PDF had no readable text', 'http' => $res->status];
            }
            return ['status' => 'done', 'http' => $res->status, 'page' => ['title' => HtmlExtractor::titleFromUrl($url), 'description' => '', 'text' => $text, 'links' => [], 'lang' => '']];
        }
        if (!str_contains($type, 'html') && !str_contains($type, 'xml') && $type !== '') {
            return ['status' => 'skipped', 'error' => 'Not an HTML page (' . $type . ')', 'http' => $res->status];
        }
        $page = HtmlExtractor::extract($res->body, $res->url ?: $url);
        if ($page['noindex']) {
            return ['status' => 'skipped', 'error' => 'Page is marked noindex', 'http' => $res->status];
        }
        if (mb_strlen($page['text']) < 80) {
            return ['status' => 'skipped', 'error' => 'Page has no readable text', 'http' => $res->status];
        }
        return ['status' => 'done', 'http' => $res->status, 'page' => $page];
    }

    private static function addUrl(int $jobId, string $url, int $depth): bool
    {
        try {
            $rows = DB::instance()->query(
                'INSERT IGNORE INTO crawl_urls (job_id, url, url_hash, depth, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$jobId, mb_substr($url, 0, 1000), sha1($url), $depth, 'queued', now()]
            )->rowCount();
            return $rows > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function inScope(string $url, string $host, string $pathPrefix, array $disallow): bool
    {
        if (Str::host($url) !== $host) {
            return false;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if ($pathPrefix !== '' && !str_starts_with($path, $pathPrefix)) {
            return false;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, self::SKIP_EXT, true)) {
            return false;
        }
        $full = $path . ((string) parse_url($url, PHP_URL_QUERY) !== '' ? '?' . parse_url($url, PHP_URL_QUERY) : '');
        if (preg_match(self::SKIP_PATH, $full)) {
            return false;
        }
        foreach ($disallow as $rule) {
            $regex = '~^' . str_replace('\*', '.*', preg_quote($rule, '~')) . '~';
            if (preg_match($regex, $full)) {
                return false;
            }
        }
        return true;
    }

    /** Canonical form of a URL, or null when it cannot be crawled. */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !preg_match('/^[a-z0-9.-]+\.[a-z0-9-]{2,}$/i', $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;
        if (str_contains($path, '/.')) {
            $segments = [];
            foreach (explode('/', $path) as $seg) {
                if ($seg === '.' || $seg === '') {
                    continue;
                }
                if ($seg === '..') {
                    array_pop($segments);
                    continue;
                }
                $segments[] = $seg;
            }
            $path = '/' . implode('/', $segments) . (str_ends_with($path, '/') && $segments ? '/' : '');
        }
        $path = preg_replace('~/index\.(html?|php)$~i', '/', $path) ?? $path;
        if ($path !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
            $path = rtrim($path, '/');
        }
        $query = '';
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $kept = [];
            foreach ($params as $k => $v) {
                if (preg_match('/^(utm_|fbclid|gclid|msclkid|ref|referrer|sessionid|phpsessid|sid|share|source|mc_cid|mc_eid|_ga|_gl|yclid|igshid|replytocom)/i', (string) $k)) {
                    continue;
                }
                $kept[$k] = $v;
            }
            if (count($kept) > 3) {
                return null; // faceted/filter pages
            }
            ksort($kept);
            if ($kept) {
                $query = '?' . http_build_query($kept);
            }
        }
        return $scheme . '://' . $host . $port . ($path === '' ? '/' : $path) . $query;
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function saveState(int $jobId, array $state): void
    {
        $state['seen_documents'] = array_values(array_unique(array_map('intval', (array) ($state['seen_documents'] ?? []))));
        DB::instance()->update('jobs', ['result' => json_encode($state), 'heartbeat_at' => now()], 'id = :id', ['id' => $jobId]);
    }
}
