<?php

namespace App\Services;

use App\Models\GithubAppConnection;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GithubWebhookSyncService
{
    public function __construct(private readonly GithubAppIssueFetchService $fetch, private readonly GithubIssueImportService $import) {}

    public function process(int $taskId): bool
    {
        $task = DB::transaction(function () use ($taskId) {
            $row = DB::table('github_webhook_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['COMPLETED', 'REJECTED', 'FAILED'], true)) {
                return null;
            }
            if (($row->lease_until && now()->lt($row->lease_until)) || ($row->available_at && now()->lt($row->available_at))) {
                return false;
            }
            if ($row->attempts >= 5) {
                DB::table('github_webhook_tasks')->where('id', $taskId)->update(['status' => 'FAILED', 'error_code' => 'ATTEMPTS_EXHAUSTED', 'updated_at' => now()]);

                return null;
            }
            $row->claim_token = (string) Str::uuid();
            $row->attempts++;
            DB::table('github_webhook_tasks')->where('id', $taskId)->update([
                'claim_token' => $row->claim_token, 'attempts' => $row->attempts, 'status' => 'PROCESSING',
                'lease_until' => now()->addSeconds(120), 'updated_at' => now(),
            ]);

            return $row;
        }, 3);
        if ($task === false) {
            return false;
        }
        if ($task === null) {
            return true;
        }
        try {
            $delivery = DB::table('github_webhook_deliveries')->where('id', $task->delivery_id)->first();
            $connection = GithubAppConnection::find($task->connection_id);
            $this->verify($task, $delivery, $connection);
            // Network calls happen outside locks. Identity is rechecked before persistence.
            $remote = $this->fetch->fetchIssue($connection, (int) $delivery->issue_number);
            DB::transaction(function () use ($task, $delivery, $remote): void {
                $project = Project::whereKey($task->project_id)->lockForUpdate()->first();
                if (! $project) {
                    throw new RuntimeException('Project unavailable.', 409);
                }
                $connection = GithubAppConnection::where('project_id', $project->id)->lockForUpdate()->first();
                $this->verify($task, $delivery, $connection);
                $current = DB::table('github_webhook_tasks')->where('id', $task->id)->lockForUpdate()->first();
                if ($current->status !== 'PROCESSING' || $current->claim_token !== $task->claim_token
                    || now()->gte($current->lease_until)) {
                    throw new RuntimeException('Delivery lease expired.', 503);
                }
                $this->import->importVerifiedWebhook($project, $connection, $remote, (int) $task->id);
                $connection->last_synced_at = now();
                $connection->save();
                DB::table('github_webhook_tasks')->where('id', $task->id)->update([
                    'status' => 'COMPLETED', 'completed_at' => now(), 'lease_until' => null,
                    'error_code' => null, 'available_at' => null, 'updated_at' => now(),
                ]);
            }, 3);

            return true;
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            $permanent = in_array($code, [400, 403, 404, 409, 410, 422], true);
            $terminal = $permanent || $task->attempts >= 5;
            DB::table('github_webhook_tasks')->where('id', $task->id)->where('claim_token', $task->claim_token)
                ->where('status', 'PROCESSING')->update([
                    'status' => $terminal ? ($permanent ? 'REJECTED' : 'FAILED') : 'RETRY',
                    'error_code' => $permanent ? 'REMOTE_OR_CONNECTION_REJECTED' : 'SYNC_RETRY_REQUIRED',
                    'lease_until' => null, 'queued_at' => null,
                    'available_at' => $terminal ? null : now()->addSeconds([60, 120, 300, 600][min($task->attempts - 1, 3)]),
                    'updated_at' => now(),
                ]);
            if ($permanent) {
                return true;
            }
            // Never persist provider response bodies, tokens or SQL bindings in failed_jobs.
            throw new RuntimeException('GitHub synchronization temporarily unavailable.', 503);
        }
    }

    private function verify(object $task, ?object $delivery, ?GithubAppConnection $connection): void
    {
        if (! $delivery || ! $connection || ! $connection->verified_at
            || (int) $connection->id !== (int) $task->connection_id
            || (int) $connection->project_id !== (int) $task->project_id
            || (int) $connection->installation_id !== (int) $delivery->installation_id
            || (int) $connection->repository_id !== (int) $delivery->repository_id
            || strcasecmp($connection->repo_full_name, $task->repository) !== 0) {
            throw new RuntimeException('GitHub connection changed.', 409);
        }
    }
}
