<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Http;
use App\Core\Logger;

/**
 * OpenAI-compatible chat completions provider (OpenAI, Groq, OpenRouter, Ollama, ...).
 * Accepts the same request shape as AnthropicProvider and converts it.
 */
final class OpenAIProvider implements LLMProvider
{
    public function __construct(private string $apiKey, private string $model, private string $baseUrl = 'https://api.openai.com/v1', private string $label = 'openai')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function name(): string
    {
        return $this->label;
    }

    public function defaultModel(): string
    {
        return $this->model;
    }

    private function buildBody(array $request, bool $stream): array
    {
        $messages = [];
        if (!empty($request['system'])) {
            $system = is_array($request['system']) ? implode("\n\n", array_filter($request['system'], static fn($b) => trim((string) $b) !== '')) : (string) $request['system'];
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($request['messages'] ?? [] as $m) {
            $content = $m['content'];
            if (is_string($content)) {
                $messages[] = ['role' => $m['role'], 'content' => $content];
                continue;
            }
            // Block content: split into text, tool calls and tool results
            $text = '';
            $toolCalls = [];
            $toolResults = [];
            foreach ($content as $block) {
                $type = $block['type'] ?? 'text';
                if ($type === 'text') {
                    $text .= (string) $block['text'];
                } elseif ($type === 'tool_use') {
                    $toolCalls[] = ['id' => $block['id'], 'type' => 'function', 'function' => ['name' => $block['name'], 'arguments' => json_encode($block['input'] ?: new \stdClass())]];
                } elseif ($type === 'tool_result') {
                    $toolResults[] = ['role' => 'tool', 'tool_call_id' => $block['tool_use_id'], 'content' => is_string($block['content']) ? $block['content'] : json_encode($block['content'])];
                }
            }
            if ($m['role'] === 'assistant') {
                $entry = ['role' => 'assistant', 'content' => $text !== '' ? $text : null];
                if ($toolCalls) {
                    $entry['tool_calls'] = $toolCalls;
                }
                $messages[] = $entry;
            } else {
                foreach ($toolResults as $tr) {
                    $messages[] = $tr;
                }
                if ($text !== '') {
                    $messages[] = ['role' => 'user', 'content' => $text];
                }
            }
        }
        $body = [
            'model' => (string) ($request['model'] ?? $this->model),
            'messages' => $messages,
        ];
        // OpenAI uses max_completion_tokens; many compatible servers (Groq, Ollama, ...) still expect max_tokens
        $body[$this->label === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = (int) ($request['max_tokens'] ?? 1024);
        if (!empty($request['tools'])) {
            $body['tools'] = array_map(static fn(array $t) => [
                'type' => 'function',
                'function' => ['name' => $t['name'], 'description' => $t['description'] ?? '', 'parameters' => $t['input_schema'] ?? ['type' => 'object', 'properties' => new \stdClass()]],
            ], array_values($request['tools']));
        }
        if ($stream) {
            $body['stream'] = true;
            $body['stream_options'] = ['include_usage' => true];
        }
        return $body;
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    public function complete(array $request): LLMResult
    {
        $result = new LLMResult();
        $body = $this->buildBody($request, false);
        $response = Http::postJson($this->baseUrl . '/chat/completions', $body, $this->headers(), ['timeout' => (int) ($request['timeout'] ?? 180)]);
        if (!$response->ok()) {
            $result->error = $this->errorMessage($response->status, $response->body, $response->error);
            return $result;
        }
        $data = $response->json();
        $choice = $data['choices'][0] ?? [];
        $result->model = (string) ($data['model'] ?? $body['model']);
        $result->inputTokens = (int) ($data['usage']['prompt_tokens'] ?? 0);
        $result->outputTokens = (int) ($data['usage']['completion_tokens'] ?? 0);
        $result->text = (string) ($choice['message']['content'] ?? '');
        if ($result->text !== '') {
            $result->content[] = ['type' => 'text', 'text' => $result->text];
        }
        foreach ((array) ($choice['message']['tool_calls'] ?? []) as $call) {
            $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            $tool = ['id' => (string) $call['id'], 'name' => (string) $call['function']['name'], 'input' => is_array($input) ? $input : []];
            $result->toolUses[] = $tool;
            $result->content[] = ['type' => 'tool_use', 'id' => $tool['id'], 'name' => $tool['name'], 'input' => $tool['input'] ?: new \stdClass()];
        }
        $finish = (string) ($choice['finish_reason'] ?? '');
        $result->stopReason = $finish === 'tool_calls' ? 'tool_use' : ($finish === 'length' ? 'max_tokens' : 'end_turn');
        return $result;
    }

    public function stream(array $request, callable $onText): LLMResult
    {
        $result = new LLMResult();
        $body = $this->buildBody($request, true);
        $buffer = '';
        $calls = []; // index => ['id','name','args']
        $finish = '';
        $response = Http::postJson($this->baseUrl . '/chat/completions', $body, $this->headers(), [
            'timeout' => (int) ($request['timeout'] ?? 120),
            'stream' => function (string $chunk) use (&$buffer, &$calls, &$finish, $result, $onText): bool {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if (!str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $payload = trim(substr($line, 5));
                    if ($payload === '[DONE]') {
                        continue;
                    }
                    $json = json_decode($payload, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    if (isset($json['usage']['prompt_tokens'])) {
                        $result->inputTokens = (int) $json['usage']['prompt_tokens'];
                        $result->outputTokens = (int) ($json['usage']['completion_tokens'] ?? 0);
                    }
                    if (!empty($json['model'])) {
                        $result->model = (string) $json['model'];
                    }
                    $choice = $json['choices'][0] ?? null;
                    if (!$choice) {
                        continue;
                    }
                    $delta = $choice['delta'] ?? [];
                    if (isset($delta['content']) && $delta['content'] !== '' && $delta['content'] !== null) {
                        $result->text .= (string) $delta['content'];
                        $onText((string) $delta['content']);
                    }
                    foreach ((array) ($delta['tool_calls'] ?? []) as $tc) {
                        $i = (int) ($tc['index'] ?? 0);
                        $calls[$i] ??= ['id' => '', 'name' => '', 'args' => ''];
                        if (!empty($tc['id'])) {
                            $calls[$i]['id'] = (string) $tc['id'];
                        }
                        if (!empty($tc['function']['name'])) {
                            $calls[$i]['name'] .= (string) $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $calls[$i]['args'] .= (string) $tc['function']['arguments'];
                        }
                    }
                    if (!empty($choice['finish_reason'])) {
                        $finish = (string) $choice['finish_reason'];
                    }
                }
                return true;
            },
        ]);
        if (!$response->ok()) {
            $result->error = $this->errorMessage($response->status, $response->body, $response->error);
            return $result;
        }
        if ($result->text !== '') {
            $result->content[] = ['type' => 'text', 'text' => $result->text];
        }
        ksort($calls);
        foreach ($calls as $call) {
            $input = json_decode($call['args'] !== '' ? $call['args'] : '{}', true);
            $tool = ['id' => $call['id'] ?: 'call_' . bin2hex(random_bytes(6)), 'name' => $call['name'], 'input' => is_array($input) ? $input : []];
            $result->toolUses[] = $tool;
            $result->content[] = ['type' => 'tool_use', 'id' => $tool['id'], 'name' => $tool['name'], 'input' => $tool['input'] ?: new \stdClass()];
        }
        $result->stopReason = ($finish === 'tool_calls' || $result->toolUses) ? 'tool_use' : ($finish === 'length' ? 'max_tokens' : 'end_turn');
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
        Logger::error($this->label . ' API error ' . $status . ': ' . mb_substr($message, 0, 500));
        return match (true) {
            $status === 401 => 'The ' . $this->label . ' API key is invalid.',
            $status === 429 => 'The AI service is busy (rate limited). Please try again in a moment.',
            $status >= 500 => 'The AI service is temporarily unavailable.',
            default => 'AI request failed: ' . mb_substr($message, 0, 300),
        };
    }
}
