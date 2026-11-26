<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncGithubIssueStatusJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class GithubIssueStatusDispatchService
{
    public function dispatch(int $taskId): bool
    {
        try {
            return DB::transaction(function () use ($taskId): bool {
                $task = DB::table('github_issue_status_outbox')
                    ->where('id', $taskId)->lockForUpdate()->first();

                if (! $task || in_array($task->status, [
                    'COMPLETED', 'REJECTED', 'SUPERSEDED', 'FAILED',
                ], true)) {
                    return false;
                }

                if (($task->available_at && Carbon::parse($task->available_at)->isFuture())
                    || ($task->lease_until && Carbon::parse($task->lease_until)->isFuture())
                    || ($task->queued_at && Carbon::parse($task->queued_at)->gt(now()->subMinutes(2)))) {
                    return false;
                }

                if (config('queue.connections.database.driver') !== 'database'
                    || (config('queue.connections.database.connection')
                        ?? config('database.default')) !== config('database.default')) {
                    throw new LogicException('Same-database queue required.');
                }

                DB::table('github_issue_status_outbox')
                    ->where('id', $taskId)
                    ->update(['queued_at' => now(), 'updated_at' => now()]);

                Bus::dispatch(
                    (new SyncGithubIssueStatusJob($taskId))->beforeCommit()
                );

                return true;
            }, 3);
        } catch (Throwable) {
            // Roll back dispatch metadata; the scheduler can recover it.
            return false;
        }
    }
}
