<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Plans;
use App\Services\Usage;

final class AnalyticsController
{
    private function compute(Request $request): array
    {
        $tenant = current_tenant();
        $tenantId = (int) $tenant['id'];
        $db = DB::instance();
        $days = in_array($request->int('days', 30), [7, 30, 90], true) ? $request->int('days', 30) : 30;
        $maxDays = Plans::limit($tenant, 'analytics_days');
        if ($maxDays > 0) {
            $days = min($days, max(7, $maxDays));
        }
        $agentId = $request->int('agent');
        $agentWhere = $agentId > 0 ? ' AND agent_id = ' . $agentId : '';
        $convAgentWhere = $agentId > 0 ? ' AND c.agent_id = ' . $agentId : '';
        $since = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $sinceDt = $since . ' 00:00:00';

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', strtotime("-{$i} days"))] = ['conversations' => 0, 'messages' => 0, 'voice_messages' => 0, 'leads' => 0, 'unanswered' => 0];
        }
        foreach ($db->fetchAll('SELECT day, SUM(conversations) c, SUM(messages) m, SUM(voice_messages) v, SUM(leads) l, SUM(unanswered) u FROM analytics_daily WHERE tenant_id = ? AND day >= ?' . $agentWhere . ' GROUP BY day', [$tenantId, $since]) as $row) {
            if (isset($series[$row['day']])) {
                $series[$row['day']] = ['conversations' => (int) $row['c'], 'messages' => (int) $row['m'], 'voice_messages' => (int) $row['v'], 'leads' => (int) $row['l'], 'unanswered' => (int) $row['u']];
            }
        }
        $totals = ['conversations' => 0, 'messages' => 0, 'voice_messages' => 0, 'leads' => 0, 'unanswered' => 0];
        foreach ($series as $d) {
            foreach ($totals as $k => $v) {
                $totals[$k] += $d[$k];
            }
        }
        $devices = $db->fetchPairs('SELECT COALESCE(device, \'unknown\'), COUNT(*) FROM conversations c WHERE c.tenant_id = ? AND c.is_test = 0 AND c.started_at >= ?' . $convAgentWhere . ' GROUP BY device', [$tenantId, $sinceDt]);
        $hours = array_fill(0, 24, 0);
        foreach ($db->fetchAll('SELECT HOUR(m.created_at) h, COUNT(*) n FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.tenant_id = ? AND c.is_test = 0 AND m.role = \'user\' AND m.created_at >= ?' . $convAgentWhere . ' GROUP BY HOUR(m.created_at)', [$tenantId, $sinceDt]) as $row) {
            $hours[(int) $row['h']] = (int) $row['n'];
        }
        $feedback = $db->fetch('SELECT SUM(m.feedback = 1) up, SUM(m.feedback = -1) down FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.tenant_id = ? AND c.is_test = 0 AND m.role = \'assistant\' AND m.created_at >= ?' . $convAgentWhere, [$tenantId, $sinceDt]);
        $topQuestions = $db->fetchAll('SELECT LEFT(m.content, 120) q, COUNT(*) n FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.tenant_id = ? AND c.is_test = 0 AND m.role = \'user\' AND m.created_at >= ? AND CHAR_LENGTH(m.content) BETWEEN 8 AND 200' . $convAgentWhere . ' GROUP BY LEFT(LOWER(m.content), 120) ORDER BY n DESC, q ASC LIMIT 10', [$tenantId, $sinceDt]);
        $topPages = $db->fetchAll('SELECT c.page_url, COUNT(*) n FROM conversations c WHERE c.tenant_id = ? AND c.is_test = 0 AND c.started_at >= ? AND c.page_url IS NOT NULL' . $convAgentWhere . ' GROUP BY c.page_url ORDER BY n DESC LIMIT 8', [$tenantId, $sinceDt]);
        $avgLatency = (float) ($db->fetchColumn('SELECT AVG(m.latency_ms) FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.tenant_id = ? AND m.role = \'assistant\' AND m.latency_ms > 0 AND m.created_at >= ?' . $convAgentWhere, [$tenantId, $sinceDt]) ?? 0);
        $avgMessages = (float) ($db->fetchColumn('SELECT AVG(message_count) FROM conversations c WHERE c.tenant_id = ? AND c.is_test = 0 AND c.started_at >= ?' . $convAgentWhere, [$tenantId, $sinceDt]) ?? 0);

        return [
            'days' => $days, 'agentId' => $agentId, 'series' => $series, 'totals' => $totals, 'devices' => $devices, 'hours' => $hours,
            'feedback' => ['up' => (int) ($feedback['up'] ?? 0), 'down' => (int) ($feedback['down'] ?? 0)],
            'topQuestions' => $topQuestions, 'topPages' => $topPages, 'avgLatency' => $avgLatency, 'avgMessages' => $avgMessages,
            'usage' => Usage::summary($tenantId), 'plan' => Plans::forTenant($tenant), 'maxDays' => $maxDays,
        ];
    }

    public function index(Request $request): Response
    {
        $data = $this->compute($request);
        $data['title'] = 'Analytics';
        $data['agents'] = DB::instance()->fetchAll('SELECT id, name FROM agents WHERE tenant_id = ? ORDER BY name', [tenant_id()]);
        return view('analytics/index', $data, 'layouts/app');
    }

    public function data(Request $request): Response
    {
        $data = $this->compute($request);
        unset($data['plan']);
        return Response::json($data);
    }
}
