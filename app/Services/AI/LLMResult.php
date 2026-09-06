<?php
declare(strict_types=1);

namespace App\Services\AI;

/**
 * Normalised result of a model call (streaming or not).
 */
final class LLMResult
{
    public string $text = '';
    /** @var array<int, array{id:string,name:string,input:array}> */
    public array $toolUses = [];
    /** Raw assistant content blocks, replayable as the next assistant turn. */
    public array $content = [];
    public string $stopReason = '';
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public string $model = '';
    public string $error = '';
    public bool $refused = false;

    public function ok(): bool
    {
        return $this->error === '';
    }

    public function wantsTools(): bool
    {
        return $this->stopReason === 'tool_use' && $this->toolUses !== [];
    }
}
