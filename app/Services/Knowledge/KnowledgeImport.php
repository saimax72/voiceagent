<?php
declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Core\DB;
use App\Core\Request;
use App\Core\Str;
use App\Services\Agents;
use App\Services\Jobs\JobQueue;
use App\Services\Plans;
use App\Services\Settings;

/**
 * Single entry point for adding knowledge to an agent (website, files, FAQs, text, pages).
 * Used by the agent creation form, the onboarding wizard and the Knowledge page.
 */
final class KnowledgeImport
{
    public const DOCUMENT_TYPES = ['file', 'faq', 'text', 'url'];

    public static function documentsUsed(int $agentId): int
    {
        return DB::instance()->count('knowledge_sources', 'agent_id = ? AND type IN (\'file\',\'faq\',\'text\',\'url\')', [$agentId]);
    }

    public static function documentLimit(array $tenant): int
    {
        return Plans::limit($tenant, 'documents_per_agent');
    }

    public static function canAddDocument(array $agent, array $tenant): bool
    {
        $limit = self::documentLimit($tenant);
        return $limit <= 0 || self::documentsUsed((int) $agent['id']) < $limit;
    }

    private static function assertDocumentSlot(array $agent, array $tenant): void
    {
        if (!self::canAddDocument($agent, $tenant)) {
            throw new \RuntimeException('Your plan allows ' . self::documentLimit($tenant) . ' documents per agent. Upgrade to add more.');
        }
    }

