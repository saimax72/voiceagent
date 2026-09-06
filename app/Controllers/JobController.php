<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\Jobs\JobQueue;
use App\Services\Jobs\JobRunner;

/**
 * Background job status for the dashboard, plus browser-driven processing when no cron is configured.
 */
final class JobController
{
    private function find(int $id): array
    {
        $job = JobQueue::find($id);
        if (!$job || ((int) $job['tenant_id'] !== tenant_id() && !auth()->isSuperAdmin())) {
            abort(404, 'Job not found.');
        }
        return $job;
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json(JobQueue::toArray($this->find((int) $id)));
    }

    /** Run a slice of the job in this request (used when the cron worker is not running). */
    public function tick(Request $request, string $id): Response
    {
        $job = $this->find((int) $id);
        if (!in_array($job['status'], ['queued', 'running'], true)) {
            return Response::json(JobQueue::toArray($job));
        }
        if (JobQueue::workerAlive()) {
            return Response::json(JobQueue::toArray($job));
        }
        $budget = (int) min(25, max(8, (int) ini_get('max_execution_time') - 5 ?: 25));
        @set_time_limit($budget + 15);
        ignore_user_abort(true);
        $claimed = JobQueue::claim((int) $job['id'], 'browser-' . tenant_id());
        if ($claimed) {
            JobRunner::runOne($claimed, $budget);
        }
        $fresh = JobQueue::find((int) $job['id']) ?? $job;
        return Response::json(JobQueue::toArray($fresh));
    }

    public function cancel(Request $request, string $id): Response
    {
        $job = $this->find((int) $id);
        if (in_array($job['status'], ['queued', 'running'], true)) {
            JobQueue::cancel((int) $job['id']);
            if ($job['type'] === 'crawl_website' || $job['type'] === 'process_source') {
                $sourceId = (int) ($job['payload']['source_id'] ?? 0);
                if ($sourceId) {
                    db()->update('knowledge_sources', ['status' => 'error', 'error_message' => 'Cancelled', 'updated_at' => now()], 'id = :id AND status = \'processing\'', ['id' => $sourceId]);
                }
            }
        }
        return Response::json(JobQueue::toArray(JobQueue::find((int) $job['id']) ?? $job));
    }

    public function forAgent(Request $request, string $id): Response
    {
        $agent = \App\Services\Agents::findOrFail((int) $id, tenant_id());
        $jobs = array_map([JobQueue::class, 'toArray'], JobQueue::activeForAgent((int) $agent['id']));
        return Response::json(['jobs' => $jobs, 'worker_alive' => JobQueue::workerAlive()]);
    }
}
