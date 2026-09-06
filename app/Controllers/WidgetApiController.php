<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Services\Agents;
use App\Services\AI\LLM;
use App\Services\AI\Speech;
use App\Services\Chat\ChatEngine;
use App\Services\Chat\Conversations;
use App\Services\Chat\Leads;
use App\Services\Plans;
use App\Services\Usage;

/**
 * Public API consumed by the embeddable widget. Stateless; CORS enabled by the router.
 */
final class WidgetApiController
{
    private function agent(Request $request, bool $allowPaused = false): array
    {
        $publicId = (string) ($request->input('agent') ?? $request->query['agent'] ?? '');
        $agent = Agents::findByPublicId($publicId);
        if (!$agent) {
            throw new HttpException(404, 'Unknown agent.');
        }
        $tenant = DB::instance()->fetch('SELECT * FROM tenants WHERE id = ?', [(int) $agent['tenant_id']]);
        if (!$tenant || $tenant['status'] !== 'active') {
            throw new HttpException(403, 'This assistant is currently unavailable.');
        }
        $preview = $this->isPreview($request, $agent);
        if ($agent['status'] !== 'active' && !$allowPaused && !$preview) {
            throw new HttpException(403, 'This assistant is currently paused.');
        }
        if (!$preview && !Agents::domainAllowed($agent, $request->origin() ?: $request->referer())) {
            throw new HttpException(403, 'This assistant is not enabled for this website.');
        }
        $agent['_tenant'] = $tenant;
        $agent['_preview'] = $preview;
        return $agent;
    }

    /** Dashboard preview/test requests carry a signed token (issued only to the agent owner). */
    private function isPreview(Request $request, array $agent): bool
    {
        $token = (string) ($request->input('preview_token') ?? $request->header('X-Preview-Token') ?? '');
        if ($token === '') {
            return false;
        }
        $payload = Crypto::verify($token);
        return $payload && ($payload['preview'] ?? '') === $agent['public_id'];
    }

    private function conversation(Request $request, array $agent): array
    {
        $token = (string) ($request->input('token') ?? $request->header('X-Widget-Token') ?? '');
        $conversation = Conversations::fromToken($token, $agent);
        if (!$conversation) {
            throw new HttpException(401, 'Conversation expired. Please start a new conversation.');
        }
        return $conversation;
    }

    public function config(Request $request): Response
    {
        $agent = $this->agent($request, true);
        $config = Agents::publicConfig($agent, $agent['_tenant']);
        $config['preview'] = $agent['_preview'];
        $config['ready'] = LLM::configured();
        $this->trackInstall($request, $agent);
        return Response::json($config, 200, ['Cache-Control' => 'no-store']);
    }

    /** Remember the external website the widget was loaded from (for the setup checklist). */
    private function trackInstall(Request $request, array $agent): void
    {
        if ($agent['_preview']) {
            return;
        }
        $origin = $request->origin() ?: $request->referer();
        $host = strtolower((string) parse_url((string) $origin, PHP_URL_HOST));
        $ownHost = strtolower((string) parse_url(base_url(), PHP_URL_HOST));
        if ($host === '' || $host === $ownHost) {
            return;
        }
        $stale = empty($agent['installed_at']) || strtotime((string) $agent['installed_at'] . ' UTC') < time() - 86400;
        if ($stale || $agent['installed_domain'] !== $host) {
            try {
                DB::instance()->update('agents', ['installed_domain' => mb_substr($host, 0, 190), 'installed_at' => now()], 'id = :id', ['id' => (int) $agent['id']]);
            } catch (\Throwable) {
                // non-critical
            }
        }
    }

    public function startConversation(Request $request): Response
    {
        $agent = $this->agent($request);
        $tenant = $agent['_tenant'];
        if (!$agent['_preview'] && !Usage::withinLimit($tenant, 'messages', 'messages_per_month')) {
            return Response::json(['error' => 'This assistant has reached its monthly message limit.', 'code' => 'limit'], 402);
        }
        if (!LLM::configured()) {
            return Response::json(['error' => 'The assistant is not configured yet.', 'code' => 'not_configured'], 503);
        }
        $conversation = Conversations::start($agent, $request->string('visitor_id') ?: null, [
            'page_url' => $request->string('page_url'),
            'referrer' => $request->string('referrer'),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
        ], $agent['_preview'] && $request->boolean('test'));
        return Response::json([
            'token' => Conversations::token($conversation),
            'conversation_id' => $conversation['public_id'],
            'greeting' => (string) $agent['greeting_message'],
        ]);
    }

