<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\Agents;
use App\Services\AI\LLM;
use App\Services\AI\PromptBuilder;
use App\Services\AI\Speech;
use App\Services\Jobs\JobQueue;
use App\Services\Knowledge\Crawler;
use App\Services\Knowledge\Indexer;
use App\Services\Knowledge\KnowledgeImport;
use App\Services\Plans;
use App\Services\Tenants;

final class AgentController
{
    public function index(Request $request): Response
    {
        $tenant = current_tenant();
        return view('agents/index', [
            'title' => 'AI Agents',
            'agents' => Agents::forTenant((int) $tenant['id']),
            'limit' => Plans::limit($tenant, 'agents'),
        ], 'layouts/app');
    }

    public function create(Request $request): Response
    {
        $tenant = current_tenant();
        if (DB::instance()->count('agents', 'tenant_id = ?', [(int) $tenant['id']]) >= Plans::limit($tenant, 'agents')) {
            flash('error', 'You have reached the number of agents included in your plan. Upgrade to add more.');
            return redirect('/billing');
        }
        return view('agents/create', [
            'title' => 'New agent',
            'personas' => Agents::PERSONAS,
            'languages' => App::languages(),
            'limits' => ['documents' => KnowledgeImport::documentLimit($tenant), 'documents_used' => 0],
        ], 'layouts/app');
    }