    /** Start (or restart) a website scan. Returns the source id. */
    public static function addWebsite(array $agent, array $tenant, string $rawUrl, ?int $maxPages = null, bool $restrictToPath = false, bool $ignoreRobots = true): int
    {
        $rawUrl = trim($rawUrl);
        $url = $rawUrl !== '' ? Crawler::normalize(preg_match('~^https?://~i', $rawUrl) ? $rawUrl : 'https://' . $rawUrl) : null;
        if ($url === null) {
            throw new \RuntimeException('Please enter a valid website address, for example https://www.example.com');
        }
        $limit = Plans::limit($tenant, 'pages_per_agent');
        $maxPages = max(1, min($limit > 0 ? $limit : 5000, $maxPages ?? min($limit > 0 ? $limit : 5000, Settings::int('crawler_max_pages_default', 100))));
        $db = DB::instance();
        $now = now();
        $settings = ['max_pages' => $maxPages, 'restrict_to_path' => $restrictToPath, 'ignore_robots' => $ignoreRobots];
        $existing = $db->fetch('SELECT * FROM knowledge_sources WHERE agent_id = ? AND type = \'website\' AND url = ? LIMIT 1', [(int) $agent['id'], $url]);
        if ($existing) {
            $db->update('knowledge_sources', ['status' => 'pending', 'error_message' => null, 'settings' => json_encode($settings), 'updated_at' => $now], 'id = :id', ['id' => $existing['id']]);
            $sourceId = (int) $existing['id'];
        } else {
            $sourceId = $db->insert('knowledge_sources', [
                'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'], 'type' => 'website', 'title' => Str::host($url), 'url' => $url,
                'status' => 'pending', 'settings' => json_encode($settings), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if (empty($agent['website_url'])) {
            Agents::update((int) $agent['id'], ['website_url' => $url]);
        }
        $active = array_filter(JobQueue::activeForAgent((int) $agent['id']), static fn($j) => $j['type'] === 'crawl_website' && (int) ($j['payload']['source_id'] ?? 0) === $sourceId);
        if (!$active) {
            JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'crawl_website', ['source_id' => $sourceId, 'max_pages' => $maxPages]);
        }
        return $sourceId;
    }

    /** Add a single page (or online PDF). */
    public static function addUrl(array $agent, array $tenant, string $rawUrl): int
    {
        self::assertDocumentSlot($agent, $tenant);
        $rawUrl = trim($rawUrl);
        $url = $rawUrl !== '' ? Crawler::normalize(preg_match('~^https?://~i', $rawUrl) ? $rawUrl : 'https://' . $rawUrl) : null;
        if ($url === null) {
            throw new \RuntimeException('"' . mb_substr($rawUrl, 0, 60) . '" is not a valid page address.');
        }
        $sourceId = DB::instance()->insert('knowledge_sources', [
            'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'], 'type' => 'url', 'title' => mb_substr($url, 0, 200), 'url' => $url,
            'status' => 'pending', 'settings' => json_encode(['ignore_robots' => true]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        return $sourceId;
    }

    /** Add free text the assistant should know. */
    public static function addText(array $agent, array $tenant, string $title, string $content): int
    {
        self::assertDocumentSlot($agent, $tenant);
        $title = mb_substr(trim($title), 0, 200) ?: 'Business information';
        $content = trim($content);
        if (mb_strlen($content) < 10) {
            throw new \RuntimeException('Please write at least a sentence of information.');
        }
        if (mb_strlen($content) > 200000) {
            throw new \RuntimeException('The text is too long (max 200,000 characters). Split it into several entries.');
        }
        $sourceId = DB::instance()->insert('knowledge_sources', [
            'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'], 'type' => 'text', 'title' => $title,
            'status' => 'pending', 'settings' => json_encode(['content' => $content]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        return $sourceId;
    }

    /**
     * Add FAQ entries. $items = [['question' => ..., 'answer' => ...], ...]. Appends to an existing FAQ source when given.
     */
    public static function addFaq(array $agent, array $tenant, string $title, array $items, ?int $appendToSourceId = null): int
    {
        $clean = [];
        foreach ($items as $item) {
            $q = trim((string) ($item['question'] ?? ''));
            $a = trim((string) ($item['answer'] ?? ''));
            if ($q !== '' && $a !== '') {
                $clean[] = ['question' => mb_substr($q, 0, 500), 'answer' => mb_substr($a, 0, 5000)];
            }
        }
        if (!$clean) {
            throw new \RuntimeException('Please add at least one question with an answer.');
        }
        $db = DB::instance();
        if ($appendToSourceId) {
            $source = $db->fetch('SELECT * FROM knowledge_sources WHERE id = ? AND agent_id = ? AND type = \'faq\'', [$appendToSourceId, (int) $agent['id']]);
            if ($source) {
                $settings = json_field($source['settings']);
                $settings['items'] = array_merge((array) ($settings['items'] ?? []), $clean);
                $db->update('knowledge_sources', ['settings' => json_encode($settings), 'status' => 'pending', 'error_message' => null, 'updated_at' => now()], 'id = :id', ['id' => $source['id']]);
                JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'process_source', ['source_id' => (int) $source['id']]);
                return (int) $source['id'];
            }
        }
        self::assertDocumentSlot($agent, $tenant);
        $sourceId = $db->insert('knowledge_sources', [
            'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'], 'type' => 'faq', 'title' => mb_substr(trim($title), 0, 200) ?: 'FAQ',
            'status' => 'pending', 'settings' => json_encode(['items' => $clean]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        return $sourceId;
    }

    /** Store an uploaded document ($_FILES entry) and queue it for training. */
    public static function addFile(array $agent, array $tenant, array $file): int
    {
        $name = (string) ($file['name'] ?? 'document');
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
                ? '"' . $name . '" is larger than the server allows.'
                : '"' . $name . '" could not be uploaded (error ' . $error . ').');
        }
        self::assertDocumentSlot($agent, $tenant);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, DocumentExtractor::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('"' . $name . '" has an unsupported type. Allowed: ' . implode(', ', DocumentExtractor::ALLOWED_EXTENSIONS) . '.');
        }
        if ((int) $file['size'] > DocumentExtractor::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('"' . $name . '" is too large (max ' . human_filesize(DocumentExtractor::MAX_UPLOAD_BYTES) . ').');
        }
        $storageMb = Plans::limit($tenant, 'storage_mb');
        $used = (int) DB::instance()->fetchColumn('SELECT COALESCE(SUM(file_size),0) FROM knowledge_sources WHERE tenant_id = ?', [(int) $tenant['id']]);
        if ($storageMb > 0 && $used + (int) $file['size'] > $storageMb * 1024 * 1024) {
            throw new \RuntimeException('Your storage limit (' . $storageMb . ' MB) would be exceeded by "' . $name . '".');
        }
        $dir = APP_ROOT . '/storage/documents/' . (int) $tenant['id'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $relative = (int) $tenant['id'] . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $target = APP_ROOT . '/storage/documents/' . $relative;
        $moved = is_uploaded_file((string) $file['tmp_name']) ? move_uploaded_file((string) $file['tmp_name'], $target) : @rename((string) $file['tmp_name'], $target);
        if (!$moved) {
            throw new \RuntimeException('Could not store "' . $name . '" on the server.');
        }
        $mime = (string) (@mime_content_type($target) ?: ($file['type'] ?? 'application/octet-stream'));
        $sourceId = DB::instance()->insert('knowledge_sources', [
            'tenant_id' => (int) $tenant['id'], 'agent_id' => (int) $agent['id'], 'type' => 'file',
            'title' => mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 200) ?: 'Document',
            'file_path' => $relative, 'file_name' => mb_substr($name, 0, 250), 'file_size' => (int) $file['size'], 'mime' => mb_substr($mime, 0, 100),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $tenant['id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        return $sourceId;
    }

    /**
     * Import everything submitted by the shared "Knowledge base" form fields
     * (files[], faq_question[]/faq_answer[], info_title/info_content, extra_urls).
     * @return array{added: string[], errors: string[]}
     */
    public static function importFromRequest(array $agent, array $tenant, Request $request): array
    {
        $added = [];
        $errors = [];

        $files = $request->files('files');
        $uploaded = 0;
        foreach (array_slice($files, 0, 20) as $file) {
            try {
                self::addFile($agent, $tenant, $file);
                $uploaded++;
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($uploaded > 0) {
            $added[] = $uploaded . ' document' . ($uploaded === 1 ? '' : 's');
        }

        $questions = $request->array('faq_question');
        $answers = $request->array('faq_answer');
        $items = [];
        foreach ($questions as $i => $q) {
            $items[] = ['question' => (string) $q, 'answer' => (string) ($answers[$i] ?? '')];
        }
        $items = array_values(array_filter($items, static fn($it) => trim($it['question']) !== '' && trim($it['answer']) !== ''));
        if ($items) {
            try {
                self::addFaq($agent, $tenant, 'FAQ', $items);
                $added[] = count($items) . ' FAQ ' . (count($items) === 1 ? 'entry' : 'entries');
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $content = trim((string) $request->input('info_content', ''));
        if ($content !== '') {
            try {
                self::addText($agent, $tenant, $request->string('info_title'), $content);
                $added[] = 'custom information';
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $urls = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $request->input('extra_urls', '')) ?: [])));
        $pages = 0;
        foreach (array_slice($urls, 0, 10) as $url) {
            try {
                self::addUrl($agent, $tenant, $url);
                $pages++;
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($pages > 0) {
            $added[] = $pages . ' page' . ($pages === 1 ? '' : 's');
        }

        return ['added' => $added, 'errors' => $errors];
    }

    /** Human-readable summary for flash messages, e.g. "2 documents, 3 FAQ entries and custom information". */
    public static function summary(array $result): string
    {
        $added = $result['added'];
        if (!$added) {
            return '';
        }
        if (count($added) === 1) {
            return $added[0];
        }
        $last = array_pop($added);
        return implode(', ', $added) . ' and ' . $last;
    }
}
