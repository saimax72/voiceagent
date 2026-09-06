<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Logger;

/**
 * Global (platform-level) settings stored in the database. Secrets are encrypted at rest.
 */
final class Settings
{
    private static ?array $cache = null;

    public const SECRET_KEYS = [
        'anthropic_api_key', 'openai_api_key', 'voyage_api_key', 'elevenlabs_api_key', 'compatible_api_key',
        'stripe_secret_key', 'stripe_webhook_secret', 'smtp_password',
    ];

    public static function defaults(): array
    {
        $appName = (string) App::config('app.name', 'VoiceAgent');
        return [
            'app_name' => $appName,
            'support_email' => '',
            'registration_enabled' => '1',
            'default_plan' => 'free',
            'trial_plan' => 'pro',
            'trial_days' => '14',
            // LLM
            'llm_provider' => 'anthropic',
            'anthropic_api_key' => '',
            'anthropic_model' => 'claude-sonnet-5',
            'anthropic_fallbacks' => '1',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4.1-mini',
            'compatible_base_url' => '',
            'compatible_api_key' => '',
            'compatible_model' => '',
            'llm_effort' => 'low',
            'llm_max_tokens' => '1024',
            // Embeddings / retrieval
            'embeddings_provider' => 'auto',
            'openai_embedding_model' => 'text-embedding-3-small',
            'voyage_api_key' => '',
            'voyage_embedding_model' => 'voyage-3.5-lite',
            'embedding_dimensions' => '768',
            'retrieval_top_k' => '6',
            'retrieval_min_score' => '0.30',
            'chunk_size' => '1600',
            'chunk_overlap' => '200',
            // Voice
            'stt_provider' => 'auto',
            'openai_stt_model' => 'gpt-4o-mini-transcribe',
            'tts_provider' => 'auto',
            'openai_tts_model' => 'gpt-4o-mini-tts',
            'elevenlabs_api_key' => '',
            'elevenlabs_model' => 'eleven_flash_v2_5',
            // Documents / crawling
            'pdf_extraction' => 'auto',
            'crawler_max_pages_default' => '100',
            'crawler_timeout' => '15',
            'crawler_user_agent' => 'Mozilla/5.0 (compatible; VoiceAgentBot/1.0; +https://voiceagent.saiberlab.com/bot)',
            // Billing
            'stripe_enabled' => '0',
            'stripe_publishable_key' => '',
            'stripe_secret_key' => '',
            'stripe_webhook_secret' => '',
            // Mail
            'mail_driver' => '',
            'mail_from_email' => '',
            'mail_from_name' => '',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => '',
            'smtp_password' => '',
            'lead_notifications' => '1',
            'widget_branding_text' => 'Powered by ' . $appName,
            'worker_heartbeat' => '',
        ];
    }

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $values = self::defaults();
        try {
            foreach (DB::instance()->fetchAll('SELECT setting_key, setting_value, is_encrypted FROM settings') as $row) {
                $value = (string) ($row['setting_value'] ?? '');
                if ((int) $row['is_encrypted'] === 1) {
                    $value = Crypto::decrypt($value);
                }
                $values[$row['setting_key']] = $value;
            }
        } catch (\Throwable $e) {
            Logger::error('Settings load failed: ' . $e->getMessage());
        }
        return self::$cache = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            return $default;
        }
        $value = $all[$key];
        return ($value === '' || $value === null) && $default !== null ? $default : $value;
    }

    public static function bool(string $key): bool
    {
        return in_array((string) self::get($key, '0'), ['1', 'true', 'on', 'yes'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function isSecret(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    public static function set(string $key, mixed $value): void
    {
        $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $encrypted = self::isSecret($key) && $value !== '';
        DB::instance()->query(
            'INSERT INTO settings (setting_key, setting_value, is_encrypted, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted), updated_at = VALUES(updated_at)',
            [$key, $encrypted ? Crypto::encrypt($value) : $value, $encrypted ? 1 : 0, now()]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set((string) $key, $value);
        }
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
