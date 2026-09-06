<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Str;

/**
 * Tenant (workspace) lifecycle: creation, deletion, lookups.
 */
final class Tenants
{
    /** Create a workspace with its owner user. Returns [tenant, user]. */
    public static function create(string $workspaceName, string $userName, string $email, string $password, array $options = []): array
    {
        $db = DB::instance();
        $now = now();
        $email = strtolower(trim($email));
        $slug = self::uniqueSlug($workspaceName !== '' ? $workspaceName : $userName);
        $trialDays = (int) Settings::get('trial_days', 14);
        $trialEnds = $trialDays > 0 && !($options['no_trial'] ?? false) ? gmdate('Y-m-d H:i:s', time() + $trialDays * 86400) : null;

        return $db->transaction(static function (DB $db) use ($workspaceName, $userName, $email, $password, $slug, $now, $trialEnds, $options): array {
            $tenantId = $db->insert('tenants', [
                'name' => $workspaceName !== '' ? $workspaceName : $userName . "'s workspace",
                'slug' => $slug,
                'website_url' => $options['website_url'] ?? null,
                'plan_key' => (string) Settings::get('default_plan', 'free'),
                'status' => 'active',
                'trial_ends_at' => $trialEnds,
                'settings' => json_encode(['notification_email' => $email]),
                'onboarding_completed' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = $db->insert('users', [
                'tenant_id' => $tenantId,
                'name' => $userName,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'owner',
                'is_super_admin' => !empty($options['super_admin']) ? 1 : 0,
                'email_verified_at' => !empty($options['verified']) ? $now : null,
                'verification_token' => !empty($options['verified']) ? null : bin2hex(random_bytes(20)),
                'timezone' => $options['timezone'] ?? 'UTC',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $tenant = $db->fetch('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
            $user = $db->fetch('SELECT * FROM users WHERE id = ?', [$userId]);
            return [$tenant, $user];
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = substr(Str::slug($name), 0, 60) ?: 'workspace';
        $slug = $base;
        $i = 1;
        while (DB::instance()->exists('tenants', 'slug = ?', [$slug])) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }

    public static function settings(array $tenant): array
    {
        return json_field($tenant['settings'] ?? null);
    }

    public static function updateSettings(int $tenantId, array $changes): void
    {
        $tenant = DB::instance()->fetch('SELECT settings FROM tenants WHERE id = ?', [$tenantId]);
        $settings = array_merge(json_field($tenant['settings'] ?? null), $changes);
        DB::instance()->update('tenants', ['settings' => json_encode($settings), 'updated_at' => now()], 'id = :id', ['id' => $tenantId]);
    }

    /** Permanently delete a tenant and everything it owns. */
    public static function delete(int $tenantId): void
    {
        $db = DB::instance();
        // Remove uploaded files
        foreach ($db->fetchAll('SELECT file_path FROM knowledge_sources WHERE tenant_id = ? AND file_path IS NOT NULL', [$tenantId]) as $row) {
            $path = APP_ROOT . '/storage/documents/' . $row['file_path'];
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $uploadDir = APP_ROOT . '/uploads/' . $tenantId;
        if (is_dir($uploadDir)) {
            foreach (glob($uploadDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($uploadDir);
        }
        $tables = ['knowledge_chunks', 'knowledge_documents', 'knowledge_sources', 'messages', 'conversations', 'leads',
            'unanswered_questions', 'usage_records', 'analytics_daily', 'jobs', 'visitors', 'activity_logs', 'agents', 'users'];
        foreach ($tables as $table) {
            $db->delete($table, 'tenant_id = ?', [$tenantId]);
        }
        $db->delete('tenants', 'id = ?', [$tenantId]);
    }

    public static function log(int $tenantId, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): void
    {
        try {
            DB::instance()->insert('activity_logs', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details ? json_encode($details) : null,
                'ip' => \App\Core\App::request()->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never block on logging
        }
    }
}
