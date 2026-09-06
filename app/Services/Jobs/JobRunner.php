<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Core\DB;
use App\Core\Logger;
use App\Services\Knowledge\Crawler;
use App\Services\Knowledge\Indexer;

/**
 * Executes queued jobs within a time budget. Handlers return true when the job is finished,
 * false when it should continue on the next run.
 */
final class JobRunner
{
    /** Process jobs until the budget is spent. Returns the number of job runs executed. */
    public static function work(int $budgetSeconds, string $worker = 'worker'): int
    {
        $deadline = microtime(true) + $budgetSeconds;
        $runs = 0;
        while (microtime(true) < $deadline - 2) {
            $job = JobQueue::claimNext($worker);
            if (!$job) {
                break;
            }
            self::runOne($job, (int) max(3, $deadline - microtime(true)));
            $runs++;
        }
        return $runs;
    }

    /** Run a single claimed job for at most $budgetSeconds. */
    public static function runOne(array $job, int $budgetSeconds): void
    {
        $deadline = microtime(true) + max(3, $budgetSeconds);
        $id = (int) $job['id'];
        @set_time_limit($budgetSeconds + 60);
        try {
            $finished = match ($job['type']) {
                'crawl_website' => Crawler::run($job, $deadline),
                'process_source' => Indexer::processSourceJob($job, $deadline),
                'index_documents' => Indexer::indexPendingJob($job, $deadline),
                'reindex_agent' => Indexer::reindexJob($job, $deadline),
                default => throw new \RuntimeException('Unknown job type: ' . $job['type']),
            };
            if (!$finished) {
                $current = JobQueue::find($id);
                if ($current && $current['status'] === 'running') {
                    JobQueue::release($id);
                }
            }
        } catch (\Throwable $e) {
            Logger::exception($e);
            JobQueue::fail($id, $e->getMessage(), self::isRetryable($e));
        }
    }

    private static function isRetryable(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        foreach (['timeout', 'timed out', 'rate limit', '429', '503', '502', 'temporar', 'connection'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Housekeeping run by the daily cron. */
    public static function maintenance(): void
    {
        $db = DB::instance();
        $db->query("DELETE FROM jobs WHERE status IN ('completed','cancelled') AND finished_at < ?", [gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);
        $db->query("DELETE FROM jobs WHERE status = 'failed' AND finished_at < ?", [gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
        $db->query('DELETE FROM crawl_urls WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        $db->query('DELETE FROM password_resets WHERE expires_at < ?', [now()]);
        $db->query('DELETE FROM rate_limits WHERE reset_at < ?', [time() - 3600]);
        $db->query('DELETE FROM activity_logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 180 * 86400)]);
        try {
            $db->query('DELETE FROM embedding_cache WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 60 * 86400)]);
        } catch (\Throwable) {
            // table may not exist yet
        }
        // Expire old TTS cache files
        foreach (glob(APP_ROOT . '/storage/cache/tts/*.mp3') ?: [] as $file) {
            if (filemtime($file) < time() - 30 * 86400) {
                @unlink($file);
            }
        }
        foreach (glob(APP_ROOT . '/storage/tmp/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
            }
        }
    }
}
