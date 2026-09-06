<?php
declare(strict_types=1);

/**
 * Background worker. Run every minute from cron:
 *   * * * * * php /home/USER/domains/example.com/public_html/cron/worker.php
 * Processes website scans, document uploads and training jobs for up to ~50 seconds per run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Jobs\JobQueue;
use App\Services\Jobs\JobRunner;

$budget = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 50;
set_time_limit($budget + 60);

// Prevent overlapping runs
$lock = fopen(APP_ROOT . '/storage/tmp/worker.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another worker is running.\n";
    exit(0);
}

JobQueue::markWorkerAlive();
$runs = JobRunner::work($budget, 'cron-' . getmypid());
JobQueue::markWorkerAlive();
echo gmdate('c') . " processed {$runs} job run(s)\n";

if ($lock) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
