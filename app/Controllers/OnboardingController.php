<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Core\Validator;
use App\Services\Agents;
use App\Services\AI\Speech;
use App\Services\Jobs\JobQueue;
use App\Services\Knowledge\Crawler;
use App\Services\Plans;
use App\Services\Settings;
use App\Services\Tenants;

/**
 * Guided first-run wizard: Website -> Agent -> Customize -> Test -> Install.
 */
final class OnboardingController
{
    private function firstAgent(int $tenantId): ?array
    {
        return DB::instance()->fetch('SELECT * FROM agents WHERE tenant_id = ? ORDER BY id ASC LIMIT 1', [$tenantId]);
    }

    public function index(Request $request): Response
    {
        $tenant = current_tenant();
        $agent = $this->firstAgent((int) $tenant['id']);
        $step = max(1, min(5, $request->int('step', $agent ? 2 : 1)));
        if ($step >= 3 && $agent) {
            $paths = [3 => '/customize', 4 => '/test', 5 => '/install'];
            return redirect('/agents/' . $agent['id'] . $paths[$step] . '?onboarding=1');
        }
        if ($step === 2 && !$agent) {
            $step = 1;
        }
        $job = null;
        if ($agent) {
            $active = JobQueue::activeForAgent((int) $agent['id']);
            $job = $active ? JobQueue::toArray($active[0]) : null;
            if (!$job) {
                $recent = JobQueue::recentForAgent((int) $agent['id'], 1);
                $job = $recent ? JobQueue::toArray($recent[0]) : null;
            }
        }
        return view('onboarding/index', [
            'title' => 'Set up your assistant',
            'step' => $step,
            'agent' => $agent,
            'job' => $job,
            'website' => $agent['website_url'] ?? ($tenant['website_url'] ?? ''),
            'personas' => Agents::PERSONAS,
            'languages' => \App\Core\App::languages(),
            'voices' => Speech::voiceOptions(),
            'ttsDefault' => Speech::ttsMode($agent ?? ['tts_provider' => 'auto'], true),
        ], 'layouts/app');
    }

    /** Step 1: website URL -> create the first agent and start scanning. */
    public function website(Request $request): Response
    {
        $tenant = current_tenant();
        $tenantId = (int) $tenant['id'];
        $url = $request->string('website');
        if ($url !== '' && !preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $normalized = $url !== '' ? Crawler::normalize($url) : null;
        if ($url !== '' && $normalized === null) {
            flash('error', 'Please enter a valid website address, for example https://www.example.com');
            return redirect('/onboarding?step=1');
        }
        $agent = $this->firstAgent($tenantId);
        $business = $normalized ? ucfirst(explode('.', Str::host($normalized))[0]) : $tenant['name'];
        if (!$agent) {
            $agent = Agents::create($tenantId, ['name' => $business . ' Assistant', 'business_name' => $business, 'website_url' => $normalized, 'lead_notify_email' => current_user()['email']]);
            Tenants::log($tenantId, auth()->id(), 'agent.created', 'agent', (int) $agent['id']);
        } elseif ($normalized) {
            Agents::update((int) $agent['id'], ['website_url' => $normalized, 'business_name' => $agent['business_name'] ?: $business]);
        }
        DB::instance()->update('tenants', ['website_url' => $normalized, 'updated_at' => now()], 'id = :id', ['id' => $tenantId]);
        if ($normalized) {
            $this->startScan($agent, $normalized, $tenant);
        }
        return redirect('/onboarding?step=2');
    }

    private function startScan(array $agent, string $url, array $tenant): void
    {
        $db = DB::instance();
        $existing = $db->fetch('SELECT * FROM knowledge_sources WHERE agent_id = ? AND type = \'website\' LIMIT 1', [(int) $agent['id']]);
        $maxPages = min(Plans::limit($tenant, 'pages_per_agent'), Settings::int('crawler_max_pages_default', 100));
        $now = now();
        if ($existing) {
            $db->update('knowledge_sources', ['url' => $url, 'status' => 'pending', 'settings' => json_encode(['max_pages' => $maxPages]), 'updated_at' => $now], 'id = :id', ['id' => $existing['id']]);
            $sourceId = (int) $existing['id'];
        } else {
            $sourceId = $db->insert('knowledge_sources', [
                'tenant_id' => (int) $agent['tenant_id'], 'agent_id' => (int) $agent['id'], 'type' => 'website',
                'title' => Str::host($url), 'url' => $url, 'status' => 'pending',
                'settings' => json_encode(['max_pages' => $maxPages]), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if (!JobQueue::activeForAgent((int) $agent['id'])) {
            JobQueue::push((int) $agent['tenant_id'], (int) $agent['id'], 'crawl_website', ['source_id' => $sourceId, 'max_pages' => $maxPages]);
        }
    }

    /** Step 2: name, personality, language and voice. */
    public function agent(Request $request): Response
    {
        $tenant = current_tenant();
        $agent = $this->firstAgent((int) $tenant['id']);
        if (!$agent) {
            return redirect('/onboarding?step=1');
        }
        $v = Validator::make($request->all(), [
            'name' => 'required|min:2|max:120', 'business_name' => 'nullable|max:160', 'persona' => 'required|in:' . implode(',', array_keys(Agents::PERSONAS)),
            'language' => 'required|max:10', 'tts_provider' => 'required|in:auto,openai,elevenlabs,browser', 'tts_voice' => 'nullable|max:80', 'greeting_message' => 'nullable|max:1000',
        ]);
        if ($v->fails()) {
            flash('error', $v->firstError() ?? 'Please check the form.');
            return redirect('/onboarding?step=2');
        }
        $d = $v->validated();
        $widget = Agents::widgetConfig($agent);
        $widget['header_title'] = $d['name'];
        Agents::update((int) $agent['id'], [
            'name' => $d['name'], 'business_name' => $d['business_name'] ?: null, 'persona' => $d['persona'],
            'language' => array_key_exists($d['language'], \App\Core\App::languages()) ? $d['language'] : 'auto',
            'tts_provider' => $d['tts_provider'], 'tts_voice' => $d['tts_voice'] ?: 'alloy',
            'voice_enabled' => $request->boolean('voice_enabled', true) ? 1 : 0,
            'greeting_message' => $d['greeting_message'] ?: Agents::defaultGreeting((string) ($d['business_name'] ?: '')),
            'widget_config' => json_encode($widget),
        ]);
        return redirect('/agents/' . $agent['id'] . '/customize?onboarding=1');
    }

    public function skip(Request $request): Response
    {
        DB::instance()->update('tenants', ['onboarding_completed' => 1, 'updated_at' => now()], 'id = :id', ['id' => tenant_id()]);
        return redirect('/dashboard');
    }

    public function complete(Request $request): Response
    {
        DB::instance()->update('tenants', ['onboarding_completed' => 1, 'updated_at' => now()], 'id = :id', ['id' => tenant_id()]);
        flash('success', 'Setup complete! Your assistant is live once the embed code is on your website.');
        return redirect('/dashboard');
    }
}
