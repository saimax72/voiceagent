<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;
use App\Services\AI\Embeddings;
use App\Services\Jobs\JobQueue;
use App\Services\Knowledge\DocumentExtractor;
use App\Services\Knowledge\Indexer;
use App\Services\Knowledge\KnowledgeImport;
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

    private function back(array $agent): Response
    {
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function index(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        $db = DB::instance();
        $sources = $db->fetchAll('SELECT s.*, (SELECT COUNT(*) FROM knowledge_documents d WHERE d.source_id = s.id AND d.status = \'indexed\') AS docs FROM knowledge_sources s WHERE s.agent_id = ? ORDER BY s.id DESC', [(int) $agent['id']]);
        $jobs = [];
        foreach (JobQueue::activeForAgent((int) $agent['id']) as $job) {
            $sourceId = (int) ($job['payload']['source_id'] ?? 0);
            if ($sourceId > 0) {
                $jobs[$sourceId] = JobQueue::toArray($job);
            } else {
                $jobs['agent'] = JobQueue::toArray($job);
            }
        }
        return view('knowledge/index', [
            'title' => 'Knowledge - ' . $agent['name'],
            'agent' => $agent,
            'sources' => $sources,
            'jobs' => $jobs,
            'stats' => Indexer::agentStats((int) $agent['id']),
            'limits' => ['pages' => Plans::limit($tenant, 'pages_per_agent'), 'documents' => KnowledgeImport::documentLimit($tenant), 'documents_used' => KnowledgeImport::documentsUsed((int) $agent['id'])],
            'embeddings' => Embeddings::available(),
            'maxUpload' => DocumentExtractor::MAX_UPLOAD_BYTES,
            'extensions' => DocumentExtractor::ALLOWED_EXTENSIONS,
            'defaultPages' => min(Plans::limit($tenant, 'pages_per_agent') ?: 100, Settings::int('crawler_max_pages_default', 100)),
        ], 'layouts/app');
    }

    public function addWebsite(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        try {
            KnowledgeImport::addWebsite($agent, current_tenant(), $request->string('url'), $request->int('max_pages') ?: null, $request->boolean('restrict_to_path'));
            flash('success', 'Website scan started. This usually takes a few minutes.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        return $this->back($agent);
    }

    public function rescan(Request $request, string $id, string $sourceId): Response
    {
        $agent = $this->agent($id);
        $source = $this->source($agent, $sourceId);
        $tenant = current_tenant();
        if ($source['type'] === 'website') {
            $settings = json_field($source['settings']);
            $limit = Plans::limit($tenant, 'pages_per_agent');
            $maxPages = max(1, min($limit > 0 ? $limit : 5000, (int) ($settings['max_pages'] ?? 100)));
            JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'crawl_website', ['source_id' => (int) $source['id'], 'max_pages' => $maxPages]);
        } else {
            JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'process_source', ['source_id' => (int) $source['id']]);
        }
        DB::instance()->update('knowledge_sources', ['status' => 'pending', 'error_message' => null, 'updated_at' => now()], 'id = :id', ['id' => $source['id']]);
        flash('success', 'Re-scan started.');
        return $this->back($agent);
    }

    public function upload(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $tenant = current_tenant();
        $files = $request->files('files') ?: $request->files('file');
        if (!$files) {
            flash('error', 'Please choose at least one file to upload.');
            return $this->back($agent);
        }
        $uploaded = 0;
        foreach (array_slice($files, 0, 20) as $file) {
            try {
                KnowledgeImport::addFile($agent, $tenant, $file);
                $uploaded++;
            } catch (\RuntimeException $e) {
                flash('error', $e->getMessage());
            }
        }
        if ($uploaded > 0) {
            flash('success', $uploaded . ' file' . ($uploaded === 1 ? '' : 's') . ' uploaded. The assistant is learning ' . ($uploaded === 1 ? 'it' : 'them') . ' now.');
        }
        return $this->back($agent);
    }

    public function addFaq(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        $questions = $request->array('question') ?: $request->array('faq_question');
        $answers = $request->array('answer') ?: $request->array('faq_answer');
        $items = [];
        foreach ($questions as $i => $q) {
            $items[] = ['question' => (string) $q, 'answer' => (string) ($answers[$i] ?? '')];
        }
        try {
            $appendTo = $request->int('source_id') ?: null;
            KnowledgeImport::addFaq($agent, current_tenant(), $request->string('title') ?: 'FAQ', $items, $appendTo);
            $count = count(array_filter($items, static fn($it) => trim($it['question']) !== '' && trim($it['answer']) !== ''));
            flash('success', $count . ' FAQ ' . ($count === 1 ? 'entry' : 'entries') . ' saved. Training in progress.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        return $this->back($agent);
    }

    public function addText(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        try {
            KnowledgeImport::addText($agent, current_tenant(), $request->string('title'), (string) $request->input('content', ''));
            flash('success', 'Text saved. Training in progress.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        return $this->back($agent);
    }

    public function addUrl(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        try {
            KnowledgeImport::addUrl($agent, current_tenant(), $request->string('url'));
            flash('success', 'Page added. Training in progress.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        return $this->back($agent);
    }

    public function reindex(Request $request, string $id): Response
    {
        $agent = $this->agent($id);
        if (JobQueue::activeForAgent((int) $agent['id'])) {
            flash('error', 'A training job is already running for this agent.');
            return $this->back($agent);
        }
        JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'reindex_agent', []);
        flash('success', 'Re-training started.');
        return $this->back($agent);
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
        return $this->back($agent);
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
