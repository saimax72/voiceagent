<?php
declare(strict_types=1);

namespace App\Services\AI;

/**
 * Request shape (Anthropic Messages API style):
 *   system   => string
 *   messages => [['role' => 'user'|'assistant', 'content' => string|array<block>], ...]
 *   tools    => [['name' => ..., 'description' => ..., 'input_schema' => [...]], ...]
 *   max_tokens => int
 *   model    => string (optional override)
 *   effort   => low|medium|high (optional)
 *   documents => [['media_type' => 'application/pdf', 'data' => base64], ...] (complete() only)
 */
interface LLMProvider
{
    public function name(): string;

    public function defaultModel(): string;

    /** Stream a response. $onText receives text deltas as they arrive. */
    public function stream(array $request, callable $onText): LLMResult;

    /** Non-streaming request. */
    public function complete(array $request): LLMResult;

    /** Quick connectivity/credential test. Returns ['ok' => bool, 'message' => string]. */
    public function test(): array;
}
