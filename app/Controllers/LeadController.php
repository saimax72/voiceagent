<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;

final class LeadController
{
    private function filters(Request $request, int $tenantId): array
    {
        $where = ['l.tenant_id = ?'];
        $params = [$tenantId];
        if ($request->int('agent') > 0) {
            $where[] = 'l.agent_id = ?';
            $params[] = $request->int('agent');
        }
        $status = $request->string('status');
        if (in_array($status, ['new', 'contacted', 'qualified', 'closed', 'spam'], true)) {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }
        $q = $request->string('q');
        if ($q !== '') {
            $where[] = '(l.name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.message LIKE ?)';
            array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
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
        $total = (int) $db->fetchColumn('SELECT COUNT(*) FROM leads l WHERE ' . $where, $params);
        $leads = $db->fetchAll('SELECT l.*, a.name AS agent_name FROM leads l INNER JOIN agents a ON a.id = l.agent_id WHERE ' . $where . ' ORDER BY l.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        $counts = $db->fetchPairs('SELECT status, COUNT(*) FROM leads WHERE tenant_id = ? GROUP BY status', [$tenantId]);
        return view('leads/index', [
            'title' => 'Leads',
            'leads' => $leads, 'counts' => $counts, 'total' => $total,
            'agents' => $db->fetchAll('SELECT id, name FROM agents WHERE tenant_id = ? ORDER BY name', [$tenantId]),
            'page' => $page, 'pages' => (int) ceil($total / $perPage), 'filters' => $request->query,
        ], 'layouts/app');
    }

    public function update(Request $request, string $id): Response
    {
        $lead = DB::instance()->fetch('SELECT * FROM leads WHERE id = ? AND tenant_id = ?', [(int) $id, tenant_id()]);
        if (!$lead) {
            abort(404);
        }
        $data = ['updated_at' => now()];
        $status = $request->string('status');
        if (in_array($status, ['new', 'contacted', 'qualified', 'closed', 'spam'], true)) {
            $data['status'] = $status;
        }
        if ($request->has('notes')) {
            $data['notes'] = mb_substr($request->string('notes'), 0, 5000) ?: null;
        }
        DB::instance()->update('leads', $data, 'id = :id', ['id' => $lead['id']]);
        if ($request->wantsJson()) {
            return Response::json(['ok' => true]);
        }
        flash('success', 'Lead updated.');
        return back();
    }

    public function destroy(Request $request, string $id): Response
    {
        DB::instance()->delete('leads', 'id = ? AND tenant_id = ?', [(int) $id, tenant_id()]);
        flash('success', 'Lead deleted.');
        return back();
    }

    public function export(Request $request): Response
    {
        [$where, $params] = $this->filters($request, tenant_id());
        $rows = DB::instance()->fetchAll('SELECT l.*, a.name AS agent_name FROM leads l INNER JOIN agents a ON a.id = l.agent_id WHERE ' . $where . ' ORDER BY l.id DESC LIMIT 10000', $params);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['ID', 'Created (UTC)', 'Agent', 'Name', 'Email', 'Phone', 'Message', 'Status', 'Source', 'Page', 'Notes']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['id'], $r['created_at'], $r['agent_name'], $r['name'], $r['email'], $r['phone'], $r['message'], $r['status'], $r['source'], $r['page_url'], $r['notes']]);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="leads-' . gmdate('Y-m-d') . '.csv"']);
    }
}
