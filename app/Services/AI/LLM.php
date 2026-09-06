<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Services\Settings;

/**
 * Factory for the configured language model provider.
 */
final class LLM
{
    public const ANTHROPIC_MODELS = [
        'claude-opus-5' => 'Claude Opus 5 (most capable, default)',
        'claude-sonnet-5' => 'Claude Sonnet 5 (fast, great value)',
        'claude-haiku-4-5' => 'Claude Haiku 4.5 (fastest, lowest cost)',
        'claude-opus-4-8' => 'Claude Opus 4.8',
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
    ];

    public static function providerName(): string
    {
        $provider = (string) Settings::get('llm_provider', 'anthropic');
        return in_array($provider, ['anthropic', 'openai', 'compatible'], true) ? $provider : 'anthropic';
    }

    public static function configured(): bool
    {
        return match (self::providerName()) {
            'openai' => (string) Settings::get('openai_api_key', '') !== '',
            'compatible' => (string) Settings::get('compatible_base_url', '') !== '',
            default => (string) Settings::get('anthropic_api_key', '') !== '',
        };
    }

    public static function provider(?string $name = null): LLMProvider
    {
        $name ??= self::providerName();
        switch ($name) {
            case 'openai':
                $key = (string) Settings::get('openai_api_key', '');
                if ($key === '') {
                    throw new \RuntimeException('OpenAI API key is not configured. Add it under Admin > Settings > AI providers.');
                }
                return new OpenAIProvider($key, (string) Settings::get('openai_model', 'gpt-4.1-mini'));
            case 'compatible':
                $base = (string) Settings::get('compatible_base_url', '');
                if ($base === '') {
                    throw new \RuntimeException('The OpenAI-compatible endpoint is not configured.');
                }
                return new OpenAIProvider((string) Settings::get('compatible_api_key', 'none'), (string) Settings::get('compatible_model', ''), $base, 'compatible');
            default:
                $key = (string) Settings::get('anthropic_api_key', '');
                if ($key === '') {
                    throw new \RuntimeException('Anthropic API key is not configured. Add it under Admin > Settings > AI providers.');
                }
                return new AnthropicProvider($key, (string) Settings::get('anthropic_model', 'claude-opus-5'), Settings::bool('anthropic_fallbacks'));
        }
    }

    /** Anthropic provider specifically (used for PDF reading), or null when not configured. */
    public static function anthropic(): ?AnthropicProvider
    {
        $key = (string) Settings::get('anthropic_api_key', '');
        if ($key === '') {
            return null;
        }
        return new AnthropicProvider($key, (string) Settings::get('anthropic_model', 'claude-opus-5'), Settings::bool('anthropic_fallbacks'));
    }

    /** Models selectable per agent for the active provider. */
    public static function modelChoices(): array
    {
        return match (self::providerName()) {
            'openai' => [(string) Settings::get('openai_model', 'gpt-4.1-mini') => 'Default (' . Settings::get('openai_model', 'gpt-4.1-mini') . ')'],
            'compatible' => [(string) Settings::get('compatible_model', '') => 'Default (' . Settings::get('compatible_model', 'configured model') . ')'],
            default => self::ANTHROPIC_MODELS,
        };
    }

    /** Resolve the model for an agent (agent override or platform default). */
    public static function modelFor(array $agent): ?string
    {
        $model = trim((string) ($agent['llm_model'] ?? ''));
        return $model !== '' ? $model : null;
    }
}
