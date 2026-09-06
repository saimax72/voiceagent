<?php
declare(strict_types=1);

/**
 * Daily housekeeping. Run once a day from cron:
 *   0 3 * * * php /home/USER/domains/example.com/public_html/cron/maintenance.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Jobs\JobRunner;

JobRunner::maintenance();
echo gmdate('c') . " maintenance complete\n";
