<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Database-backed fixed-window rate limiter (works on shared hosting without Redis).
 */
final class RateLimiter
{
    /** Returns true when the request is allowed. */
    public static function hit(string $key, int $maxHits, int $windowSeconds): bool
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $now = time();
        $db = DB::instance();
        try {
            $row = $db->fetch('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ? LIMIT 1', [$key]);
            if (!$row || (int) $row['reset_at'] <= $now) {
                $db->query(
                    'INSERT INTO rate_limits (rl_key, hits, reset_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = 1, reset_at = VALUES(reset_at)',
                    [$key, $now + $windowSeconds]
                );
                return true;
            }
            if ((int) $row['hits'] >= $maxHits) {
                return false;
            }
            $db->query('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Rate limiter failure: ' . $e->getMessage());
            return true; // fail open
        }
    }

    public static function retryAfter(string $key): int
    {
        $key = substr(hash('sha256', $key), 0, 64);
        $reset = (int) (DB::instance()->fetchColumn('SELECT reset_at FROM rate_limits WHERE rl_key = ?', [$key]) ?? 0);
        return max(1, $reset - time());
    }

    public static function cleanup(): void
    {
        DB::instance()->query('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 3600]);
    }
}