    public function store(Request $request): Response
    {
        $tenant = current_tenant();
        $tenantId = (int) $tenant['id'];
        if (DB::instance()->count('agents', 'tenant_id = ?', [$tenantId]) >= Plans::limit($tenant, 'agents')) {
            flash('error', 'You have reached the number of agents included in your plan.');
            return redirect('/billing');
        }
        $v = Validator::make($request->all(), [
            'name' => 'required|min:2|max:120', 'business_name' => 'nullable|max:160', 'website_url' => 'nullable|url|max:500',
            'persona' => 'required|in:' . implode(',', array_keys(Agents::PERSONAS)), 'language' => 'required|max:10',
        ]);
        if ($v->fails()) {
            Session::setOldInput($request->all());
            flash('error', $v->firstError() ?? 'Please check the form.');
            return redirect('/agents/new');
        }
        $d = $v->validated();
        $url = null;
        if (!empty($d['website_url'])) {
            $raw = (string) $d['website_url'];
            $url = Crawler::normalize(preg_match('~^https?://~i', $raw) ? $raw : 'https://' . $raw);
        }
        $agent = Agents::create($tenantId, [
            'name' => $d['name'], 'business_name' => $d['business_name'] ?: null, 'website_url' => $url,
            'persona' => $d['persona'], 'language' => array_key_exists($d['language'], App::languages()) ? $d['language'] : 'auto',
            'lead_notify_email' => current_user()['email'],
        ]);
        Tenants::log($tenantId, auth()->id(), 'agent.created', 'agent', (int) $agent['id']);

        // Knowledge base submitted together with the agent
        $parts = [];
        $errors = [];
        if ($url && $request->boolean('scan', true)) {
            try {
                KnowledgeImport::addWebsite($agent, $tenant, $url);
                $parts[] = 'your website';
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
        $import = KnowledgeImport::importFromRequest($agent, $tenant, $request);
        $parts = array_merge($parts, $import['added']);
        $errors = array_merge($errors, $import['errors']);
        foreach ($errors as $error) {
            flash('error', $error);
        }
        if ($parts) {
            flash('success', 'Agent created. The assistant is now learning ' . KnowledgeImport::summary(['added' => $parts]) . '. This runs in the background and usually takes a few minutes.');
        } else {
            flash('success', 'Agent created. Add some knowledge so it can start answering.');
        }
        return redirect('/agents/' . $agent['id'] . '/knowledge');
    }

    public function show(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $db = DB::instance();
        $agentId = (int) $agent['id'];
        $monthStart = gmdate('Y-m-01 00:00:00');
        $stats = [
            'conversations' => $db->count('conversations', 'agent_id = ? AND is_test = 0 AND started_at >= ?', [$agentId, $monthStart]),
            'messages' => (int) $db->fetchColumn('SELECT COALESCE(SUM(messages),0) FROM analytics_daily WHERE agent_id = ? AND day >= ?', [$agentId, gmdate('Y-m-01')]),
            'voice' => (int) $db->fetchColumn('SELECT COALESCE(SUM(voice_messages),0) FROM analytics_daily WHERE agent_id = ? AND day >= ?', [$agentId, gmdate('Y-m-01')]),
            'leads' => $db->count('leads', 'agent_id = ?', [$agentId]),
            'unanswered' => $db->count('unanswered_questions', 'agent_id = ? AND status = \'open\'', [$agentId]),
        ];
        return view('agents/show', [
            'title' => $agent['name'],
            'agent' => $agent,
            'stats' => $stats,
            'knowledge' => Indexer::agentStats($agentId),
            'sources' => $db->fetchAll('SELECT * FROM knowledge_sources WHERE agent_id = ? ORDER BY id DESC LIMIT 5', [$agentId]),
            'jobs' => JobQueue::activeForAgent($agentId),
            'recent' => $db->fetchAll('SELECT * FROM conversations WHERE agent_id = ? AND is_test = 0 ORDER BY id DESC LIMIT 5', [$agentId]),
            'testConversations' => $db->count('conversations', 'agent_id = ? AND is_test = 1', [$agentId]),
        ], 'layouts/app');
    }

    public function edit(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $tenant = current_tenant();
        $plan = Plans::forTenant($tenant);
        $langSettings = Agents::languageSettings($agent);
        $extraLangs = [];
        foreach (Agents::additionalLanguages($agent) as $code) {
            $extraLangs[] = ['code' => $code, 'name' => language_name($code), 'greeting' => $langSettings[$code]['greeting'] ?? '', 'voice' => $langSettings[$code]['voice'] ?? ''];
        }
        return view('agents/edit', [
            'title' => $agent['name'],
            'agent' => $agent,
            'personas' => Agents::PERSONAS,
            'languages' => App::languages(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'modelGroups' => LLM::modelChoices(),
            'providerLabels' => LLM::PROVIDER_LABELS,
            'platformProvider' => LLM::providerName(),
            'voices' => Speech::voiceOptions(),
            'elevenModels' => Speech::ELEVENLABS_MODELS,
            'voiceSettings' => Agents::voiceSettings($agent),
            'extraLangs' => $extraLangs,
            'premiumVoice' => (int) ($plan['limits']['premium_voice'] ?? 0) === 1,
            'hasOpenAI' => Speech::hasOpenAI(),
            'hasElevenLabs' => Speech::hasElevenLabs(),
            'leadFields' => Agents::leadFields($agent),
            'variables' => Agents::TEMPLATE_VARIABLES,
            'responseLengths' => Agents::RESPONSE_LENGTHS,
        ], 'layouts/app');
    }

    public function update(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $v = Validator::make($request->all(), [
            'name' => 'required|min:2|max:120', 'business_name' => 'nullable|max:160', 'description' => 'nullable|max:500', 'website_url' => 'nullable|url|max:500', 'tags' => 'nullable|max:255',
            'persona' => 'required|in:' . implode(',', array_keys(Agents::PERSONAS)), 'instructions' => 'nullable|max:6000',
            'prompt_mode' => 'required|in:guided,custom', 'system_prompt' => 'nullable|max:12000', 'timezone' => 'nullable|max:60',
            'response_length' => 'required|in:short,medium,long', 'voice_response_length' => 'required|in:short,medium,long',
            'language' => 'required|max:10', 'llm_choice' => 'nullable|max:120', 'effort' => 'required|in:low,medium,high',
            'tts_provider' => 'required|in:auto,openai,elevenlabs,browser', 'tts_voice' => 'nullable|max:80', 'tts_speed' => 'required|numeric|between:0.5,2',
            'elevenlabs_model' => 'nullable|max:40', 'stability' => 'nullable|numeric|between:0,1', 'similarity' => 'nullable|numeric|between:0,1', 'style' => 'nullable|numeric|between:0,1', 'openai_instructions' => 'nullable|max:400',
            'stt_provider' => 'required|in:auto,browser', 'greeting_message' => 'nullable|max:1000', 'fallback_message' => 'nullable|max:1000',
            'lead_instructions' => 'nullable|max:2000', 'lead_notify_email' => 'nullable|email', 'allowed_domains' => 'nullable|max:2000',
        ]);
        if ($v->fails()) {
            Session::setOldInput($request->all());
            flash('error', $v->firstError() ?? 'Please check the form.');
            return redirect('/agents/' . $agent['id'] . '/settings');
        }
        $d = $v->validated();
        $languages = App::languages();
        $default = array_key_exists($d['language'], $languages) ? $d['language'] : 'auto';

        // Additional languages with optional greeting / voice overrides
        $extra = [];
        $langSettings = [];
        foreach ($request->array('additional_languages') as $code) {
            $code = strtolower(trim((string) $code));
            if ($code === 'auto' || $code === $default || !isset($languages[$code]) || in_array($code, $extra, true)) {
                continue;
            }
            $extra[] = $code;
            $greetings = $request->array('lang_greeting');
            $voicesIn = $request->array('lang_voice');
            $greeting = mb_substr(trim((string) ($greetings[$code] ?? '')), 0, 1000);
            $voice = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($voicesIn[$code] ?? '')) ?? '';
            if ($greeting !== '' || $voice !== '') {
                $langSettings[$code] = ['greeting' => $greeting, 'voice' => mb_substr($voice, 0, 80)];
            }
        }

        // Lead fields
        $fields = array_values(array_intersect(['name', 'email', 'phone', 'message'], $request->array('lead_fields')));
        if (!in_array('name', $fields, true)) {
            array_unshift($fields, 'name');
        }

        $url = null;
        if (!empty($d['website_url'])) {
            $raw = (string) $d['website_url'];
            $url = Crawler::normalize(preg_match('~^https?://~i', $raw) ? $raw : 'https://' . $raw);
        }

        // Model choice: "" (platform default) or "provider|model"
        $llmProvider = null;
        $llmModel = null;
        $choice = trim((string) ($d['llm_choice'] ?? ''));
        if ($choice !== '' && str_contains($choice, '|')) {
            [$p, $m] = explode('|', $choice, 2);
            $groups = LLM::modelChoices();
            if (isset($groups[$p][$m])) {
                $llmProvider = $p;
                $llmModel = $m;
            }
        }

        $timezone = (string) ($d['timezone'] ?? '');
        $timezone = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : null;

        $voiceSettings = [
            'elevenlabs_model' => isset(Speech::ELEVENLABS_MODELS[(string) ($d['elevenlabs_model'] ?? '')]) ? (string) $d['elevenlabs_model'] : '',
            'stability' => round(max(0.0, min(1.0, (float) ($d['stability'] ?? 0.5))), 2),
            'similarity' => round(max(0.0, min(1.0, (float) ($d['similarity'] ?? 0.75))), 2),
            'style' => round(max(0.0, min(1.0, (float) ($d['style'] ?? 0))), 2),
            'openai_instructions' => trim((string) ($d['openai_instructions'] ?? '')),
        ];

        $tags = implode(', ', array_slice(array_values(array_unique(array_filter(array_map(static fn($t) => mb_substr(trim($t), 0, 30), explode(',', (string) ($d['tags'] ?? '')))))), 0, 10));

        $widget = Agents::widgetConfig($agent);
        if (($widget['header_title'] ?? '') === $agent['name']) {
            $widget['header_title'] = $d['name'];
        }
        Agents::update((int) $agent['id'], [
            'name' => $d['name'], 'business_name' => $d['business_name'] ?: null, 'description' => $d['description'] ?: null, 'website_url' => $url, 'tags' => $tags ?: null,
            'persona' => $d['persona'], 'instructions' => $d['instructions'] ?: null,
            'prompt_mode' => $d['prompt_mode'], 'system_prompt' => $d['system_prompt'] ?: null, 'timezone' => $timezone,
            'response_length' => $d['response_length'], 'voice_response_length' => $d['voice_response_length'],
            'language' => $default, 'additional_languages' => $extra ? implode(',', $extra) : null, 'language_settings' => $langSettings ? json_encode($langSettings, JSON_UNESCAPED_UNICODE) : null,
            'llm_provider' => $llmProvider, 'llm_model' => $llmModel, 'effort' => $d['effort'],
            'voice_enabled' => $request->boolean('voice_enabled') ? 1 : 0, 'tts_provider' => $d['tts_provider'], 'tts_voice' => $d['tts_voice'] ?: 'alloy',
            'tts_speed' => round((float) $d['tts_speed'], 2), 'voice_settings' => json_encode($voiceSettings), 'stt_provider' => $d['stt_provider'],
            'auto_speak' => $request->boolean('auto_speak') ? 1 : 0, 'interruptible' => $request->boolean('interruptible') ? 1 : 0,
            'greeting_message' => $d['greeting_message'] ?: null, 'fallback_message' => $d['fallback_message'] ?: null,
            'lead_capture_enabled' => $request->boolean('lead_capture_enabled') ? 1 : 0, 'lead_fields' => implode(',', $fields),
            'lead_instructions' => $d['lead_instructions'] ?: null, 'lead_notify_email' => $d['lead_notify_email'] ?: null,
            'allowed_domains' => $d['allowed_domains'] ?: null,
            'widget_config' => json_encode($widget, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        flash('success', 'Agent settings saved.');
        return redirect('/agents/' . $agent['id'] . '/settings' . ($request->string('tab') ? '#' . preg_replace('/[^a-z]/', '', $request->string('tab')) : ''));
    }

    /** Build the final system prompt from the (unsaved) form values for the preview modal. */
    public function promptPreview(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $overrides = $request->only(['name', 'business_name', 'website_url', 'persona', 'instructions', 'prompt_mode', 'system_prompt', 'language', 'timezone', 'response_length', 'voice_response_length', 'lead_instructions']);
        foreach ($overrides as $k => $v) {
            $agent[$k] = is_string($v) ? $v : $agent[$k];
        }
        $agent['lead_capture_enabled'] = $request->has('lead_capture_enabled') ? ($request->boolean('lead_capture_enabled') ? 1 : 0) : (int) $agent['lead_capture_enabled'];
        $extra = array_values(array_filter(array_map(static fn($c) => strtolower(trim((string) $c)), $request->array('additional_languages'))));
        if ($request->has('additional_languages')) {
            $agent['additional_languages'] = implode(',', $extra);
        }
        $modality = $request->string('modality') === 'voice' ? 'voice' : 'text';
        return Response::json(['prompt' => PromptBuilder::preview($agent, $modality)]);
    }

    /** Draft a system prompt with the AI provider. */
    public function generatePrompt(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        foreach (['name', 'business_name', 'website_url', 'persona'] as $k) {
            $value = $request->string($k);
            if ($value !== '') {
                $agent[$k] = $value;
            }
        }
        if (!LLM::configured()) {
            return Response::json(['error' => 'No AI provider is configured yet.'], 503);
        }
        try {
            $prompt = PromptBuilder::generate($agent, mb_substr($request->string('brief'), 0, 1000));
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Could not generate a prompt: ' . $e->getMessage()], 502);
        }
        return Response::json(['prompt' => $prompt]);
    }

    /** Play a short sample with the selected voice and style settings. */
    public function voicePreview(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $provider = $request->string('provider');
        if ($provider === 'auto') {
            $provider = Speech::ttsMode(['tts_provider' => 'auto'], true);
        }
        if (!in_array($provider, ['openai', 'elevenlabs'], true) || ($provider === 'openai' && !Speech::hasOpenAI()) || ($provider === 'elevenlabs' && !Speech::hasElevenLabs())) {
            return Response::json(['error' => 'This voice engine is not configured. Browser voices can only be previewed on your website.'], 422);
        }
        $voice = preg_replace('/[^A-Za-z0-9_-]/', '', $request->string('voice')) ?: 'alloy';
        $settings = [
            'elevenlabs_model' => $request->string('elevenlabs_model'),
            'stability' => $request->float('stability', 0.5),
            'similarity' => $request->float('similarity', 0.75),
            'style' => $request->float('style', 0.0),
            'openai_instructions' => mb_substr($request->string('openai_instructions'), 0, 400),
        ];
        $sample = $request->string('text') ?: ('Hi there! I am ' . $agent['name'] . '. How can I help you today?');
        try {
            $audio = Speech::synthesize(mb_substr($sample, 0, 300), $provider, $voice, $request->float('speed', 1.0), $settings);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 502);
        }
        return new Response($audio['audio'], 200, ['Content-Type' => $audio['mime'], 'Content-Length' => (string) strlen($audio['audio']), 'Cache-Control' => 'no-store']);
    }

    public function toggle(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        $status = $agent['status'] === 'active' ? 'paused' : 'active';
        Agents::update((int) $agent['id'], ['status' => $status]);
        flash('success', $status === 'active' ? 'Agent enabled. It is live on your website.' : 'Agent paused. The widget will not appear on your website.');
        if ($request->wantsJson()) {
            return Response::json(['status' => $status]);
        }
        return back();
    }

    public function destroy(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        Agents::delete($agent);
        Tenants::log(tenant_id(), auth()->id(), 'agent.deleted', 'agent', (int) $agent['id'], ['name' => $agent['name']]);
        flash('success', 'Agent "' . $agent['name'] . '" was deleted.');
        return redirect('/agents');
    }

    public function duplicate(Request $request, string $id): Response
    {
        $tenant = current_tenant();
        $agent = Agents::findOrFail((int) $id, tenant_id());
        if (DB::instance()->count('agents', 'tenant_id = ?', [(int) $tenant['id']]) >= Plans::limit($tenant, 'agents')) {
            flash('error', 'You have reached the number of agents included in your plan.');
            return redirect('/billing');
        }
        $newId = Agents::duplicate($agent);
        flash('success', 'Agent duplicated (knowledge is not copied). The copy is paused until you enable it.');
        return redirect('/agents/' . $newId);
    }

    public function test(Request $request, string $id): Response
    {
        $agent = Agents::findOrFail((int) $id, tenant_id());
        return view('agents/test', [
            'title' => 'Test ' . $agent['name'],
            'agent' => $agent,
            'knowledge' => Indexer::agentStats((int) $agent['id']),
            'aiReady' => LLM::configured(),
            'onboarding' => $request->boolean('onboarding'),
        ], 'layouts/app');
    }
}
