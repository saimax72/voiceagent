<?php
declare(strict_types=1);

namespace App\Services\AI;

use App\Services\Settings;

/**
 * Factory for language model providers (platform default or per-agent choice).
 */
final class LLM
{
    public const ANTHROPIC_MODELS = [
        'claude-sonnet-5' => 'Claude Sonnet 5 (fast, recommended for voice - default)',
        'claude-haiku-4-5' => 'Claude Haiku 4.5 (fastest replies, lowest cost)',
        'claude-opus-5' => 'Claude Opus 5 (most capable, slower)',
        'claude-opus-4-8' => 'Claude Opus 4.8',
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
    ];

    public const OPENAI_MODELS = [
        'gpt-4.1' => 'GPT-4.1',
        'gpt-4.1-mini' => 'GPT-4.1 mini',
        'gpt-4.1-nano' => 'GPT-4.1 nano',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o mini',
    ];

    public const PROVIDER_LABELS = ['anthropic' => 'Anthropic Claude', 'openai' => 'OpenAI', 'compatible' => 'Custom endpoint'];

    public static function providerName(): string
    {
        $provider = (string) Settings::get('llm_provider', 'anthropic');
        return in_array($provider, ['anthropic', 'openai', 'compatible'], true) ? $provider : 'anthropic';
    }

    public static function isConfigured(string $name): bool
    {
        return match ($name) {
            'anthropic' => (string) Settings::get('anthropic_api_key', '') !== '',
            'openai' => (string) Settings::get('openai_api_key', '') !== '',
            'compatible' => (string) Settings::get('compatible_base_url', '') !== '',
            default => false,
        };
    }

    public static function configured(): bool
    {
        return self::isConfigured(self::providerName());
    }

    /** Providers with credentials, keyed by name. */
    public static function configuredProviders(): array
    {
        $out = [];
        foreach (self::PROVIDER_LABELS as $name => $label) {
            if (self::isConfigured($name)) {
                $out[$name] = $label;
            }
        }
        return $out;
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
                return new AnthropicProvider($key, (string) Settings::get('anthropic_model', 'claude-sonnet-5'), Settings::bool('anthropic_fallbacks'));
        }
    }

    /** Provider for an agent: its own choice when configured, otherwise the platform default. */
    public static function providerFor(array $agent): LLMProvider
    {
        $name = (string) ($agent['llm_provider'] ?? '');
        if ($name !== '' && self::isConfigured($name)) {
            return self::provider($name);
        }
        return self::provider();
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

    /** Models selectable per agent, grouped by configured provider: [provider => [model => label]]. */
    public static function modelChoices(): array
    {
        $groups = [];
        if (self::isConfigured('anthropic')) {
            $models = self::ANTHROPIC_MODELS;
            $default = (string) Settings::get('anthropic_model', 'claude-sonnet-5');
            if ($default !== '' && !isset($models[$default])) {
                $models = [$default => $default . ' (platform default)'] + $models;
            }
            $groups['anthropic'] = $models;
        }
        if (self::isConfigured('openai')) {
            $models = self::OPENAI_MODELS;
            $default = (string) Settings::get('openai_model', 'gpt-4.1-mini');
            if ($default !== '' && !isset($models[$default])) {
                $models = [$default => $default . ' (platform default)'] + $models;
            }
            $groups['openai'] = $models;
        }
        if (self::isConfigured('compatible')) {
            $model = (string) Settings::get('compatible_model', '');
            $groups['compatible'] = [$model => ($model !== '' ? $model : 'Configured model')];
        }
        return $groups;
    }

    /** Resolve the model override for an agent, validated against the provider it will use. */
    public static function modelFor(array $agent): ?string
    {
        $model = trim((string) ($agent['llm_model'] ?? ''));
        if ($model === '') {
            return null;
        }
        $provider = (string) ($agent['llm_provider'] ?? '');
        if ($provider === '' || !self::isConfigured($provider)) {
            $provider = self::providerName();
        }
        $choices = self::modelChoices()[$provider] ?? [];
        return isset($choices[$model]) ? $model : null;
    }
}
