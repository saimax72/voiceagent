<?php
declare(strict_types=1);

namespace App\Services\Chat;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Mailer;
use App\Services\Settings;
use App\Services\Tenants;
use App\Services\Usage;

final class Leads
{
    /** Create a lead. Returns the lead id, or 0 when the data is unusable. */
    public static function create(array $agent, ?array $conversation, array $data, string $source = 'chat'): int
    {
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 160);
        $email = mb_substr(strtolower(trim((string) ($data['email'] ?? ''))), 0, 190);
        $phone = mb_substr(trim((string) ($data['phone'] ?? '')), 0, 60);
        $message = mb_substr(trim((string) ($data['message'] ?? '')), 0, 4000);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        if ($phone !== '' && !preg_match('/\d{5,}/', preg_replace('/\D/', '', $phone) ?? '')) {
            $phone = '';
        }
        if ($name === '' && $email === '' && $phone === '') {
            return 0;
        }
        $db = DB::instance();
        // Merge with an existing lead from the same conversation instead of duplicating
        if ($conversation) {
            $existing = $db->fetch('SELECT * FROM leads WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', [(int) $conversation['id']]);
            if ($existing) {
                $db->update('leads', [
                    'name' => $name ?: $existing['name'], 'email' => $email ?: $existing['email'], 'phone' => $phone ?: $existing['phone'],
                    'message' => $message ?: $existing['message'], 'updated_at' => now(),
                ], 'id = :id', ['id' => $existing['id']]);
                return (int) $existing['id'];
            }
        }
        $now = now();
        $id = $db->insert('leads', [
            'tenant_id' => (int) $agent['tenant_id'],
            'agent_id' => (int) $agent['id'],
            'conversation_id' => $conversation ? (int) $conversation['id'] : null,
            'name' => $name ?: null, 'email' => $email ?: null, 'phone' => $phone ?: null, 'message' => $message ?: null,
            'source' => $source, 'status' => 'new', 'notes' => null,
            'page_url' => $conversation['page_url'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($conversation) {
            $db->update('conversations', ['has_lead' => 1, 'updated_at' => $now], 'id = :id', ['id' => $conversation['id']]);
        }
        $db->query('UPDATE agents SET leads_count = leads_count + 1 WHERE id = ?', [(int) $agent['id']]);
        if (empty($conversation['is_test'])) {
            Usage::recordDaily((int) $agent['tenant_id'], (int) $agent['id'], ['leads' => 1]);
        }
        self::notify($agent, ['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $message, 'page_url' => $conversation['page_url'] ?? null]);
        return $id;
    }

    private static function notify(array $agent, array $lead): void
    {
        if (!Settings::bool('lead_notifications')) {
            return;
        }
        $to = trim((string) ($agent['lead_notify_email'] ?? ''));
        if ($to === '') {
            $tenant = DB::instance()->fetch('SELECT settings FROM tenants WHERE id = ?', [(int) $agent['tenant_id']]);
            $settings = json_field($tenant['settings'] ?? null);
            $to = (string) ($settings['notification_email'] ?? '');
            if ($to === '' || !empty($settings['lead_emails_disabled'])) {
                return;
            }
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            Mailer::send($to, 'New lead from ' . $agent['name'], Mailer::render('lead', [
                'agent_name' => $agent['name'], 'lead' => $lead, 'link' => url('/leads'),
            ]));
        } catch (\Throwable $e) {
            Logger::error('Lead notification failed: ' . $e->getMessage());
        }
    }
}
