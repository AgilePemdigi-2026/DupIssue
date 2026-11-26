<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;

final class GithubIssueStatusOutboxService
{
    /**
     * Persist an explicit owner-confirmed status intent.
     *
     * The caller holds the project and issue locks inside the same
     * transaction as the local status change and its activity log.
     *
     * This method performs no external requests or queue execution.
     */
    public function record(
        Project $project,
        Issue $issue,
        string $before,
        string $target,
        int $actorId,
        int $revision
    ): ?int {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'GitHub status outbox requires a transaction.'
            );
        }

        if ((int) $project->owner_id !== $actorId
            || (int) $issue->project_id !== (int) $project->id
            || $issue->status !== $target) {
            throw new LogicException(
                'Invalid GitHub status outbox context.'
            );
        }

        if ($issue->source_type !== 'GITHUB'
            || $issue->duplicate_of !== null
            || $issue->status === 'DUPLICATE'
            || $issue->github_repository_id === null
            || (int) $issue->github_repository_id <= 0
            || $issue->github_number === null
            || (int) $issue->github_number <= 0
            || (int) $issue->github_number > 4294967295) {
            return null;
        }

        $previousState = $this->projectState($before);
        $desiredState = $this->projectState($target);

        if ($previousState === null
            || $desiredState === null
            || $previousState === $desiredState) {
            return null;
        }

        $connection = GithubAppConnection::query()
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();

        if ($connection === null
            || $connection->verified_at === null
            || (int) $connection->installation_id <= 0
            || (int) $connection->repository_id
                !== (int) $issue->github_repository_id) {
            return null;
        }

        if ($revision <= 0) {
            throw new LogicException(
                'Missing GitHub status audit revision.'
            );
        }

        $taskId = (int) DB::table(
            'github_issue_status_outbox'
        )->insertGetId([
            'project_id' => $project->id,
            'issue_id' => $issue->id,
            'connection_id' => $connection->id,
            'connection_generation' => $connection->status_sync_generation,
            'installation_id' => $connection->installation_id,
            'repository_id' => $connection->repository_id,
            'repo_full_name' => $connection->repo_full_name,
            'issue_number' => $issue->github_number,
            'requested_by' => $actorId,
            'desired_state' => $desiredState,
            'revision' => $revision,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::afterCommit(static function () use ($taskId): void {
            app(GithubIssueStatusDispatchService::class)->dispatch($taskId);
        });

        return $taskId;
    }

    private function projectState(string $status): ?string
    {
        return match ($status) {
            'OPEN', 'IN_PROGRESS' => 'open',
            'RESOLVED', 'CLOSED' => 'closed',
            default => null,
        };
    }
}
