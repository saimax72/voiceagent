<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Str;
use App\Services\AI\Speech;

/**
 * Agent records, defaults, personas and widget configuration.
 */
final class Agents
{
    public const PERSONAS = [
        'friendly' => ['label' => 'Friendly & helpful', 'description' => 'Warm, approachable and conversational.',
            'prompt' => 'Be warm, friendly and approachable. Use a natural conversational tone and keep answers clear and easy to follow.'],
        'professional' => ['label' => 'Professional', 'description' => 'Polished, precise and business-like.',
            'prompt' => 'Be professional, polished and precise. Keep a courteous business tone and avoid slang or exclamation marks.'],
        'concise' => ['label' => 'Short & direct', 'description' => 'Minimal words, straight to the point.',
            'prompt' => 'Be direct and concise. Answer in as few words as possible while staying complete and polite.'],
        'enthusiastic' => ['label' => 'Enthusiastic', 'description' => 'Upbeat and energetic brand voice.',
            'prompt' => 'Be upbeat, positive and energetic. Show genuine enthusiasm for helping, without being over the top.'],
        'empathetic' => ['label' => 'Empathetic support', 'description' => 'Calm, patient and reassuring.',
            'prompt' => 'Be calm, patient and reassuring. Acknowledge the visitor\'s situation before answering and never rush them.'],
        'custom' => ['label' => 'Custom', 'description' => 'Describe the personality in the instructions box.', 'prompt' => ''],
    ];

    public const FONTS = ['Inter', 'DM Sans', 'Poppins', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Nunito', 'Manrope', 'Source Sans 3', 'System'];

    public static function defaultWidgetConfig(): array
    {
        return [
            'position' => 'right',
            'offset_x' => 24,
            'offset_y' => 24,
            'launcher_size' => 60,
            'launcher_shape' => 'circle',
            'launcher_icon' => 'chat',
            'launcher_image' => '',
            'launcher_label' => '',
            'launcher_pulse' => true,
            'popup_width' => 400,
            'popup_height' => 640,
            'border_radius' => 20,
            'font' => 'Inter',
            'theme' => 'light',
            'primary_color' => '#5b5bd6',
            'header_bg' => '#5b5bd6',
            'header_text' => '#ffffff',
            'bg_color' => '#ffffff',
            'text_color' => '#0f172a',
            'bot_bubble_bg' => '#f1f5f9',
            'bot_bubble_text' => '#0f172a',
            'user_bubble_bg' => '#5b5bd6',
            'user_bubble_text' => '#ffffff',
            'button_color' => '#5b5bd6',
            'button_text_color' => '#ffffff',
            'avatar_image' => '',
            'avatar_style' => 'initials',
            'header_title' => '',
            'header_subtitle' => 'Online - ask me anything',
            'greeting_text' => 'Hi there!',
            'welcome_message' => 'I can answer questions about our products, services and more. You can type or talk to me.',
            'ask_me_text' => 'Ask me anything',
            'input_placeholder' => 'Type your message...',
            'mic_text' => 'Tap to talk',
            'listening_text' => 'Listening...',
            'thinking_text' => 'Thinking...',
            'speaking_text' => 'Speaking...',
            'suggested_questions' => ['What services do you offer?', 'How can I contact you?', 'What are your opening hours?'],
            'lead_form_title' => 'Leave your details and we will get back to you',
            'show_lead_form' => true,
            'voice_mode_default' => false,
            'auto_open' => false,
            'auto_open_delay' => 8,
            'sound_effects' => true,
            'show_branding' => true,
            'z_index' => 2147483000,
        ];
    }

    public static function widgetConfig(array $agent): array
    {
        $stored = json_field($agent['widget_config'] ?? null);
        $config = array_merge(self::defaultWidgetConfig(), $stored);
        if (trim((string) $config['header_title']) === '') {
            $config['header_title'] = (string) ($agent['name'] ?? 'Assistant');
        }
        if (!is_array($config['suggested_questions'])) {
            $config['suggested_questions'] = array_values(array_filter(array_map('trim', explode("\n", (string) $config['suggested_questions']))));
        }
        return $config;
    }

    public static function find(int $id, int $tenantId): ?array
    {
        return DB::instance()->fetch('SELECT * FROM agents WHERE id = ? AND tenant_id = ? LIMIT 1', [$id, $tenantId]);
    }

    public static function findOrFail(int $id, int $tenantId): array
    {
        $agent = self::find($id, $tenantId);
        if (!$agent) {
            abort(404, 'Agent not found.');
        }
        return $agent;
    }

    public static function findByPublicId(string $publicId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            return null;
        }
        return DB::instance()->fetch('SELECT * FROM agents WHERE public_id = ? LIMIT 1', [$publicId]);
    }

