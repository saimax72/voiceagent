<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Services\Agents;
use App\Services\AI\Embeddings;
use App\Services\Jobs\JobQueue;
use App\Services\Knowledge\Crawler;
use App\Services\Knowledge\DocumentExtractor;
use App\Services\Knowledge\Indexer;
use App\Services\Plans;
use App\Services\Settings;

final class KnowledgeController
{
    private function agent(string $id): array
    {
        return Agents::findOrFail((int) $id, tenant_id());
    }

    private function source(array $agent, string $sourceId): array
    {
        $source = DB::instance()->fetch('SELECT * FROM knowledge_sources WHERE id = ? AND agent_id = ?', [(int) $sourceId, (int) $agent['id']]);
        if (!$source) {
            abort(404, 'Source not found.');
        }
        return $source;
    }

    public function index(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        $db = DB::instance();
        $sources = $db->fetchAll('SELECT s.*, (SELECT COUNT(*) FROM knowledge_documents d WHERE d.source_id = s.id AND d.status = \'indexed\') AS docs FROM knowledge_sources s WHERE s.agent_id = ? ORDER BY s.id DESC', [(int) $agent['id']]);
        $jobs = [];
        foreach (JobQueue::activeForAgent((int) $agent['id']) as $job) {
            $jobs[(int) ($job['payload']['source_id'] ?? 0)] = JobQueue::toArray($job);
            if (empty($job['payload']['source_id'])) {
                $jobs['agent'] = JobQueue::toArray($job);
            }
        }
        $documents = $db->count('knowledge_sources', 'agent_id = ? AND type IN (\'file\',\'faq\',\'text\',\'url\')', [(int) $agent['id']]);
        return view('knowledge/index', [
            'title' => 'Knowledge - ' . $agent['name'],
            'agent' => $agent,
            'sources' => $sources,
            'jobs' => $jobs,
            'stats' => Indexer::agentStats((int) $agent['id']),
            'limits' => ['pages' => Plans::limit($tenant, 'pages_per_agent'), 'documents' => Plans::limit($tenant, 'documents_per_agent'), 'documents_used' => $documents],
            'embeddings' => Embeddings::available(),
            'maxUpload' => DocumentExtractor::MAX_UPLOAD_BYTES,
            'extensions' => DocumentExtractor::ALLOWED_EXTENSIONS,
        ], 'layouts/app');
    }

