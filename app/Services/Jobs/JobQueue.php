<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Core\DB;
use App\Services\Settings;

/**
 * Database-backed job queue. Jobs are resumable: a handler may return before finishing and the
 * job is put back in the queue to continue on the next worker run or browser tick.
 */
final class JobQueue
{
    public const STALE_LOCK_SECONDS = 240;
    public const WORKER_ALIVE_SECONDS = 150;

    public static function push(int $tenantId, int $agentId, string $type, array $payload = [], int $delaySeconds = 0): int
    {
        $now = now();
        return DB::instance()->insert('jobs', [
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'queued',
            'progress' => 0,
            'progress_text' => 'Waiting to start...',
            'attempts' => 0,
            'run_after' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function find(int $id): ?array
    {
        $job = DB::instance()->fetch('SELECT * FROM jobs WHERE id = ? LIMIT 1', [$id]);
        if ($job) {
            $job['payload'] = json_field($job['payload']);
            $job['result'] = json_field($job['result']);
        }
        return $job;
    }

    /** Atomically claim the next runnable job. */
    public static function claimNext(string $worker): ?array
    {
        $db = DB::instance();
        $stale = gmdate('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS);
        $candidates = $db->fetchAll(
            "SELECT id FROM jobs WHERE (status = 'queued' AND run_after <= ?) OR (status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < ?)) ORDER BY id ASC LIMIT 5",
            [now(), $stale]
        );
        foreach ($candidates as $row) {
            $job = self::claim((int) $row['id'], $worker);
            if ($job) {
                return $job;
            }
        }
        return null;
    }

    /** Claim a specific job if it is runnable. Returns null when someone else holds it. */
    public static function claim(int $id, string $worker): ?array
    {
        $db = DB::instance();
        $now = now();
        $stale = gmdate('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS);
        $rows = $db->query(
            "UPDATE jobs SET status = 'running', locked_at = ?, locked_by = ?, heartbeat_at = ?, started_at = COALESCE(started_at, ?), attempts = attempts + 1, updated_at = ?
             WHERE id = ? AND ((status = 'queued' AND run_after <= ?) OR (status = 'running' AND (heartbeat_at IS NULL OR heartbeat_at < ?)))",
            [$now, $worker, $now, $now, $now, $id, $now, $stale]
        )->rowCount();
        return $rows > 0 ? self::find($id) : null;
    }

    public static function heartbeat(int $id): void
    {
        DB::instance()->update('jobs', ['heartbeat_at' => now()], 'id = :id', ['id' => $id]);
    }

    public static function progress(int $id, int $percent, ?string $text = null): void
    {
        $data = ['progress' => max(0, min(100, $percent)), 'heartbeat_at' => now(), 'updated_at' => now()];
        if ($text !== null) {
            $data['progress_text'] = mb_substr($text, 0, 250);
        }
        DB::instance()->update('jobs', $data, 'id = :id', ['id' => $id]);
    }

    /** Put the job back in the queue so the next run continues it. */
    public static function release(int $id, ?string $text = null): void
    {
        $data = ['status' => 'queued', 'locked_at' => null, 'locked_by' => null, 'run_after' => now(), 'updated_at' => now()];
        if ($text !== null) {
            $data['progress_text'] = mb_substr($text, 0, 250);
        }
        DB::instance()->update('jobs', $data, 'id = :id', ['id' => $id]);
    }

    public static function complete(int $id, array $result = [], ?string $text = null): void
    {
        DB::instance()->update('jobs', [
            'status' => 'completed', 'progress' => 100, 'progress_text' => $text ?? 'Completed',
            'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'locked_at' => null, 'locked_by' => null, 'finished_at' => now(), 'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
    }

    public static function fail(int $id, string $error, bool $retry = false): void
    {
        $job = self::find($id);
        $canRetry = $retry && $job && (int) $job['attempts'] < 3;
        DB::instance()->update('jobs', [
            'status' => $canRetry ? 'queued' : 'failed',
            'last_error' => mb_substr($error, 0, 2000),
            'progress_text' => $canRetry ? 'Retrying after error...' : 'Failed: ' . mb_substr($error, 0, 200),
            'run_after' => gmdate('Y-m-d H:i:s', time() + ($canRetry ? 30 : 0)),
            'locked_at' => null, 'locked_by' => null,
            'finished_at' => $canRetry ? null : now(),
            'updated_at' => now(),
        ], 'id = :id', ['id' => $id]);
    }

    public static function cancel(int $id): void
    {
        DB::instance()->update('jobs', ['status' => 'cancelled', 'progress_text' => 'Cancelled', 'locked_at' => null, 'locked_by' => null, 'finished_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $id]);
    }

    public static function activeForAgent(int $agentId): array
    {
        $jobs = DB::instance()->fetchAll("SELECT * FROM jobs WHERE agent_id = ? AND status IN ('queued','running') ORDER BY id ASC", [$agentId]);
        foreach ($jobs as &$job) {
            $job['payload'] = json_field($job['payload']);
        }
        return $jobs;
    }

    public static function recentForAgent(int $agentId, int $limit = 10): array
    {
        return DB::instance()->fetchAll('SELECT * FROM jobs WHERE agent_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$agentId]);
    }

    /** Whether a cron worker has checked in recently. */
    public static function workerAlive(): bool
    {
        $last = (string) Settings::get('worker_heartbeat', '');
        return $last !== '' && (time() - (int) $last) < self::WORKER_ALIVE_SECONDS;
    }

    public static function markWorkerAlive(): void
    {
        Settings::set('worker_heartbeat', (string) time());
    }

    /** Public representation for the dashboard poller. */
    public static function toArray(array $job): array
    {
        return [
            'id' => (int) $job['id'],
            'type' => $job['type'],
            'status' => $job['status'],
            'progress' => (int) $job['progress'],
            'progress_text' => $job['progress_text'],
            'result' => is_array($job['result'] ?? null) ? $job['result'] : json_field($job['result'] ?? null),
            'last_error' => $job['last_error'],
            'needs_tick' => in_array($job['status'], ['queued', 'running'], true) && !self::workerAlive(),
            'created_at' => $job['created_at'],
            'finished_at' => $job['finished_at'],
        ];
    }
}
