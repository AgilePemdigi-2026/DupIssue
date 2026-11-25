<?php

namespace App\Services;

use App\Jobs\SyncGithubIssueJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class GithubWebhookDispatchService
{
    public function dispatch(int $taskId): bool
    {
        try {
            return DB::transaction(function () use ($taskId): bool {
                $task = DB::table('github_webhook_tasks')->where('id', $taskId)->lockForUpdate()->first();
                if (! $task || in_array($task->status, ['COMPLETED', 'FAILED', 'REJECTED'], true)) {
                    return false;
                }
                if ($task->available_at && now()->lt($task->available_at)) {
                    return false;
                }
                if ($task->lease_until && now()->lt($task->lease_until)) {
                    return false;
                }
                if ($task->queued_at && now()->subMinutes(5)->lt($task->queued_at)) {
                    return false;
                }
                if (config('queue.connections.database.driver') !== 'database') {
                    throw new LogicException('Database queue required.');
                }
                if ((config('queue.connections.database.connection') ?? config('database.default')) !== config('database.default')) {
                    throw new LogicException('Webhook outbox requires the same database connection as jobs.');
                }
                DB::table('github_webhook_tasks')->where('id', $taskId)->update([
                    'status' => 'QUEUED', 'queued_at' => now(), 'error_code' => null, 'updated_at' => now(),
                ]);
                Bus::dispatch((new SyncGithubIssueJob($taskId))->beforeCommit());

                return true;
            }, 3);
        } catch (Throwable) {
            DB::table('github_webhook_tasks')->where('id', $taskId)->whereIn('status', ['RECEIVED', 'RETRY', 'QUEUED'])
                ->update(['error_code' => 'DISPATCH_FAILED', 'updated_at' => now()]);

            return false;
        }
    }
}