    public function addWebsite(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        $raw = $request->string('url');
        $url = $raw !== '' ? Crawler::normalize(preg_match('~^https?://~i', $raw) ? $raw : 'https://' . $raw) : null;
        if ($url === null) {
            flash('error', 'Please enter a valid website address.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $limit = Plans::limit($tenant, 'pages_per_agent');
        $maxPages = max(1, min($limit, $request->int('max_pages', min($limit, Settings::int('crawler_max_pages_default', 100)))));
        $db = DB::instance();
        $now = now();
        $settings = ['max_pages' => $maxPages, 'restrict_to_path' => $request->boolean('restrict_to_path')];
        $existing = $db->fetch('SELECT * FROM knowledge_sources WHERE agent_id = ? AND type = \'website\' AND url = ? LIMIT 1', [(int) $agent['id'], $url]);
        if ($existing) {
            $db->update('knowledge_sources', ['status' => 'pending', 'settings' => json_encode($settings), 'updated_at' => $now], 'id = :id', ['id' => $existing['id']]);
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
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'crawl_website', ['source_id' => $sourceId, 'max_pages' => $maxPages]);
        flash('success', 'Website scan started. This usually takes a few minutes.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function rescan(Request $request, string $id, string $sourceId): Response
    {
        $agent = $this->agent($id);
        $source = $this->source($agent, $sourceId);
        $tenant = current_tenant();
        if ($source['type'] === 'website') {
            $settings = json_field($source['settings']);
            $maxPages = max(1, min(Plans::limit($tenant, 'pages_per_agent'), (int) ($settings['max_pages'] ?? 100)));
            JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'crawl_website', ['source_id' => (int) $source['id'], 'max_pages' => $maxPages]);
        } else {
            JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'process_source', ['source_id' => (int) $source['id']]);
        }
        DB::instance()->update('knowledge_sources', ['status' => 'pending', 'error_message' => null, 'updated_at' => now()], 'id = :id', ['id' => $source['id']]);
        flash('success', 'Re-scan started.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    private function checkDocumentLimit(array $agent, array $tenant): ?Response
    {
        $used = DB::instance()->count('knowledge_sources', 'agent_id = ? AND type IN (\'file\',\'faq\',\'text\',\'url\')', [(int) $agent['id']]);
        if ($used >= Plans::limit($tenant, 'documents_per_agent')) {
            flash('error', 'You have reached the number of documents included in your plan for this agent.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        return null;
    }

    public function upload(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        if ($r = $this->checkDocumentLimit($agent, $tenant)) {
            return $r;
        }
        $file = $request->file('file');
        if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Please choose a file to upload' . (isset($file['error']) && (int) $file['error'] === UPLOAD_ERR_INI_SIZE ? ' (the file is larger than the server allows)' : '') . '.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $name = (string) $file['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, DocumentExtractor::ALLOWED_EXTENSIONS, true)) {
            flash('error', 'Unsupported file type. Allowed: ' . implode(', ', DocumentExtractor::ALLOWED_EXTENSIONS));
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        if ((int) $file['size'] > DocumentExtractor::MAX_UPLOAD_BYTES) {
            flash('error', 'The file is too large (max ' . human_filesize(DocumentExtractor::MAX_UPLOAD_BYTES) . ').');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $storageMb = Plans::limit($tenant, 'storage_mb');
        $used = (int) DB::instance()->fetchColumn('SELECT COALESCE(SUM(file_size),0) FROM knowledge_sources WHERE tenant_id = ?', [(int) $tenant['id']]);
        if ($storageMb > 0 && $used + (int) $file['size'] > $storageMb * 1024 * 1024) {
            flash('error', 'Your storage limit (' . $storageMb . ' MB) would be exceeded.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $dir = APP_ROOT . '/storage/documents/' . (int) $tenant['id'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $relative = (int) $tenant['id'] . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], APP_ROOT . '/storage/documents/' . $relative)) {
            flash('error', 'Could not store the uploaded file.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $mime = (string) (mime_content_type(APP_ROOT . '/storage/documents/' . $relative) ?: ($file['type'] ?? 'application/octet-stream'));
        $sourceId = DB::instance()->insert('knowledge_sources', [
            'tenant_id' => (int) $tenant['id'], 'agent_id' => (int) $agent['id'], 'type' => 'file', 'title' => mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 200) ?: 'Document',
            'file_path' => $relative, 'file_name' => mb_substr($name, 0, 250), 'file_size' => (int) $file['size'], 'mime' => mb_substr($mime, 0, 100),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $tenant['id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        flash('success', 'File uploaded. The assistant is learning it now.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function addFaq(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        $questions = $request->array('question');
        $answers = $request->array('answer');
        $items = [];
        foreach ($questions as $i => $q) {
            $q = trim((string) $q);
            $a = trim((string) ($answers[$i] ?? ''));
            if ($q !== '' && $a !== '') {
                $items[] = ['question' => mb_substr($q, 0, 500), 'answer' => mb_substr($a, 0, 5000)];
            }
        }
        if (!$items) {
            flash('error', 'Please add at least one question with an answer.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $existingId = $request->int('source_id');
        if ($existingId > 0) {
            $source = $this->source($agent, (string) $existingId);
            $settings = json_field($source['settings']);
            $items = array_merge((array) ($settings['items'] ?? []), $items);
            DB::instance()->update('knowledge_sources', ['settings' => json_encode(['items' => $items]), 'status' => 'pending', 'updated_at' => now()], 'id = :id', ['id' => $source['id']]);
            $sourceId = (int) $source['id'];
        } else {
            if ($r = $this->checkDocumentLimit($agent, $tenant)) {
                return $r;
            }
            $sourceId = DB::instance()->insert('knowledge_sources', [
                'tenant_id' => (int) $tenant['id'], 'agent_id' => (int) $agent['id'], 'type' => 'faq', 'title' => mb_substr($request->string('title') ?: 'FAQ', 0, 200),
                'status' => 'pending', 'settings' => json_encode(['items' => $items]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        JobQueue::push((int) $tenant['id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        flash('success', count($items) . ' FAQ ' . (count($items) === 1 ? 'entry' : 'entries') . ' saved. Training in progress.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function addText(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        if ($r = $this->checkDocumentLimit($agent, $tenant)) {
            return $r;
        }
        $title = mb_substr($request->string('title'), 0, 200);
        $content = trim((string) $request->input('content', ''));
        if ($title === '' || mb_strlen($content) < 10) {
            flash('error', 'Please provide a title and some content.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        if (mb_strlen($content) > 200000) {
            flash('error', 'The text is too long (max 200,000 characters). Split it into several entries.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $sourceId = DB::instance()->insert('knowledge_sources', [
            'tenant_id' => (int) $tenant['id'], 'agent_id' => (int) $agent['id'], 'type' => 'text', 'title' => $title,
            'status' => 'pending', 'settings' => json_encode(['content' => $content]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $tenant['id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        flash('success', 'Text saved. Training in progress.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function addUrl(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        if ($r = $this->checkDocumentLimit($agent, $tenant)) {
            return $r;
        }
        $raw = $request->string('url');
        $url = $raw !== '' ? Crawler::normalize(preg_match('~^https?://~i', $raw) ? $raw : 'https://' . $raw) : null;
        if ($url === null) {
            flash('error', 'Please enter a valid page address.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        $sourceId = DB::instance()->insert('knowledge_sources', [
            'tenant_id' => (int) $tenant['id'], 'agent_id' => (int) $agent['id'], 'type' => 'url', 'title' => mb_substr($url, 0, 200), 'url' => $url,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        JobQueue::push((int) $tenant['id'], (int) $agent['id'], 'process_source', ['source_id' => $sourceId]);
        flash('success', 'Page added. Training in progress.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function reindex(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        if (JobQueue::activeForAgent((int) $agent['id'])) {
            flash('error', 'A training job is already running for this agent.');
            return redirect('/agents/' . $agent['id'] . '/knowledge');
        }
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'reindex_agent', []);
        flash('success', 'Re-training started.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function showSource(Request $request, string $id, string $sourceId): Response
    {
        $agent = $this->agent($id);
        $source = $this->source($agent, $sourceId);
        $page = max(1, $request->int('page', 1));
        $perPage = 50;
        $total = DB::instance()->count('knowledge_documents', 'source_id = ?', [(int) $source['id']]);
        $documents = DB::instance()->fetchAll('SELECT id, title, url, status, char_count, chunk_count, is_enabled, error_message, updated_at FROM knowledge_documents WHERE source_id = ? ORDER BY id ASC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), [(int) $source['id']]);
        return view('knowledge/source', [
            'title' => $source['title'], 'agent' => $agent, 'source' => $source, 'documents' => $documents,
            'page' => $page, 'pages' => (int) ceil($total / $perPage), 'total' => $total, 'settings' => json_field($source['settings']), 'sourceStats' => json_field($source['stats']),
        ], 'layouts/app');
    }

    public function deleteSource(Request $request, string $id, string $sourceId): Response
    {
        $agent = $this->agent($id);
        $source = $this->source($agent, $sourceId);
        Indexer::deleteSource($source);
        flash('success', 'Source removed from the knowledge base.');
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function showDocument(Request $request, string $id, string $docId): Response
    {
        $agent = $this->agent($id);
        $doc = DB::instance()->fetch('SELECT * FROM knowledge_documents WHERE id = ? AND agent_id = ?', [(int) $docId, (int) $agent['id']]);
        if (!$doc) {
            abort(404);
        }
        $source = DB::instance()->fetch('SELECT * FROM knowledge_sources WHERE id = ?', [(int) $doc['source_id']]);
        return view('knowledge/document', ['title' => $doc['title'], 'agent' => $agent, 'doc' => $doc, 'source' => $source, 'editable' => in_array($source['type'] ?? '', ['text', 'faq'], true)], 'layouts/app');
    }

    public function updateDocument(Request $request, string $id, string $docId): Response
    {
        $agent = $this->agent($id);
        $doc = DB::instance()->fetch('SELECT * FROM knowledge_documents WHERE id = ? AND agent_id = ?', [(int) $docId, (int) $agent['id']]);
        if (!$doc) {
            abort(404);
        }
        $content = trim((string) $request->input('content', ''));
        $title = mb_substr($request->string('title') ?: $doc['title'], 0, 490);
        if (mb_strlen($content) < 5) {
            flash('error', 'Content cannot be empty.');
            return redirect('/agents/' . $agent['id'] . '/knowledge/documents/' . $doc['id']);
        }
        DB::instance()->delete('knowledge_chunks', 'document_id = ?', [(int) $doc['id']]);
        DB::instance()->update('knowledge_documents', ['title' => $title, 'content' => $content, 'content_hash' => sha1($content), 'char_count' => mb_strlen($content), 'chunk_count' => 0, 'status' => 'pending', 'updated_at' => now()], 'id = :id', ['id' => $doc['id']]);
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'index_documents', []);
        flash('success', 'Document updated. Re-training in progress.');
        return redirect('/agents/' . $agent['id'] . '/knowledge/documents/' . $doc['id']);
    }

    public function toggleDocument(Request $request, string $id, string $docId): Response
    {
        $agent = $this->agent($id);
        $doc = DB::instance()->fetch('SELECT id, is_enabled, source_id FROM knowledge_documents WHERE id = ? AND agent_id = ?', [(int) $docId, (int) $agent['id']]);
        if (!$doc) {
            abort(404);
        }
        DB::instance()->update('knowledge_documents', ['is_enabled' => (int) $doc['is_enabled'] ? 0 : 1, 'updated_at' => now()], 'id = :id', ['id' => $doc['id']]);
        return back();
    }

    public function deleteDocument(Request $request, string $id, string $docId): Response
    {
        $agent = $this->agent($id);
        $doc = DB::instance()->fetch('SELECT id, source_id FROM knowledge_documents WHERE id = ? AND agent_id = ?', [(int) $docId, (int) $agent['id']]);
        if (!$doc) {
            abort(404);
        }
        Indexer::deleteDocument((int) $doc['id']);
        flash('success', 'Page removed.');
        return redirect('/agents/' . $agent['id'] . '/knowledge/sources/' . $doc['source_id']);
    }
}
