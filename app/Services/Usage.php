<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

/**
 * Usage metering per tenant/agent/month plus daily analytics counters.
 * Metrics: messages, voice_messages, tokens_input, tokens_output, tts_characters, stt_seconds,
 *          embedding_tokens, pages_crawled, documents
 */
final class Usage
{
    public static function period(?int $timestamp = null): string
    {
        return gmdate('Y-m', $timestamp ?? time());
    }

    public static function increment(int $tenantId, int $agentId, string $metric, int $amount = 1): void
    {
        if ($amount === 0) {
            return;
        }
        try {
            DB::instance()->query(
                'INSERT INTO usage_records (tenant_id, agent_id, period, metric, value, updated_at) VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = value + VALUES(value), updated_at = VALUES(updated_at)',
                [$tenantId, $agentId, self::period(), $metric, max(0, $amount), now()]
            );
        } catch (\Throwable $e) {
            Logger::error('Usage increment failed: ' . $e->getMessage());
        }
    }

    public static function incrementMany(int $tenantId, int $agentId, array $metrics): void
    {
        foreach ($metrics as $metric => $amount) {
            self::increment($tenantId, $agentId, (string) $metric, (int) $amount);
        }
    }

    public static function get(int $tenantId, string $metric, ?string $period = null, int $agentId = -1): int
    {
        $sql = 'SELECT COALESCE(SUM(value), 0) FROM usage_records WHERE tenant_id = ? AND period = ? AND metric = ?';
        $params = [$tenantId, $period ?? self::period(), $metric];
        if ($agentId >= 0) {
            $sql .= ' AND agent_id = ?';
            $params[] = $agentId;
        }
        return (int) DB::instance()->fetchColumn($sql, $params);
    }

    /** All metrics for a tenant in a period, keyed by metric. */
    public static function summary(int $tenantId, ?string $period = null): array
    {
        $rows = DB::instance()->fetchAll(
            'SELECT metric, SUM(value) AS total FROM usage_records WHERE tenant_id = ? AND period = ? GROUP BY metric',
            [$tenantId, $period ?? self::period()]
        );
        $out = ['messages' => 0, 'voice_messages' => 0, 'tokens_input' => 0, 'tokens_output' => 0, 'tts_characters' => 0, 'stt_seconds' => 0, 'embedding_tokens' => 0, 'pages_crawled' => 0];
        foreach ($rows as $row) {
            $out[$row['metric']] = (int) $row['total'];
        }
        return $out;
    }

    /** Monthly history for the last N months (for charts). */
    public static function history(int $tenantId, string $metric, int $months = 6): array
    {
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $period = gmdate('Y-m', strtotime("first day of -{$i} months"));
            $out[$period] = self::get($tenantId, $metric, $period);
        }
        return $out;
    }

    /** True when the tenant may still consume the metric this month. */
    public static function withinLimit(array $tenant, string $metric, string $limitName, int $amount = 1): bool
    {
        $limit = Plans::limit($tenant, $limitName);
        if ($limit <= 0) {
            return true; // unlimited / not enforced
        }
        return self::get((int) $tenant['id'], $metric) + $amount <= $limit;
    }

    public static function recordDaily(int $tenantId, int $agentId, array $increments): void
    {
        $allowed = ['conversations', 'messages', 'voice_messages', 'leads', 'bookings', 'unanswered', 'tokens_input', 'tokens_output'];
        $sets = [];
        $values = [];
        foreach ($increments as $col => $amount) {
            if (!in_array($col, $allowed, true) || (int) $amount === 0) {
                continue;
            }
            $sets[] = "`{$col}` = `{$col}` + VALUES(`{$col}`)";
            $values[$col] = (int) $amount;
        }
        if (!$values) {
            return;
        }
        $cols = array_keys($values);
        try {
            DB::instance()->query(
                'INSERT INTO analytics_daily (tenant_id, agent_id, day, `' . implode('`, `', $cols) . '`) VALUES (?, ?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')
                 ON DUPLICATE KEY UPDATE ' . implode(', ', $sets),
                array_merge([$tenantId, $agentId, gmdate('Y-m-d')], array_values($values))
            );
        } catch (\Throwable $e) {
            Logger::error('Analytics record failed: ' . $e->getMessage());
        }
    }
}
