<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Jobs\JobQueue;

final class UnansweredController
{
    public function index(Request $request): Response
    {
        $tenantId = tenant_id();
        $db = DB::instance();
        $status = in_array($request->string('status'), ['open', 'resolved', 'ignored'], true) ? $request->string('status') : 'open';
        $where = 'u.tenant_id = ? AND u.status = ?';
        $params = [$tenantId, $status];
        if ($request->int('agent') > 0) {
            $where .= ' AND u.agent_id = ?';
            $params[] = $request->int('agent');
        }
        $page = max(1, $request->int('page', 1));
        $perPage = 30;
        $total = (int) $db->fetchColumn('SELECT COUNT(*) FROM unanswered_questions u WHERE ' . $where, $params);
        $items = $db->fetchAll('SELECT u.*, a.name AS agent_name FROM unanswered_questions u INNER JOIN agents a ON a.id = u.agent_id WHERE ' . $where . ' ORDER BY u.occurrences DESC, u.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        return view('unanswered/index', [
            'title' => 'Unanswered questions',
            'items' => $items, 'status' => $status, 'total' => $total,
            'counts' => $db->fetchPairs('SELECT status, COUNT(*) FROM unanswered_questions WHERE tenant_id = ? GROUP BY status', [$tenantId]),
            'agents' => $db->fetchAll('SELECT id, name FROM agents WHERE tenant_id = ? ORDER BY name', [$tenantId]),
            'page' => $page, 'pages' => (int) ceil($total / $perPage), 'filters' => $request->query,
        ], 'layouts/app');
    }

    private function find(string $id): array
    {
        $item = DB::instance()->fetch('SELECT * FROM unanswered_questions WHERE id = ? AND tenant_id = ?', [(int) $id, tenant_id()]);
        if (!$item) {
            abort(404);
        }
        return $item;
    }

    /** Teach the assistant: the answer is added to a "Learned answers" FAQ source and indexed. */
    public function resolve(Request $request, string $id): Response
    {
        $item = $this->find($id);
        $answer = trim((string) $request->input('answer', ''));
        $question = trim($request->string('question')) ?: (string) $item['question'];
        if (mb_strlen($answer) < 2) {
            flash('error', 'Please write an answer.');
            return back();
        }
        $db = DB::instance();
        $agentId = (int) $item['agent_id'];
        $source = $db->fetch('SELECT * FROM knowledge_sources WHERE agent_id = ? AND type = \'faq\' AND title = \'Learned answers\' LIMIT 1', [$agentId]);
        $entry = ['question' => mb_substr($question, 0, 500), 'answer' => mb_substr($answer, 0, 5000)];
        if ($source) {
            $settings = json_field($source['settings']);
            $settings['items'] = array_merge((array) ($settings['items'] ?? []), [$entry]);
            $db->update('knowledge_sources', ['settings' => json_encode($settings), 'status' => 'pending', 'updated_at' => now()], 'id = :id', ['id' => $source['id']]);
            $sourceId = (int) $source['id'];
        } else {
            $sourceId = $db->insert('knowledge_sources', [
                'tenant_id' => tenant_id(), 'agent_id' => $agentId, 'type' => 'faq', 'title' => 'Learned answers', 'status' => 'pending',
                'settings' => json_encode(['items' => [$entry]]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        JobQueue::push(tenant_id(), $agentId, 'process_source', ['source_id' => $sourceId]);
        $db->update('unanswered_questions', ['status' => 'resolved', 'resolution' => $answer, 'updated_at' => now()], 'id = :id', ['id' => $item['id']]);
        // Resolve duplicates of the same question
        $db->update('unanswered_questions', ['status' => 'resolved', 'resolution' => $answer, 'updated_at' => now()], 'agent_id = :a AND question_hash = :h AND status = \'open\'', ['a' => $agentId, 'h' => $item['question_hash']]);
        flash('success', 'Answer saved. The assistant is learning it now.');
        return redirect('/unanswered');
    }

    public function ignore(Request $request, string $id): Response
    {
        $item = $this->find($id);
        DB::instance()->update('unanswered_questions', ['status' => 'ignored', 'updated_at' => now()], 'id = :id', ['id' => $item['id']]);
        return back();
    }

    public function destroy(Request $request, string $id): Response
    {
        $item = $this->find($id);
        DB::instance()->delete('unanswered_questions', 'id = ?', [(int) $item['id']]);
        return back();
    }
}
