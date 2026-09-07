<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AI\AnthropicProvider;
use App\Services\AI\Embeddings;
use App\Services\AI\LLM;
use App\Services\AI\OpenAIProvider;
use App\Services\Jobs\JobQueue;
use App\Services\Jobs\JobRunner;
use App\Services\Migrator;
use App\Services\Plans;
use App\Services\Settings;
use App\Services\Tenants;
use App\Services\Usage;

/**
 * Platform administration (super admins only).
 */
final class AdminController
{
    public function index(Request $request): Response
    {
        $db = DB::instance();
        $monthStart = gmdate('Y-m-01 00:00:00');
        $stats = [
            'tenants' => $db->count('tenants'),
            'tenants_month' => $db->count('tenants', 'created_at >= ?', [$monthStart]),
            'agents' => $db->count('agents'),
            'conversations_month' => $db->count('conversations', 'is_test = 0 AND started_at >= ?', [$monthStart]),
            'messages_month' => (int) $db->fetchColumn('SELECT COALESCE(SUM(value),0) FROM usage_records WHERE period = ? AND metric = \'messages\'', [Usage::period()]),
            'leads_month' => $db->count('leads', 'created_at >= ?', [$monthStart]),
            'jobs_queued' => $db->count('jobs', 'status IN (\'queued\',\'running\')'),
            'jobs_failed' => $db->count('jobs', 'status = \'failed\' AND created_at >= ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]),
            'paid' => $db->count('tenants', 'plan_key <> \'free\''),
        ];
        return view('admin/index', [
            'title' => 'Admin',
            'stats' => $stats,
            'recentTenants' => $db->fetchAll('SELECT t.*, (SELECT email FROM users u WHERE u.tenant_id = t.id ORDER BY u.id ASC LIMIT 1) AS email FROM tenants t ORDER BY t.id DESC LIMIT 8'),
            'workerAlive' => JobQueue::workerAlive(),
            'workerLast' => (string) Settings::get('worker_heartbeat', ''),
            'llmConfigured' => LLM::configured(),
            'llmProvider' => LLM::providerName(),
            'embeddings' => Embeddings::provider(),
            'pending' => Migrator::pending($db),
            'diskUsed' => (int) $db->fetchColumn('SELECT COALESCE(SUM(file_size),0) FROM knowledge_sources'),
        ], 'layouts/app');
    }

    public function tenants(Request $request): Response
    {
        $db = DB::instance();
        $q = $request->string('q');
        $where = '1=1';
        $params = [];
        if ($q !== '') {
            $where = '(t.name LIKE ? OR t.slug LIKE ? OR EXISTS (SELECT 1 FROM users u WHERE u.tenant_id = t.id AND u.email LIKE ?))';
            $params = ["%{$q}%", "%{$q}%", "%{$q}%"];
        }
        $page = max(1, $request->int('page', 1));
        $perPage = 30;
        $total = (int) $db->fetchColumn('SELECT COUNT(*) FROM tenants t WHERE ' . $where, $params);
        $rows = $db->fetchAll(
            'SELECT t.*, (SELECT email FROM users u WHERE u.tenant_id = t.id ORDER BY u.id ASC LIMIT 1) AS email,
             (SELECT COUNT(*) FROM agents a WHERE a.tenant_id = t.id) AS agents,
             (SELECT COALESCE(SUM(value),0) FROM usage_records r WHERE r.tenant_id = t.id AND r.period = ? AND r.metric = \'messages\') AS messages
             FROM tenants t WHERE ' . $where . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            array_merge([Usage::period()], $params)
        );
        return view('admin/tenants', ['title' => 'Workspaces', 'tenants' => $rows, 'q' => $q, 'page' => $page, 'pages' => (int) ceil($total / $perPage), 'total' => $total], 'layouts/app');
    }

    private function tenantOrFail(string $id): array
    {
        $tenant = DB::instance()->fetch('SELECT * FROM tenants WHERE id = ?', [(int) $id]);
        if (!$tenant) {
            abort(404);
        }
        return $tenant;
    }

    public function tenant(Request $request, string $id): Response
    {
        $tenant = $this->tenantOrFail($id);
        $db = DB::instance();
        return view('admin/tenant', [
            'title' => $tenant['name'],
            'tenant' => $tenant,
            'users' => $db->fetchAll('SELECT * FROM users WHERE tenant_id = ? ORDER BY id', [(int) $tenant['id']]),
            'agents' => $db->fetchAll('SELECT * FROM agents WHERE tenant_id = ? ORDER BY id', [(int) $tenant['id']]),
            'usage' => Usage::summary((int) $tenant['id']),
            'plans' => Plans::all(),
            'activity' => $db->fetchAll('SELECT * FROM activity_logs WHERE tenant_id = ? ORDER BY id DESC LIMIT 20', [(int) $tenant['id']]),
        ], 'layouts/app');
    }

    public function updateTenant(Request $request, string $id): Response
    {
        $tenant = $this->tenantOrFail($id);
        $data = ['updated_at' => now()];
        $plan = $request->string('plan_key');
        if (Plans::get($plan)) {
            $data['plan_key'] = $plan;
        }
        $status = $request->string('status');
        if (in_array($status, ['active', 'suspended'], true)) {
            $data['status'] = $status;
        }
        $trial = $request->string('trial_ends_at');
        $data['trial_ends_at'] = $trial !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $trial) ? gmdate('Y-m-d 23:59:59', strtotime($trial)) : null;
        DB::instance()->update('tenants', $data, 'id = :id', ['id' => (int) $tenant['id']]);
        Tenants::log((int) $tenant['id'], auth()->id(), 'admin.tenant_updated', 'tenant', (int) $tenant['id'], $data);
        flash('success', 'Workspace updated.');
        return redirect('/admin/tenants/' . $tenant['id']);
    }

    public function impersonate(Request $request, string $id): Response
    {
        $tenant = $this->tenantOrFail($id);
        $owner = DB::instance()->fetch('SELECT * FROM users WHERE tenant_id = ? ORDER BY (role = \'owner\') DESC, id ASC LIMIT 1', [(int) $tenant['id']]);
        if (!$owner) {
            flash('error', 'This workspace has no users.');
            return redirect('/admin/tenants/' . $tenant['id']);
        }
        Tenants::log((int) $tenant['id'], auth()->id(), 'admin.impersonate', 'user', (int) $owner['id']);
        auth()->impersonate((int) $owner['id']);
        return redirect('/dashboard');
    }

    public function stopImpersonating(Request $request): Response
    {
        auth()->stopImpersonating();
        return redirect('/admin/tenants');
    }

    public function deleteTenant(Request $request, string $id): Response
    {
        $tenant = $this->tenantOrFail($id);
        if ((int) $tenant['id'] === tenant_id()) {
            flash('error', 'You cannot delete your own workspace from here.');
            return redirect('/admin/tenants/' . $tenant['id']);
        }
        Tenants::delete((int) $tenant['id']);
        flash('success', 'Workspace deleted.');
        return redirect('/admin/tenants');
    }

    public function plans(Request $request): Response
    {
        return view('admin/plans', ['title' => 'Plans', 'plans' => Plans::all(), 'limitKeys' => array_keys(Plans::DEFAULT_LIMITS)], 'layouts/app');
    }

    public function updatePlan(Request $request, string $id): Response
    {
        $plan = DB::instance()->fetch('SELECT * FROM plans WHERE id = ?', [(int) $id]);
        if (!$plan) {
            abort(404);
        }
        $limits = [];
        foreach (Plans::DEFAULT_LIMITS as $key => $default) {
            $limits[$key] = (int) $request->input('limit_' . $key, $default);
        }
        $features = array_values(array_filter(array_map('trim', explode("\n", (string) $request->input('features', '')))));
        DB::instance()->update('plans', [
            'name' => mb_substr($request->string('name') ?: $plan['name'], 0, 80),
            'description' => mb_substr($request->string('description'), 0, 255),
            'price_monthly' => round($request->float('price_monthly'), 2),
            'price_yearly' => round($request->float('price_yearly'), 2),
            'stripe_price_monthly' => $request->string('stripe_price_monthly') ?: null,
            'stripe_price_yearly' => $request->string('stripe_price_yearly') ?: null,
            'limits' => json_encode($limits),
            'features' => json_encode($features),
            'is_active' => $request->boolean('is_active') ? 1 : 0,
            'is_featured' => $request->boolean('is_featured') ? 1 : 0,
            'sort_order' => $request->int('sort_order'),
            'updated_at' => now(),
        ], 'id = :id', ['id' => (int) $plan['id']]);
        Plans::clearCache();
        flash('success', 'Plan "' . $plan['name'] . '" updated.');
        return redirect('/admin/plans');
    }

    private const SETTING_KEYS = [
        'general' => ['app_name', 'support_email', 'registration_enabled', 'default_plan', 'trial_plan', 'trial_days', 'widget_branding_text', 'demo_agent_public_id', 'lead_notifications', 'email_verification_notice'],
        'ai' => ['llm_provider', 'anthropic_api_key', 'anthropic_model', 'anthropic_fallbacks', 'openai_api_key', 'openai_model', 'compatible_base_url', 'compatible_api_key', 'compatible_model', 'llm_effort', 'llm_max_tokens',
            'embeddings_provider', 'openai_embedding_model', 'voyage_api_key', 'voyage_embedding_model', 'embedding_dimensions', 'retrieval_top_k', 'retrieval_min_score', 'chunk_size', 'chunk_overlap'],
        'voice' => ['stt_provider', 'openai_stt_model', 'tts_provider', 'openai_tts_model', 'elevenlabs_api_key', 'elevenlabs_model', 'fishaudio_api_key', 'fishaudio_model'],
        'documents' => ['pdf_extraction', 'crawler_max_pages_default', 'crawler_timeout', 'crawler_user_agent'],
        'billing' => ['stripe_enabled', 'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret'],
        'mail' => ['mail_driver', 'mail_from_email', 'mail_from_name', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'],
    ];

    public function settings(Request $request): Response
    {
        $tab = $request->string('tab') ?: 'ai';
        if (!isset(self::SETTING_KEYS[$tab])) {
            $tab = 'ai';
        }
        return view('admin/settings', [
            'title' => 'Platform settings',
            'tab' => $tab,
            'values' => Settings::all(),
            'plans' => Plans::all(),
            'anthropicModels' => LLM::ANTHROPIC_MODELS,
            'webhookUrl' => url('/webhooks/stripe'),
            'cronUrl' => url('/webcron/run', ['token' => (string) \App\Core\App::config('app.cron_token', '')]),
            'workerAlive' => JobQueue::workerAlive(),
        ], 'layouts/app');
    }

    public function saveSettings(Request $request): Response
    {
        $tab = $request->string('tab');
        $keys = self::SETTING_KEYS[$tab] ?? [];
        if (!$keys) {
            abort(400, 'Unknown settings tab.');
        }
        $updates = [];
        foreach ($keys as $key) {
            if (Settings::isSecret($key)) {
                if ($request->boolean('clear_' . $key)) {
                    $updates[$key] = '';
                    continue;
                }
                $value = trim((string) $request->input($key, ''));
                if ($value !== '') {
                    $updates[$key] = $value;
                }
                continue;
            }
            if ($request->has($key)) {
                $value = $request->input($key);
                $updates[$key] = is_array($value) ? '' : trim((string) $value);
            } elseif (in_array($key, ['registration_enabled', 'anthropic_fallbacks', 'stripe_enabled', 'lead_notifications', 'email_verification_notice'], true)) {
                $updates[$key] = '0';
            }
        }
        // Guard numeric ranges
        foreach (['embedding_dimensions' => [64, 3072], 'retrieval_top_k' => [1, 20], 'chunk_size' => [400, 4000], 'chunk_overlap' => [0, 1000], 'llm_max_tokens' => [128, 8192], 'crawler_max_pages_default' => [1, 5000], 'crawler_timeout' => [5, 60], 'trial_days' => [0, 365], 'smtp_port' => [1, 65535]] as $k => [$min, $max]) {
            if (isset($updates[$k]) && $updates[$k] !== '') {
                $updates[$k] = (string) max($min, min($max, (int) $updates[$k]));
            }
        }
        Settings::setMany($updates);
        Settings::clearCache();
        flash('success', 'Settings saved.');
        return redirect('/admin/settings?tab=' . $tab);
    }

    /** Live test of a provider connection. */
    public function testProvider(Request $request): Response
    {
        $which = $request->string('provider');
        try {
            switch ($which) {
                case 'anthropic':
                    $key = (string) Settings::get('anthropic_api_key', '');
                    if ($key === '') {
                        return Response::json(['ok' => false, 'message' => 'No Anthropic API key saved yet.']);
                    }
                    return Response::json((new AnthropicProvider($key, (string) Settings::get('anthropic_model', 'claude-opus-5'), Settings::bool('anthropic_fallbacks')))->test());
                case 'openai':
                    $key = (string) Settings::get('openai_api_key', '');
                    if ($key === '') {
                        return Response::json(['ok' => false, 'message' => 'No OpenAI API key saved yet.']);
                    }
                    return Response::json((new OpenAIProvider($key, (string) Settings::get('openai_model', 'gpt-4.1-mini')))->test());
                case 'compatible':
                    return Response::json(LLM::provider('compatible')->test());
                case 'embeddings':
                    if (!Embeddings::available()) {
                        return Response::json(['ok' => false, 'message' => 'No embedding provider is configured.']);
                    }
                    $v = Embeddings::embed(['Hello world'], 'query');
                    return Response::json(['ok' => true, 'message' => 'Embeddings OK: ' . Embeddings::provider() . ' / ' . Embeddings::model() . ' (' . count($v[0]) . ' dimensions).']);
                case 'elevenlabs':
                    $audio = \App\Services\AI\Speech::synthesize('Hello from VoiceAgent. Test ' . time(), 'elevenlabs', '21m00Tcm4TlvDq8ikWAM', 1.0);
                    return Response::json(['ok' => true, 'message' => 'ElevenLabs OK (' . human_filesize(strlen($audio['audio'])) . ' of audio generated).']);
                case 'openai_tts':
                    $audio = \App\Services\AI\Speech::synthesize('Hello from VoiceAgent. Test ' . time(), 'openai', 'alloy', 1.0);
                    return Response::json(['ok' => true, 'message' => 'OpenAI speech OK (' . human_filesize(strlen($audio['audio'])) . ' of audio generated).']);
                case 'fishaudio':
                    $audio = \App\Services\AI\Speech::synthesize('Hello from VoiceAgent. Test ' . time(), 'fishaudio', '', 1.0);
                    return Response::json(['ok' => true, 'message' => 'Fish Audio OK (' . human_filesize(strlen($audio['audio'])) . ' of audio generated).']);
                case 'mail':
                    $ok = \App\Core\Mailer::send((string) current_user()['email'], 'Test email from ' . app_name(), '<p>This is a test email. Your mail settings work.</p>');
                    return Response::json(['ok' => $ok, 'message' => $ok ? 'Test email sent to ' . current_user()['email'] . '.' : 'Sending failed. Check storage/logs for details.']);
                default:
                    return Response::json(['ok' => false, 'message' => 'Unknown provider.'], 400);
            }
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function jobs(Request $request): Response
    {
        $db = DB::instance();
        $status = $request->string('status');
        $where = in_array($status, ['queued', 'running', 'completed', 'failed', 'cancelled'], true) ? 'j.status = ?' : '1=1';
        $params = $where === '1=1' ? [] : [$status];
        $jobs = $db->fetchAll('SELECT j.*, t.name AS tenant_name, a.name AS agent_name FROM jobs j LEFT JOIN tenants t ON t.id = j.tenant_id LEFT JOIN agents a ON a.id = j.agent_id WHERE ' . $where . ' ORDER BY j.id DESC LIMIT 100', $params);
        return view('admin/jobs', ['title' => 'Background jobs', 'jobs' => $jobs, 'status' => $status, 'workerAlive' => JobQueue::workerAlive(), 'counts' => $db->fetchPairs('SELECT status, COUNT(*) FROM jobs GROUP BY status')], 'layouts/app');
    }

    public function retryJob(Request $request, string $id): Response
    {
        DB::instance()->update('jobs', ['status' => 'queued', 'attempts' => 0, 'run_after' => now(), 'locked_at' => null, 'locked_by' => null, 'last_error' => null, 'finished_at' => null, 'progress_text' => 'Retry requested', 'updated_at' => now()], 'id = :id', ['id' => (int) $id]);
        flash('success', 'Job queued again.');
        return redirect('/admin/jobs');
    }

    public function runJobs(Request $request): Response
    {
        @set_time_limit(90);
        $runs = JobRunner::work(25, 'admin-' . auth()->id());
        flash('success', 'Worker ran ' . $runs . ' job slice(s).');
        return redirect('/admin/jobs');
    }

    public function logs(Request $request): Response
    {
        $files = glob(APP_ROOT . '/storage/logs/app-*.log') ?: [];
        rsort($files);
        $file = $request->string('file');
        $selected = $file !== '' && in_array(APP_ROOT . '/storage/logs/' . basename($file), $files, true) ? APP_ROOT . '/storage/logs/' . basename($file) : ($files[0] ?? null);
        $lines = [];
        if ($selected && is_file($selected)) {
            $content = (string) file_get_contents($selected);
            $lines = array_slice(explode("\n", trim($content)), -300);
        }
        return view('admin/logs', ['title' => 'Logs', 'files' => array_map('basename', $files), 'selected' => $selected ? basename($selected) : '', 'lines' => array_reverse($lines)], 'layouts/app');
    }

    public function migrate(Request $request): Response
    {
        $applied = Migrator::run(DB::instance());
        flash('success', $applied ? 'Applied: ' . implode(', ', $applied) : 'Database is up to date.');
        return redirect('/admin');
    }
}
