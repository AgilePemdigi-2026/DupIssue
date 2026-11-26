<?php

namespace App\Services;

use App\Models\GithubAppConnection;
use App\Models\Issue;
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
        $mutex = \App\Services\GithubStatusSyncMutex::acquire();
        if ($mutex === null) {
            return false;
        }

        try {
            return $this->processLocked($taskId);
        } finally {
            $mutex->release();
        }
    }

    private function processLocked(int $taskId): bool
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
            GithubStatusSyncMutex::assertHeld();
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
                // Preserve the existing automatic import workflow.
                // Status-only events must never create new issues.
                match ($delivery->action) {
                    'opened' => $this->importOpened(
                        $project, $connection, $delivery, $remote, (int) $task->id
                    ),
                    'closed', 'reopened' => $this->reconcileStatus(
                        $project,
                        $connection,
                        $delivery,
                        $remote
                    ),
                    default => throw new RuntimeException(
                        'Unsupported webhook action.',
                        422
                    ),
                };
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
            $retryAfter = $exception instanceof GithubStatusRateLimitException
                ? $exception->retryAfterSeconds : 0;
            $permanent = in_array($code, [400, 403, 404, 409, 410, 422], true);
            $terminal = $permanent || $task->attempts >= 5;
            DB::table('github_webhook_tasks')->where('id', $task->id)->where('claim_token', $task->claim_token)
                ->where('status', 'PROCESSING')->update([
                    'status' => $terminal ? ($permanent ? 'REJECTED' : 'FAILED') : 'RETRY',
                    'error_code' => $permanent ? 'REMOTE_OR_CONNECTION_REJECTED' : 'SYNC_RETRY_REQUIRED',
                    'lease_until' => null, 'queued_at' => null,
                    'available_at' => $terminal ? null : now()->addSeconds(max($retryAfter, [60, 120, 300, 600][min($task->attempts - 1, 3)])),
                    'updated_at' => now(),
                ]);
            if ($permanent) {
                return true;
            }
            // Never persist provider response bodies, tokens or SQL bindings in failed_jobs.
            throw new RuntimeException('GitHub synchronization temporarily unavailable.', 503);
        }
    }

    /**
     * Reconcile an existing GitHub issue using verified remote state.
     *
     * This method runs inside the existing webhook persistence
     * transaction, after project and connection identity verification.
     *
     * It deliberately does not create issues, dispatch NLP jobs,
     * remove duplicate relationships or perform outbound API writes.
     */
    private function reconcileStatus(
        Project $project,
        GithubAppConnection $connection,
        object $delivery,
        array $remote
    ): ?int {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Status reconciliation requires a transaction.',
                500
            );
        }

        $remoteState = $remote['state'] ?? null;

        if (! in_array($remoteState, ['open', 'closed'], true)
            || ($remote['number'] ?? null) !== (int) $delivery->issue_number
            || array_key_exists('pull_request', $remote)) {
            throw new RuntimeException(
                'Invalid remote issue state or identity.',
                422
            );
        }

        // Only the verified, project-scoped stable identity is eligible.
        // Do not fall back to matching title, URL or historical repo name.
        $issue = Issue::query()
            ->where('project_id', $project->id)
            ->where('source_type', 'GITHUB')
            ->where(
                'github_repository_id',
                $connection->repository_id
            )
            ->where('github_number', $delivery->issue_number)
            ->lockForUpdate()
            ->first();

        if ($issue === null) {
            // An unimported, historical or foreign-project issue
            // must never be created by a status-only event.
            return null;
        }

        app(GithubIssueSyncStateService::class)->observe(
            $issue, $connection, $remoteState
        );

        $latest = DB::table('github_issue_status_outbox')
            ->where('issue_id', $issue->id)
            ->where('project_id', $project->id)
            ->where('connection_id', $connection->id)
            ->where('connection_generation', $connection->status_sync_generation)
            ->orderByDesc('revision')->first();

        // The latest unresolved explicit local intent takes priority.
        // The outbound worker will verify and reconcile remote state.
        if ($latest && in_array($latest->status, [
            'PENDING', 'QUEUED', 'PROCESSING', 'RETRY',
        ], true)) {
            return (int) $issue->id;
        }

        if ($issue->duplicate_of !== null
            || $issue->status === 'DUPLICATE') {
            // REQ-05 and REQ-06 remain authoritative for duplicate
            // decisions and corrections. Conflict metadata is added
            // in the later reliability phase.
            return (int) $issue->id;
        }

        $before = (string) $issue->status;

        // Use the verified current GitHub state, not the potentially
        // delayed closed/reopened action in the delivery.
        $target = match ($remoteState) {
            'closed' => match ($before) {
                'OPEN', 'IN_PROGRESS' => 'CLOSED',
                default => $before,
            },
            'open' => match ($before) {
                'CLOSED' => 'OPEN',
                'RESOLVED' => 'IN_PROGRESS',
                default => $before,
            },
        };

        if ($before === $target) {
            // Idempotent reconciliation: no redundant history records.
            return (int) $issue->id;
        }

        $issue->status = $target;
        $issue->save();

        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => null,
            'issue_id' => $issue->id,
            'action' => 'ISSUE_STATUS_CHANGED',
            'old_values' => json_encode(
                ['status' => $before],
                JSON_THROW_ON_ERROR
            ),
            'new_values' => json_encode(
                [
                    'status' => $target,
                    'actor' => 'github_app_webhook',
                    'delivery_id' => (int) $delivery->id,
                    'remote_state' => $remoteState,
                ],
                JSON_THROW_ON_ERROR
            ),
            'reason' => 'Sinkronisasi status dari GitHub',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $issue->id;
    }

    private function verify(object $task, ?object $delivery, ?GithubAppConnection $connection): void
    {
        if (! $delivery || ! $connection || ! $connection->verified_at
            || (int) $connection->id !== (int) $task->connection_id
            || (int) $connection->status_sync_generation !== (int) $task->connection_generation
            || (int) $connection->project_id !== (int) $task->project_id
            || (int) $connection->installation_id !== (int) $delivery->installation_id
            || (int) $connection->repository_id !== (int) $delivery->repository_id
            || strcasecmp($connection->repo_full_name, $task->repository) !== 0) {
            throw new RuntimeException('GitHub connection changed.', 409);
        }
    }

    private function importOpened(
        Project $project,
        GithubAppConnection $connection,
        object $delivery,
        array $remote,
        int $taskId
    ): ?int {
        $issueId = $this->import->importVerifiedWebhook(
            $project, $connection, $remote, $taskId
        );

        // Preserve the existing initial OPEN import policy.
        // Reconcile only when a genuine status event was processed
        // before the delayed opened event.
        if ($issueId !== null
            && in_array($remote['state'] ?? null, ['open', 'closed'], true)) {

            $priorStatusEvent = DB::table('github_webhook_tasks as tasks')
                ->join(
                    'github_webhook_deliveries as deliveries',
                    'deliveries.id',
                    '=',
                    'tasks.delivery_id'
                )
                ->where('tasks.project_id', $project->id)
                ->where('tasks.connection_id', $connection->id)
                ->where(
                    'tasks.connection_generation',
                    $connection->status_sync_generation
                )
                ->where('tasks.status', 'COMPLETED')
                ->where(
                    'deliveries.installation_id',
                    $connection->installation_id
                )
                ->where(
                    'deliveries.repository_id',
                    $connection->repository_id
                )
                ->where(
                    'deliveries.issue_number',
                    $delivery->issue_number
                )
                ->whereIn('deliveries.action', ['closed', 'reopened'])
                ->exists();

            if ($priorStatusEvent) {
                $this->reconcileStatus(
                    $project, $connection, $delivery, $remote
                );
            } else {
                $imported = Issue::query()
                    ->where('project_id', $project->id)
                    ->findOrFail($issueId);

                app(GithubIssueSyncStateService::class)->observe(
                    $imported,
                    $connection,
                    $remote['state']
                );
            }
        }

        return $issueId;
    }
}