    public static function forTenant(int $tenantId): array
    {
        return DB::instance()->fetchAll(
            'SELECT a.*, (SELECT COUNT(*) FROM knowledge_documents d WHERE d.agent_id = a.id AND d.status = \'indexed\') AS documents_indexed
             FROM agents a WHERE a.tenant_id = ? ORDER BY a.created_at DESC',
            [$tenantId]
        );
    }

    public static function defaultGreeting(string $businessName): string
    {
        $name = trim($businessName) !== '' ? trim($businessName) : 'our website';
        return "Hi! I'm the virtual assistant for {$name}. Ask me anything about our products and services, or tap the microphone to talk to me.";
    }

    public static function create(int $tenantId, array $data): array
    {
        $now = now();
        $name = trim((string) ($data['name'] ?? '')) ?: 'Assistant';
        $business = trim((string) ($data['business_name'] ?? ''));
        $widget = self::defaultWidgetConfig();
        $widget['header_title'] = $name;
        if (!empty($data['primary_color'])) {
            foreach (['primary_color', 'header_bg', 'user_bubble_bg', 'button_color'] as $k) {
                $widget[$k] = $data['primary_color'];
            }
        }
        $id = DB::instance()->insert('agents', [
            'tenant_id' => $tenantId,
            'public_id' => Str::publicId(),
            'name' => $name,
            'status' => 'active',
            'description' => $data['description'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'business_name' => $business ?: null,
            'persona' => in_array($data['persona'] ?? '', array_keys(self::PERSONAS), true) ? $data['persona'] : 'friendly',
            'instructions' => $data['instructions'] ?? null,
            'language' => $data['language'] ?? 'auto',
            'llm_model' => $data['llm_model'] ?? null,
            'effort' => 'low',
            'response_length' => 'medium',
            'voice_enabled' => isset($data['voice_enabled']) ? (int) (bool) $data['voice_enabled'] : 1,
            'tts_provider' => $data['tts_provider'] ?? 'auto',
            'tts_voice' => $data['tts_voice'] ?? 'alloy',
            'tts_speed' => 1.00,
            'stt_provider' => 'auto',
            'auto_speak' => 1,
            'greeting_message' => $data['greeting_message'] ?? self::defaultGreeting($business),
            'fallback_message' => $data['fallback_message'] ?? "I'm sorry, I don't have that information yet. Would you like to leave your contact details so someone from the team can get back to you?",
            'lead_capture_enabled' => 1,
            'lead_fields' => 'name,email,phone,message',
            'lead_instructions' => null,
            'lead_notify_email' => $data['lead_notify_email'] ?? null,
            'allowed_domains' => null,
            'widget_config' => json_encode($widget),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return DB::instance()->fetch('SELECT * FROM agents WHERE id = ?', [$id]);
    }

    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = now();
        DB::instance()->update('agents', $data, 'id = :id', ['id' => $id]);
    }

    public static function saveWidgetConfig(int $id, array $config): void
    {
        self::update($id, ['widget_config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }

    public static function delete(array $agent): void
    {
        $db = DB::instance();
        $id = (int) $agent['id'];
        foreach ($db->fetchAll('SELECT file_path FROM knowledge_sources WHERE agent_id = ? AND file_path IS NOT NULL', [$id]) as $row) {
            $path = APP_ROOT . '/storage/documents/' . $row['file_path'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (['knowledge_chunks', 'knowledge_documents', 'knowledge_sources', 'leads', 'unanswered_questions', 'jobs', 'visitors', 'analytics_daily', 'usage_records'] as $table) {
            $db->delete($table, 'agent_id = ?', [$id]);
        }
        $db->query('DELETE m FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.agent_id = ?', [$id]);
        $db->delete('conversations', 'agent_id = ?', [$id]);
        $db->delete('agents', 'id = ?', [$id]);
    }

    public static function duplicate(array $agent): int
    {
        $copy = $agent;
        unset($copy['id'], $copy['conversations_count'], $copy['messages_count'], $copy['leads_count'], $copy['last_trained_at']);
        $copy['public_id'] = Str::publicId();
        $copy['name'] = $agent['name'] . ' (copy)';
        $copy['status'] = 'paused';
        $copy['created_at'] = $copy['updated_at'] = now();
        return DB::instance()->insert('agents', $copy);
    }

    public static function refreshCounts(int $agentId): void
    {
        $db = DB::instance();
        $db->query(
            'UPDATE agents SET conversations_count = (SELECT COUNT(*) FROM conversations WHERE agent_id = ? AND is_test = 0),
             messages_count = (SELECT COUNT(*) FROM messages m INNER JOIN conversations c ON c.id = m.conversation_id WHERE c.agent_id = ? AND c.is_test = 0 AND m.role = \'user\'),
             leads_count = (SELECT COUNT(*) FROM leads WHERE agent_id = ?) WHERE id = ?',
            [$agentId, $agentId, $agentId, $agentId]
        );
    }

    /** Allowed domains as a normalised list of hosts. */
    public static function allowedDomains(array $agent): array
    {
        $raw = (string) ($agent['allowed_domains'] ?? '');
        $out = [];
        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $d) {
            $d = strtolower(trim($d));
            if ($d === '') {
                continue;
            }
            $d = preg_replace('~^https?://~', '', $d) ?? $d;
            $d = explode('/', $d)[0];
            $d = preg_replace('/^www\./', '', $d) ?? $d;
            $out[] = $d;
        }
        return array_values(array_unique($out));
    }

    public static function domainAllowed(array $agent, ?string $origin): bool
    {
        $allowed = self::allowedDomains($agent);
        if (!$allowed) {
            return true;
        }
        if (!$origin) {
            return false;
        }
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '') {
            return false;
        }
        foreach ($allowed as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }
        return false;
    }

    public static function leadFields(array $agent): array
    {
        $fields = array_values(array_filter(array_map('trim', explode(',', (string) ($agent['lead_fields'] ?? 'name,email')))));
        return array_values(array_intersect(['name', 'email', 'phone', 'message'], $fields)) ?: ['name', 'email'];
    }

    /** Configuration exposed to the public widget. */
    public static function publicConfig(array $agent, array $tenant): array
    {
        $widget = self::widgetConfig($agent);
        $plan = Plans::forTenant($tenant);
        $premium = (int) ($plan['limits']['premium_voice'] ?? 0) === 1;
        $canRemoveBranding = (int) ($plan['limits']['remove_branding'] ?? 0) === 1;
        if (!$canRemoveBranding) {
            $widget['show_branding'] = true;
        }
        $ttsMode = Speech::ttsMode($agent, $premium);
        $sttMode = Speech::sttMode($agent, $premium);
        $base = base_url();
        foreach (['launcher_image', 'avatar_image'] as $k) {
            if (!empty($widget[$k]) && !preg_match('~^https?://~', (string) $widget[$k])) {
                $widget[$k] = $base . '/' . ltrim((string) $widget[$k], '/');
            }
        }
        return [
            'agent' => [
                'public_id' => $agent['public_id'],
                'name' => $agent['name'],
                'status' => $agent['status'],
                'language' => $agent['language'],
                'voice_enabled' => (bool) $agent['voice_enabled'],
                'auto_speak' => (bool) $agent['auto_speak'],
                'greeting' => (string) $agent['greeting_message'],
                'tts' => ['mode' => $ttsMode, 'voice' => $agent['tts_voice'], 'speed' => (float) $agent['tts_speed']],
                'stt' => ['mode' => $sttMode],
                'lead_capture' => (bool) $agent['lead_capture_enabled'],
                'lead_fields' => self::leadFields($agent),
            ],
            'widget' => $widget,
            'branding' => [
                'show' => (bool) $widget['show_branding'],
                'text' => (string) Settings::get('widget_branding_text', 'Powered by ' . app_name()),
                'url' => $base,
            ],
            'api_base' => $base . '/api/widget',
        ];
    }
}
