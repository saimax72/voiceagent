<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;
use App\Services\Chat\Conversations;

final class ConversationController
{
    private function filters(Request $request, int $tenantId): array
    {
        $where = ['c.tenant_id = ?'];
        $params = [$tenantId];
        $agentId = $request->int('agent');
        if ($agentId > 0) {
            $where[] = 'c.agent_id = ?';
            $params[] = $agentId;
        }
        if ($request->boolean('test')) {
            $where[] = 'c.is_test = 1';
        } else {
            $where[] = 'c.is_test = 0';
        }
        $channel = $request->string('channel');
        if (in_array($channel, ['text', 'voice', 'mixed'], true)) {
            $where[] = $channel === 'voice' ? "c.channel IN ('voice','mixed')" : 'c.channel = ?';
            if ($channel !== 'voice') {
                $params[] = $channel;
            }
        }
        if ($request->boolean('lead')) {
            $where[] = 'c.has_lead = 1';
        }
        if ($request->boolean('unanswered')) {
            $where[] = 'c.has_unanswered = 1';
        }
        $q = $request->string('q');
        if ($q !== '') {
            $where[] = '(c.title LIKE ? OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.content LIKE ?))';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $from = $request->string('from');
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where[] = 'c.started_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        $to = $request->string('to');
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where[] = 'c.started_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        return [implode(' AND ', $where), $params];
    }

    public function index(Request $request): Response
    {
        $tenantId = tenant_id();
        [$where, $params] = $this->filters($request, $tenantId);
        $db = DB::instance();
        $page = max(1, $request->int('page', 1));
        $perPage = 25;
        $total = (int) $db->fetchColumn('SELECT COUNT(*) FROM conversations c WHERE ' . $where, $params);
        $rows = $db->fetchAll(
            'SELECT c.*, a.name AS agent_name FROM conversations c INNER JOIN agents a ON a.id = c.agent_id WHERE ' . $where . ' ORDER BY COALESCE(c.last_message_at, c.started_at) DESC, c.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );
        return view('conversations/index', [
            'title' => 'Conversations',
            'conversations' => $rows,
            'agents' => $db->fetchAll('SELECT id, name FROM agents WHERE tenant_id = ? ORDER BY name', [$tenantId]),
            'page' => $page, 'pages' => (int) ceil($total / $perPage), 'total' => $total,
            'filters' => $request->query,
        ], 'layouts/app');
    }

    public function show(Request $request, string $id): Response
    {
        $db = DB::instance();
        $conversation = $db->fetch('SELECT c.*, a.name AS agent_name, a.id AS agent_id FROM conversations c INNER JOIN agents a ON a.id = c.agent_id WHERE c.id = ? AND c.tenant_id = ?', [(int) $id, tenant_id()]);
        if (!$conversation) {
            abort(404, 'Conversation not found.');
        }
        return view('conversations/show', [
            'title' => $conversation['title'] ?: 'Conversation',
            'conversation' => $conversation,
            'messages' => Conversations::messages((int) $conversation['id']),
            'lead' => $db->fetch('SELECT * FROM leads WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', [(int) $conversation['id']]),
            'unanswered' => $db->fetchAll('SELECT * FROM unanswered_questions WHERE conversation_id = ? ORDER BY id DESC', [(int) $conversation['id']]),
        ], 'layouts/app');
    }

    public function destroy(Request $request, string $id): Response
    {
        $db = DB::instance();
        $conversation = $db->fetch('SELECT id, agent_id FROM conversations WHERE id = ? AND tenant_id = ?', [(int) $id, tenant_id()]);
        if (!$conversation) {
            abort(404);
        }
        $db->delete('messages', 'conversation_id = ?', [(int) $conversation['id']]);
        $db->query('UPDATE leads SET conversation_id = NULL WHERE conversation_id = ?', [(int) $conversation['id']]);
        $db->query('UPDATE unanswered_questions SET conversation_id = NULL WHERE conversation_id = ?', [(int) $conversation['id']]);
        $db->delete('conversations', 'id = ?', [(int) $conversation['id']]);
        Agents::refreshCounts((int) $conversation['agent_id']);
        flash('success', 'Conversation deleted.');
        return redirect('/conversations');
    }

    public function export(Request $request): Response
    {
        $tenantId = tenant_id();
        [$where, $params] = $this->filters($request, $tenantId);
        $rows = DB::instance()->fetchAll(
            'SELECT c.id, a.name AS agent, c.started_at, c.channel, c.message_count, c.has_lead, c.has_unanswered, c.device, c.page_url, c.title FROM conversations c INNER JOIN agents a ON a.id = c.agent_id WHERE ' . $where . ' ORDER BY c.id DESC LIMIT 5000',
            $params
        );
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['ID', 'Agent', 'Started (UTC)', 'Channel', 'Messages', 'Lead', 'Unanswered', 'Device', 'Page', 'First message']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['agent'], $r['started_at'], $r['channel'], $r['message_count'], $r['has_lead'] ? 'yes' : 'no', $r['has_unanswered'] ? 'yes' : 'no', $r['device'], $r['page_url'], $r['title']]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="conversations-' . gmdate('Y-m-d') . '.csv"']);
    }
}
