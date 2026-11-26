<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class GithubIssueStatusOutboundService
{
    private const TABLE = 'github_issue_status_outbox';

    private const TERMINAL = [
        'COMPLETED', 'REJECTED', 'SUPERSEDED', 'FAILED',
    ];

    private const MAX_ATTEMPTS = 5;

    private const LEASE_SECONDS = 120;

    public function __construct(
        private readonly GithubAppInstallationTokenService $authentication,
        private readonly GithubApiClient $api
    ) {}

    /**
     * True means terminal; false means retry or another active claim.
     * No database transaction is held during external HTTP.
     */
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
        $hint = DB::table(self::TABLE)->find($taskId);

        if ($hint === null) {
            return true;
        }

        $claim = DB::transaction(function () use ($hint, $taskId): array {
            $project = Project::query()
                ->whereKey($hint->project_id)
                ->lockForUpdate()->first();

            $task = DB::table(self::TABLE)
                ->where('id', $taskId)->lockForUpdate()->first();

            if ($task === null
                || in_array($task->status, self::TERMINAL, true)) {
                return ['done' => true];
            }

            if ($task->status === 'PROCESSING'
                && $this->leaseActive($task)) {
                return ['done' => false];
            }

            $invalid = $this->ineligible($task, $project);

            if ($invalid !== null) {
                $this->finishRow(
                    $task,
                    $invalid === 'NEWER_INTENT'
                        ? 'SUPERSEDED' : 'REJECTED',
                    $invalid
                );

                return ['done' => true];
            }

            if ($task->available_at !== null
                && Carbon::parse($task->available_at)->isFuture()) {
                return ['done' => false];
            }

            // Do not overlap another unexpired write for the same issue.
            if (DB::table(self::TABLE)
                ->where('issue_id', $task->issue_id)
                ->where('id', '!=', $task->id)
                ->where('status', 'PROCESSING')
                ->where('lease_until', '>', now())
                ->exists()) {
                return ['done' => false];
            }

            if ((int) $task->attempts >= self::MAX_ATTEMPTS) {
                $this->finishRow(
                    $task, 'FAILED', 'ATTEMPTS_EXHAUSTED'
                );
                return ['done' => true];
            }

            $task->claim_token = (string) Str::uuid();
            $task->attempts = (int) $task->attempts + 1;
            $task->lease_until = now()->addSeconds(self::LEASE_SECONDS);
            $task->status = 'PROCESSING';

            DB::table(self::TABLE)->where('id', $task->id)->update([
                'status' => $task->status,
                'claim_token' => $task->claim_token,
                'attempts' => $task->attempts,
                'lease_until' => $task->lease_until,
                'available_at' => null,
                'queued_at' => null,
                'updated_at' => now(),
            ]);

            return ['task' => $task];
        }, 3);

        if (! isset($claim['task'])) {
            return $claim['done'];
        }

        $task = $claim['task'];

        $guard = function () use ($task): void {
            GithubStatusSyncMutex::assertHeld();
            $valid = DB::transaction(function () use ($task): bool {
                $project = Project::query()
                    ->whereKey($task->project_id)
                    ->lockForUpdate()->first();

                $current = DB::table(self::TABLE)
                    ->where('id', $task->id)
                    ->lockForUpdate()->first();

                return $this->ownsClaim($current, $task)
                    && $this->ineligible($current, $project) === null;
            }, 3);

            if (! $valid) {
                throw new RuntimeException(
                    'Outbound issue status context changed.', 409
                );
            }
        };

        $error = null;
        $retryAfter = 0;

        try {
            $guard();

            $token = $this->authentication
                ->createForRepositoryWithWriteAccess(
                    (int) $task->installation_id,
                    (int) $task->repository_id
                );

            $guard();

            $this->api->updateVerifiedIssueState(
                $task->repo_full_name,
                (int) $task->repository_id,
                (int) $task->issue_number,
                $task->desired_state,
                $token,
                $guard
            );
        } catch (RuntimeException $exception) {
            // Persist only safe error classification, not response details.
            $error = (int) $exception->getCode();

            if ($exception instanceof GithubStatusRateLimitException) {
                $retryAfter = $exception->retryAfterSeconds;
            }
        }

        return DB::transaction(function () use ($task, $error, $retryAfter): bool {
            $project = Project::query()
                ->whereKey($task->project_id)
                ->lockForUpdate()->first();

            $current = DB::table(self::TABLE)
                ->where('id', $task->id)
                ->lockForUpdate()->first();

            if (! $this->ownsClaim($current, $task)) {
                return false;
            }

            $invalid = $this->ineligible($current, $project);

            if ($invalid !== null) {
                $this->finishRow(
                    $current,
                    $invalid === 'NEWER_INTENT'
                        ? 'SUPERSEDED' : 'REJECTED',
                    $invalid
                );

                return true;
            }

            if ($error === null) {
                GithubStatusSyncMutex::assertHeld();

                $issue = DB::table('issues')->find($current->issue_id);
                $connection = DB::table('github_app_connections')->find(
                    $current->connection_id
                );

                app(GithubIssueSyncStateService::class)->observe(
                    $issue, $connection, $current->desired_state
                );

                DB::table('github_app_connections')
                    ->where('id', $connection->id)
                    ->update(['last_synced_at' => now(), 'updated_at' => now()]);

                $this->finishRow($current, 'COMPLETED', null);
                return true;
            }

            if (in_array($error, [400, 403, 404, 409, 410, 422], true)) {
                $this->finishRow(
                    $current, 'REJECTED', 'GITHUB_'.$error
                );

                return true;
            }

            if ((int) $current->attempts >= self::MAX_ATTEMPTS) {
                $this->finishRow(
                    $current, 'FAILED', 'ATTEMPTS_EXHAUSTED'
                );

                return true;
            }

            $delay = match ((int) $current->attempts) {
                1 => 60,
                2 => 120,
                3 => 300,
                default => 600,
            };

            DB::table(self::TABLE)->where('id', $current->id)->update([
                'status' => 'RETRY',
                'claim_token' => null,
                'lease_until' => null,
                'available_at' => now()->addSeconds(max($delay, $retryAfter)),
                'last_error_code' => $error > 0
                    ? 'GITHUB_'.$error : 'GITHUB_UNAVAILABLE',
                'updated_at' => now(),
            ]);

            return false;
        }, 3);
    }

    private function leaseActive(object $task): bool
    {
        return $task->lease_until !== null
            && Carbon::parse($task->lease_until)->isFuture();
    }

    private function ownsClaim(?object $current, object $claimed): bool
    {
        return $current !== null
            && $current->status === 'PROCESSING'
            && $current->claim_token === $claimed->claim_token
            && $this->leaseActive($current);
    }

    private function ineligible(
        object $task,
        ?Project $project
    ): ?string {
        if (DB::table(self::TABLE)
            ->where('issue_id', $task->issue_id)
            ->where('revision', '>', $task->revision)
            ->exists()) {
            return 'NEWER_INTENT';
        }

        if ($project === null
            || (int) $project->owner_id !== (int) $task->requested_by
            || ! DB::table('users')
                ->where('id', $task->requested_by)
                ->where('role', 'MAINTAINER')
                ->exists()) {
            return 'OWNER_CHANGED';
        }

        $connection = DB::table('github_app_connections')
            ->where('project_id', $task->project_id)->first();

        if ($connection === null
            || $connection->verified_at === null
            || (int) $connection->id !== (int) $task->connection_id
            || (int) $connection->status_sync_generation !== (int) $task->connection_generation
            || (int) $connection->installation_id
                !== (int) $task->installation_id
            || (int) $connection->repository_id
                !== (int) $task->repository_id
            || $connection->repo_full_name !== $task->repo_full_name) {
            return 'CONNECTION_CHANGED';
        }

        if (DB::table('github_app_connections')
            ->where('repository_id', $task->repository_id)
            ->where('project_id', '!=', $task->project_id)
            ->whereNotNull('verified_at')
            ->exists()) {
            return 'AMBIGUOUS_REPOSITORY';
        }

        $issue = DB::table('issues')
            ->where('id', $task->issue_id)
            ->where('project_id', $task->project_id)
            ->first();

        if ($issue === null
            || $issue->source_type !== 'GITHUB'
            || $issue->duplicate_of !== null
            || (int) $issue->github_repository_id
                !== (int) $task->repository_id
            || (int) $issue->github_number !== (int) $task->issue_number) {
            return 'ISSUE_IDENTITY_CHANGED';
        }

        if (DB::table('activity_logs')
            ->where('issue_id', $task->issue_id)
            ->where('project_id', $task->project_id)
            ->where('id', '>', $task->revision)
            ->whereIn('action', [
                'DUPLICATE_REVIEW', 'DUPLICATE_CORRECTED', 'DUPLICATE_AUTO_CLOSED',
            ])->exists()) {
            return 'DUPLICATE_RELATION_CHANGED';
        }

        $desired = match ($issue->status) {
            'OPEN', 'IN_PROGRESS' => 'open',
            'RESOLVED', 'CLOSED' => 'closed',
            default => null,
        };

        if ($desired === null || $desired !== $task->desired_state) {
            return 'LOCAL_STATUS_CHANGED';
        }

        return null;
    }

    private function finishRow(
        object $task,
        string $status,
        ?string $error
    ): void {
        DB::table(self::TABLE)->where('id', $task->id)->update([
            'status' => $status,
            'claim_token' => null,
            'lease_until' => null,
            'available_at' => null,
            'last_error_code' => $error,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
