<?php
declare(strict_types=1);

namespace App\Services\Chat;

use App\Core\Crypto;
use App\Core\DB;
use App\Core\Str;
use App\Services\Usage;

final class Conversations
{
    public static function start(array $agent, ?string $visitorId, array $meta = [], bool $isTest = false): array
    {
        $db = DB::instance();
        $now = now();
        $ua = (string) ($meta['user_agent'] ?? '');
        $device = preg_match('/mobile|android|iphone|ipad/i', $ua) ? (preg_match('/ipad|tablet/i', $ua) ? 'tablet' : 'mobile') : 'desktop';
        $visitorId = $visitorId && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $visitorId) ? $visitorId : Str::random(24);
        $id = $db->insert('conversations', [
            'tenant_id' => (int) $agent['tenant_id'],
            'agent_id' => (int) $agent['id'],
            'public_id' => Str::publicId(),
            'visitor_id' => $visitorId,
            'channel' => 'text',
            'status' => 'open',
            'page_url' => isset($meta['page_url']) ? mb_substr((string) $meta['page_url'], 0, 1000) : null,
            'referrer' => isset($meta['referrer']) ? mb_substr((string) $meta['referrer'], 0, 1000) : null,
            'user_agent' => mb_substr($ua, 0, 500) ?: null,
            'ip_hash' => isset($meta['ip']) ? hash('sha256', $meta['ip'] . '|' . (string) \App\Core\App::config('app.key')) : null,
            'device' => $device,
            'is_test' => $isTest ? 1 : 0,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$isTest) {
            $db->query(
                'INSERT INTO visitors (tenant_id, agent_id, visitor_id, first_seen_at, last_seen_at, conversations) VALUES (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), conversations = conversations + 1',
                [(int) $agent['tenant_id'], (int) $agent['id'], $visitorId, $now, $now]
            );
            Usage::recordDaily((int) $agent['tenant_id'], (int) $agent['id'], ['conversations' => 1]);
            $db->query('UPDATE agents SET conversations_count = conversations_count + 1 WHERE id = ?', [(int) $agent['id']]);
        }
        return $db->fetch('SELECT * FROM conversations WHERE id = ?', [$id]);
    }

    public static function token(array $conversation): string
    {
        return Crypto::sign(['c' => (int) $conversation['id'], 'a' => (int) $conversation['agent_id']], 60 * 60 * 24 * 30);
    }

    /** Resolve a signed widget token to a conversation belonging to the given agent. */
    public static function fromToken(?string $token, array $agent): ?array
    {
        if (!$token) {
            return null;
        }
        $payload = Crypto::verify($token);
        if (!$payload || (int) ($payload['a'] ?? 0) !== (int) $agent['id']) {
            return null;
        }
        $conversation = DB::instance()->fetch('SELECT * FROM conversations WHERE id = ? AND agent_id = ? LIMIT 1', [(int) $payload['c'], (int) $agent['id']]);
        return $conversation ?: null;
    }

    public static function messages(int $conversationId, int $limit = 200): array
    {
        $rows = DB::instance()->fetchAll('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT ' . (int) $limit, [$conversationId]);
        foreach ($rows as &$row) {
            $row['sources'] = json_field($row['sources']);
            $row['meta'] = json_field($row['meta']);
        }
        return $rows;
    }

    public static function addMessage(array $conversation, string $role, string $content, string $modality = 'text', array $extra = []): int
    {
        $db = DB::instance();
        $id = $db->insert('messages', array_merge([
            'tenant_id' => (int) $conversation['tenant_id'],
            'conversation_id' => (int) $conversation['id'],
            'role' => $role,
            'content' => $content,
            'modality' => $modality,
            'sources' => null,
            'meta' => null,
            'tokens_input' => 0,
            'tokens_output' => 0,
            'latency_ms' => 0,
            'is_unanswered' => 0,
            'created_at' => now(),
        ], $extra));
        $updates = ['message_count' => (int) $conversation['message_count'] + 1, 'last_message_at' => now(), 'updated_at' => now()];
        if ($modality === 'voice') {
            $updates['voice_message_count'] = (int) $conversation['voice_message_count'] + ($role === 'user' ? 1 : 0);
            $updates['channel'] = (int) $conversation['message_count'] === 0 || $conversation['channel'] === 'voice' ? 'voice' : 'mixed';
        } elseif ($conversation['channel'] === 'voice' && $role === 'user') {
            $updates['channel'] = 'mixed';
        }
        if ($role === 'user' && empty($conversation['title'])) {
            $updates['title'] = mb_substr(preg_replace('/\s+/', ' ', $content) ?? $content, 0, 120);
        }
        $db->update('conversations', $updates, 'id = :id', ['id' => $conversation['id']]);
        return $id;
    }

    public static function close(int $conversationId): void
    {
        DB::instance()->update('conversations', ['status' => 'closed', 'updated_at' => now()], 'id = :id', ['id' => $conversationId]);
    }
}
