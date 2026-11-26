<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;

final class GithubIssueSyncStateService
{
    public function observe(
        object $issue,
        object $connection,
        string $remoteState
    ): void {
        if (DB::transactionLevel() < 1
            || ! in_array($remoteState, ['open', 'closed'], true)
            || (int) $issue->project_id !== (int) $connection->project_id
            || (int) $issue->github_repository_id !== (int) $connection->repository_id) {
            throw new LogicException('Invalid synchronization observation.');
        }

        DB::table('github_issue_sync_states')->updateOrInsert(
            ['issue_id' => $issue->id],
            [
                'project_id' => $issue->project_id,
                'connection_id' => $connection->id,
                'connection_generation' => $connection->status_sync_generation,
                'repository_id' => $connection->repository_id,
                'remote_state' => $remoteState,
                'confirmed_at' => now(),
            ]
        );
    }

    public function describe(Project $project, Issue $issue): ?array
    {
        if ($issue->source_type !== 'GITHUB') {
            return null;
        }

        $result = static fn (string $label, string $style, ?string $time = null): array =>
            ['label' => $label, 'style' => $style, 'confirmed_at' => $time];

        if ((int) $issue->project_id !== (int) $project->id) {
            return $result('Not Connected', 'secondary');
        }

        $connection = DB::table('github_app_connections')
            ->where('project_id', $project->id)
            ->whereNotNull('verified_at')->first();

        if (! $connection
            || ! $issue->github_repository_id
            || ! $issue->github_number
            || (int) $connection->repository_id !== (int) $issue->github_repository_id) {
            return $result('Not Connected', 'secondary');
        }

        if ($issue->duplicate_of !== null || $issue->status === 'DUPLICATE') {
            return $result('Not Managed', 'secondary');
        }

        $latest = DB::table('github_issue_status_outbox')
            ->where('issue_id', $issue->id)
            ->where('project_id', $project->id)
            ->where('connection_id', $connection->id)
            ->where('connection_generation', $connection->status_sync_generation)
            ->orderByDesc('revision')->first();

        if ($latest && in_array($latest->status, [
            'PENDING', 'QUEUED', 'PROCESSING', 'RETRY',
        ], true)) {
            return $result('Pending Synchronization', 'warning');
        }

        $observed = DB::table('github_issue_sync_states')
            ->where('issue_id', $issue->id)
            ->where('project_id', $project->id)
            ->where('connection_id', $connection->id)
            ->where('connection_generation', $connection->status_sync_generation)
            ->where('repository_id', $connection->repository_id)
            ->first();

        if ($latest && in_array($latest->status, ['FAILED', 'REJECTED'], true)) {
            return $result('Synchronization Failed', 'danger');
        }

        if (! $observed) {
            return $result('Not Yet Verified', 'secondary');
        }

        $desired = match ($issue->status) {
            'OPEN', 'IN_PROGRESS' => 'open',
            'CLOSED', 'RESOLVED' => 'closed',
            default => null,
        };

        if ($desired !== $observed->remote_state) {
            return $result('Synchronization Failed', 'danger');
        }

        return $result(
            'Synced',
            'success',
            (string) $observed->confirmed_at
        );
    }
}
