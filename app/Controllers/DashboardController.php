<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;
use App\Services\AI\LLM;
use App\Services\Plans;
use App\Services\Usage;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $tenant = current_tenant();
        $tenantId = (int) $tenant['id'];
        $db = DB::instance();
        $agents = Agents::forTenant($tenantId);
        if (!$agents && !(int) $tenant['onboarding_completed'] && !auth()->isSuperAdmin()) {
            return redirect('/onboarding');
        }
        $monthStart = gmdate('Y-m-01 00:00:00');
        $stats = [
            'agents' => count($agents),
            'conversations' => $db->count('conversations', 'tenant_id = ? AND is_test = 0 AND started_at >= ?', [$tenantId, $monthStart]),
            'messages' => Usage::get($tenantId, 'messages'),
            'voice_messages' => Usage::get($tenantId, 'voice_messages'),
            'leads' => $db->count('leads', 'tenant_id = ? AND created_at >= ?', [$tenantId, $monthStart]),
            'leads_new' => $db->count('leads', 'tenant_id = ? AND status = \'new\'', [$tenantId]),
            'unanswered' => $db->count('unanswered_questions', 'tenant_id = ? AND status = \'open\'', [$tenantId]),
        ];
        $recentConversations = $db->fetchAll(
            'SELECT c.*, a.name AS agent_name FROM conversations c INNER JOIN agents a ON a.id = c.agent_id WHERE c.tenant_id = ? AND c.is_test = 0 ORDER BY c.last_message_at DESC, c.id DESC LIMIT 6',
            [$tenantId]
        );
        $recentLeads = $db->fetchAll('SELECT l.*, a.name AS agent_name FROM leads l INNER JOIN agents a ON a.id = l.agent_id WHERE l.tenant_id = ? ORDER BY l.id DESC LIMIT 5', [$tenantId]);
        $daily = $db->fetchAll('SELECT day, SUM(conversations) AS conversations, SUM(messages) AS messages FROM analytics_daily WHERE tenant_id = ? AND day >= ? GROUP BY day ORDER BY day ASC', [$tenantId, gmdate('Y-m-d', strtotime('-13 days'))]);
        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-{$i} days"));
            $series[$day] = ['conversations' => 0, 'messages' => 0];
        }
        foreach ($daily as $row) {
            if (isset($series[$row['day']])) {
                $series[$row['day']] = ['conversations' => (int) $row['conversations'], 'messages' => (int) $row['messages']];
            }
        }
        return view('dashboard/index', [
            'title' => 'Dashboard',
            'subtitle' => 'Welcome back, ' . explode(' ', trim((string) current_user()['name']))[0],
            'agents' => $agents,
            'stats' => $stats,
            'recentConversations' => $recentConversations,
            'recentLeads' => $recentLeads,
            'series' => $series,
            'plan' => Plans::forTenant($tenant),
            'aiReady' => LLM::configured(),
        ], 'layouts/app');
    }
}
