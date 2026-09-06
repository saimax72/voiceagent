<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Http;
use App\Core\Logger;
use App\Services\Settings;

/**
 * Anthropic Messages API over raw HTTP (no SDK/Composer dependency, works on shared hosting).
 */
final class AnthropicProvider implements LLMProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    public function __construct(private string $apiKey, private string $model, private bool $fallbacks = true)
    {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    private function supportsEffort(string $model): bool
    {
        // Effort is supported on Opus 4.6+/Sonnet 4.6+/Sonnet 5/Opus 5/Fable; not on Haiku 4.5 or 4.5-era models.
        return !preg_match('/haiku|sonnet-4-5|opus-4-5|opus-4-1|claude-3/i', $model);
    }

    private function supportsFallbacks(string $model): bool
    {
        return (bool) preg_match('/claude-(opus-5|fable-5|mythos-5)/i', $model);
    }

    private function buildBody(array $request, bool $stream): array
    {
        $model = (string) ($request['model'] ?? $this->model);
        $body = [
            'model' => $model,
            'max_tokens' => (int) ($request['max_tokens'] ?? 1024),
            'messages' => $this->normalizeMessages($request['messages'] ?? [], $request['documents'] ?? []),
        ];
        if (!empty($request['system'])) {
            $body['system'] = [['type' => 'text', 'text' => (string) $request['system'], 'cache_control' => ['type' => 'ephemeral']]];
        }
        if (!empty($request['tools'])) {
            $body['tools'] = array_values($request['tools']);
        }
        if (!empty($request['effort']) && $this->supportsEffort($model)) {
            $body['output_config'] = ['effort' => (string) $request['effort']];
        }
        if ($this->fallbacks && $this->supportsFallbacks($model)) {
            $body['fallbacks'] = 'default';
        }
        if ($stream) {
            $body['stream'] = true;
        }
        return $body;
    }

    private function headers(array $body): array
    {
        $headers = [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::VERSION,
            'Accept' => isset($body['stream']) ? 'text/event-stream' : 'application/json',
        ];
        if (isset($body['fallbacks'])) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }
        return $headers;
    }

    /** Convert our message shape into API blocks; attach documents to the last user message. */
    private function normalizeMessages(array $messages, array $documents): array
    {
        $out = [];
        foreach ($messages as $m) {
            $content = $m['content'];
            if (is_string($content)) {
                $content = [['type' => 'text', 'text' => $content]];
            }
            $out[] = ['role' => $m['role'], 'content' => array_values($content)];
        }
        if ($documents && $out) {
            $lastIndex = count($out) - 1;
            $blocks = [];
            foreach ($documents as $doc) {
                $blocks[] = [
                    'type' => 'document',
                    'source' => ['type' => 'base64', 'media_type' => $doc['media_type'] ?? 'application/pdf', 'data' => $doc['data']],
                ];
            }
            $out[$lastIndex]['content'] = array_merge($blocks, $out[$lastIndex]['content']);
        }
        return $out;
    }

    public function complete(array $request): LLMResult
    {
        $result = new LLMResult();
        $body = $this->buildBody($request, false);
        $response = Http::postJson(self::ENDPOINT, $body, $this->headers($body), ['timeout' => (int) ($request['timeout'] ?? 180)]);
        if (!$response->ok()) {
            $result->error = $this->errorMessage($response->status, $response->body, $response->error);
            return $result;
        }
        $data = $response->json();
        $result->model = (string) ($data['model'] ?? $body['model']);
        $result->stopReason = (string) ($data['stop_reason'] ?? '');
        $result->refused = $result->stopReason === 'refusal';
        $result->inputTokens = (int) ($data['usage']['input_tokens'] ?? 0);
        $result->outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        foreach ((array) ($data['content'] ?? []) as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $result->text .= (string) $block['text'];
                $result->content[] = ['type' => 'text', 'text' => (string) $block['text']];
            } elseif ($type === 'tool_use') {
                $tool = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => is_array($block['input'] ?? null) ? $block['input'] : []];
                $result->toolUses[] = $tool;
                $result->content[] = ['type' => 'tool_use', 'id' => $tool['id'], 'name' => $tool['name'], 'input' => $tool['input'] ?: new \stdClass()];
            } elseif ($type === 'thinking' && !empty($block['signature'])) {
                // Thinking blocks must be replayed unchanged when the turn continues after tool use
                $result->content[] = ['type' => 'thinking', 'thinking' => (string) ($block['thinking'] ?? ''), 'signature' => (string) $block['signature']];
            } elseif ($type === 'redacted_thinking' && !empty($block['data'])) {
                $result->content[] = ['type' => 'redacted_thinking', 'data' => (string) $block['data']];
            }
        }
        return $result;
    }

    public function stream(array $request, callable $onText): LLMResult
    {
        $result = new LLMResult();
        $body = $this->buildBody($request, true);
        $buffer = '';
        $blocks = []; // index => ['type' => ..., 'text' => '', 'json' => '', 'id' => '', 'name' => '']

        $handleEvent = function (array $event) use (&$blocks, $result, $onText): void {
            $type = $event['type'] ?? '';
            switch ($type) {
                case 'message_start':
                    $result->model = (string) ($event['message']['model'] ?? $result->model);
                    $result->inputTokens = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                    break;
                case 'content_block_start':
                    $index = (int) ($event['index'] ?? 0);
                    $block = $event['content_block'] ?? [];
                    $blocks[$index] = [
                        'type' => (string) ($block['type'] ?? ''),
                        'text' => (string) ($block['text'] ?? ''),
                        'json' => '',
                        'id' => (string) ($block['id'] ?? ''),
                        'name' => (string) ($block['name'] ?? ''),
                        'thinking' => (string) ($block['thinking'] ?? ''),
                        'signature' => (string) ($block['signature'] ?? ''),
                        'data' => (string) ($block['data'] ?? ''),
                    ];
                    if ($blocks[$index]['text'] !== '') {
                        $onText($blocks[$index]['text']);
                    }
                    break;
                case 'content_block_delta':
                    $index = (int) ($event['index'] ?? 0);
                    $delta = $event['delta'] ?? [];
                    if (!isset($blocks[$index])) {
                        $blocks[$index] = ['type' => 'text', 'text' => '', 'json' => '', 'id' => '', 'name' => '', 'thinking' => '', 'signature' => '', 'data' => ''];
                    }
                    $deltaType = (string) ($delta['type'] ?? '');
                    if ($deltaType === 'text_delta') {
                        $blocks[$index]['text'] .= (string) $delta['text'];
                        $onText((string) $delta['text']);
                    } elseif ($deltaType === 'input_json_delta') {
                        $blocks[$index]['json'] .= (string) $delta['partial_json'];
                    } elseif ($deltaType === 'thinking_delta') {
                        $blocks[$index]['thinking'] .= (string) ($delta['thinking'] ?? '');
                    } elseif ($deltaType === 'signature_delta') {
                        $blocks[$index]['signature'] .= (string) ($delta['signature'] ?? '');
                    }
                    break;
                case 'message_delta':
                    $result->stopReason = (string) ($event['delta']['stop_reason'] ?? $result->stopReason);
                    $result->outputTokens = (int) ($event['usage']['output_tokens'] ?? $result->outputTokens);
                    break;
                case 'error':
                    $result->error = (string) ($event['error']['message'] ?? 'Streaming error');
                    break;
            }
        };

        $response = Http::postJson(self::ENDPOINT, $body, $this->headers($body), [
            'timeout' => (int) ($request['timeout'] ?? 120),
            'stream' => function (string $chunk) use (&$buffer, $handleEvent): bool {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $raw = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    foreach (explode("\n", $raw) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $json = json_decode(trim(substr($line, 5)), true);
                            if (is_array($json)) {
                                $handleEvent($json);
                            }
                        }
                    }
                }
                return true;
            },
        ]);

        if (!$response->ok() && $result->error === '') {
            $result->error = $this->errorMessage($response->status, $response->body, $response->error);
        }
        ksort($blocks);
        foreach ($blocks as $block) {
            if ($block['type'] === 'text') {
                $result->text .= $block['text'];
                $result->content[] = ['type' => 'text', 'text' => $block['text']];
            } elseif ($block['type'] === 'tool_use') {
                $input = json_decode($block['json'] !== '' ? $block['json'] : '{}', true);
                $input = is_array($input) ? $input : [];
                $result->toolUses[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => $input];
                $result->content[] = ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => $input ?: new \stdClass()];
            } elseif ($block['type'] === 'thinking' && $block['signature'] !== '') {
                $result->content[] = ['type' => 'thinking', 'thinking' => $block['thinking'], 'signature' => $block['signature']];
            } elseif ($block['type'] === 'redacted_thinking' && $block['data'] !== '') {
                $result->content[] = ['type' => 'redacted_thinking', 'data' => $block['data']];
            }
        }
        $result->refused = $result->stopReason === 'refusal';
        if ($result->model === '') {
            $result->model = $body['model'];
        }
        return $result;
    }

    public function test(): array
    {
        $result = $this->complete(['messages' => [['role' => 'user', 'content' => 'Reply with the single word OK.']], 'max_tokens' => 16]);
        if (!$result->ok()) {
            return ['ok' => false, 'message' => $result->error];
        }
        return ['ok' => true, 'message' => 'Connected. Model ' . $result->model . ' replied: ' . trim($result->text)];
    }

    private function errorMessage(int $status, string $body, string $transport): string
    {
        if ($transport !== '') {
            return 'Connection error: ' . $transport;
        }
        $data = json_decode($body, true);
        $message = (string) ($data['error']['message'] ?? $body);
        Logger::error('Anthropic API error ' . $status . ': ' . mb_substr($message, 0, 500));
        return match (true) {
            $status === 401 => 'The Anthropic API key is invalid.',
            $status === 429 => 'The AI service is busy (rate limited). Please try again in a moment.',
            $status >= 500 => 'The AI service is temporarily unavailable.',
            default => 'AI request failed: ' . mb_substr($message, 0, 300),
        };
    }
}
