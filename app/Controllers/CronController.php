<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Jobs\JobQueue;
use App\Services\Jobs\JobRunner;

/**
 * Web-triggered cron endpoint: /cron/run?token=... (for hosts without CLI cron or external cron services).
 */
final class CronController
{
    public function run(Request $request): Response
    {
        $token = (string) ($request->query['token'] ?? $request->input('token') ?? '');
        $expected = (string) App::config('app.cron_token', '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            return Response::json(['error' => 'Invalid token'], 403);
        }
        ignore_user_abort(true);
        $budget = (int) min(50, max(10, (int) ini_get('max_execution_time') - 10 ?: 50));
        @set_time_limit($budget + 20);
        JobQueue::markWorkerAlive();
        $runs = JobRunner::work($budget, 'webcron');
        if (($request->query['maintenance'] ?? '') === '1') {
            JobRunner::maintenance();
        }
        return Response::json(['ok' => true, 'runs' => $runs, 'budget' => $budget]);
    }
}