    public function sendMessage(Request $request): Response
    {
        $agent = $this->agent($request);
        $tenant = $agent['_tenant'];
        $conversation = $this->conversation($request, $agent);
        $text = trim((string) $request->input('message', ''));
        $modality = $request->string('modality') === 'voice' ? 'voice' : 'text';
        if ($text === '' || mb_strlen($text) > 4000) {
            return Response::json(['error' => 'Please enter a message (max 4000 characters).'], 422);
        }
        if (!RateLimiter::hit('conv:' . $conversation['id'], 30, 60)) {
            return Response::json(['error' => 'You are sending messages too quickly. Please wait a moment.'], 429);
        }
        if (!$agent['_preview'] && !Usage::withinLimit($tenant, 'messages', 'messages_per_month')) {
            return Response::json(['error' => 'This assistant has reached its monthly message limit.', 'code' => 'limit'], 402);
        }
        if ($conversation['status'] === 'closed') {
            DB::instance()->update('conversations', ['status' => 'open'], 'id = :id', ['id' => $conversation['id']]);
        }
        $context = ['page_url' => $request->string('page_url') ?: ($conversation['page_url'] ?? null)];
        $stream = !$request->has('stream') || $request->boolean('stream', true);

        if (!$stream) {
            $result = ChatEngine::respond($agent, $tenant, $conversation, $text, $modality, static function (): void {
            }, $context);
            return Response::json($this->donePayload($result));
        }

        return Response::stream(function () use ($agent, $tenant, $conversation, $text, $modality, $context): void {
            @set_time_limit(180);
            ignore_user_abort(true);
            echo ':' . str_repeat(' ', 2048) . "\n\n"; // defeat proxy buffering
            $this->flush();
            $emit = function (string $event, array $data): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                $this->flush();
            };
            try {
                $result = ChatEngine::respond($agent, $tenant, $conversation, $text, $modality, $emit, $context);
                $emit('done', $this->donePayload($result));
            } catch (\Throwable $e) {
                Logger::exception($e);
                $emit('error', ['error' => 'Something went wrong. Please try again.']);
            }
        }, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    private function donePayload(array $result): array
    {
        return [
            'message_id' => $result['message_id'],
            'text' => $result['text'],
            'sources' => array_map(static fn($s) => ['title' => $s['title'], 'url' => $s['url']], $result['sources']),
            'lead_saved' => $result['lead_id'] > 0,
            'unanswered' => $result['unanswered'],
            'error' => $result['error'] !== null,
        ];
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    public function history(Request $request): Response
    {
        $agent = $this->agent($request, true);
        $conversation = $this->conversation($request, $agent);
        $messages = [];
        foreach (Conversations::messages((int) $conversation['id']) as $m) {
            if ($m['role'] === 'system') {
                continue;
            }
            $messages[] = ['id' => (int) $m['id'], 'role' => $m['role'], 'content' => $m['content'], 'modality' => $m['modality'], 'created_at' => $m['created_at'], 'feedback' => $m['feedback']];
        }
        return Response::json(['status' => $conversation['status'], 'messages' => $messages]);
    }

    public function stt(Request $request): Response
    {
        $agent = $this->agent($request);
        $conversation = $this->conversation($request, $agent);
        $plan = Plans::forTenant($agent['_tenant']);
        if (Speech::sttMode($agent, (int) ($plan['limits']['premium_voice'] ?? 0) === 1) !== 'server') {
            return Response::json(['error' => 'Server transcription is not enabled for this assistant.'], 403);
        }
        $file = $request->file('audio');
        if (!$file || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No audio received.'], 422);
        }
        if ((int) $file['size'] > 15 * 1024 * 1024) {
            return Response::json(['error' => 'Recording is too long.'], 422);
        }
        $mime = (string) ($file['type'] ?: 'audio/webm');
        $language = (string) $agent['language'] !== 'auto' ? (string) $agent['language'] : ($request->string('language') ?: null);
        try {
            $result = Speech::transcribe((string) $file['tmp_name'], $mime, $language);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 502);
        }
        $seconds = max(1, min(600, (int) round($request->float('duration', 0))));
        if (!$agent['_preview']) {
            Usage::increment((int) $agent['tenant_id'], (int) $agent['id'], 'stt_seconds', $seconds);
        }
        return Response::json(['text' => $result['text']]);
    }

    public function tts(Request $request): Response
    {
        $agent = $this->agent($request);
        $conversation = $this->conversation($request, $agent);
        $plan = Plans::forTenant($agent['_tenant']);
        $mode = Speech::ttsMode($agent, (int) ($plan['limits']['premium_voice'] ?? 0) === 1);
        if ($mode === 'browser') {
            return Response::json(['error' => 'Server voices are not enabled for this assistant.'], 403);
        }
        $text = trim((string) $request->input('text', ''));
        if ($text === '' || mb_strlen($text) > 3000) {
            return Response::json(['error' => 'Invalid text.'], 422);
        }
        // Only speak text the assistant actually produced in this conversation (or the greeting)
        $allowed = [(string) $agent['greeting_message']];
        foreach (DB::instance()->fetchAll('SELECT content FROM messages WHERE conversation_id = ? AND role = \'assistant\' ORDER BY id DESC LIMIT 6', [(int) $conversation['id']]) as $row) {
            $allowed[] = (string) $row['content'];
        }
        $normalizedText = preg_replace('/\s+/', ' ', $text) ?? $text;
        $ok = false;
        foreach ($allowed as $candidate) {
            if (str_contains(preg_replace('/\s+/', ' ', $candidate) ?? $candidate, $normalizedText)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return Response::json(['error' => 'Text not recognised.'], 403);
        }
        if (!RateLimiter::hit('tts:' . $conversation['id'], 120, 60)) {
            return Response::json(['error' => 'Too many requests.'], 429);
        }
        try {
            $audio = Speech::synthesize($this->speakable($text), $mode, (string) $agent['tts_voice'], (float) $agent['tts_speed']);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 502);
        }
        if (!$agent['_preview'] && !$audio['cached']) {
            Usage::increment((int) $agent['tenant_id'], (int) $agent['id'], 'tts_characters', mb_strlen($text));
        }
        return new Response($audio['audio'], 200, [
            'Content-Type' => $audio['mime'],
            'Content-Length' => (string) strlen($audio['audio']),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /** Strip markdown so it is not read aloud. */
    private function speakable(string $text): string
    {
        $text = preg_replace('/\[([^\]]+)\]\((https?:[^)]+)\)/', '$1', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/', 'the link', $text) ?? $text;
        $text = preg_replace('/[*_`#>]+/', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-•]\s+/m', '', $text) ?? $text;
        return trim($text);
    }

    public function lead(Request $request): Response
    {
        $agent = $this->agent($request);
        $conversation = $this->conversation($request, $agent);
        if ((int) $agent['lead_capture_enabled'] !== 1) {
            return Response::json(['error' => 'Lead capture is disabled.'], 403);
        }
        if (!RateLimiter::hit('lead:' . $conversation['id'], 5, 600)) {
            return Response::json(['error' => 'Too many submissions.'], 429);
        }
        $data = ['name' => $request->string('name'), 'email' => $request->string('email'), 'phone' => $request->string('phone'), 'message' => $request->string('message')];
        $id = Leads::create($agent, $conversation, $data, 'form');
        if ($id === 0) {
            return Response::json(['error' => 'Please provide at least your name and an email address or phone number.'], 422);
        }
        Conversations::addMessage($conversation, 'system', 'Visitor submitted the contact form.' . ($data['message'] !== '' ? ' Message: ' . $data['message'] : ''), 'text');
        return Response::json(['ok' => true]);
    }

    public function feedback(Request $request): Response
    {
        $agent = $this->agent($request, true);
        $conversation = $this->conversation($request, $agent);
        $messageId = $request->int('message_id');
        $value = $request->int('value');
        if (!in_array($value, [1, -1, 0], true)) {
            return Response::json(['error' => 'Invalid value.'], 422);
        }
        DB::instance()->update('messages', ['feedback' => $value === 0 ? null : $value], 'id = :id AND conversation_id = :cid AND role = \'assistant\'', ['id' => $messageId, 'cid' => (int) $conversation['id']]);
        return Response::json(['ok' => true]);
    }

    public function end(Request $request): Response
    {
        $agent = $this->agent($request, true);
        $conversation = $this->conversation($request, $agent);
        Conversations::close((int) $conversation['id']);
        return Response::json(['ok' => true]);
    }
}
