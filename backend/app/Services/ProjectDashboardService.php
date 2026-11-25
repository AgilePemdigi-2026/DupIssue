<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectDashboardService
{
    private const STATUSES = [
        'OPEN',
        'IN_PROGRESS',
        'RESOLVED',
        'CLOSED',
        'DUPLICATE',
    ];

    /**
     * @return array{
     *     totalIssueCount: int,
     *     statusCounts: array<string, int>,
     *     statusDistribution: array<int, array{status: string, count: int, percentage: float}>,
     *     duplicateIssueCount: int,
     *     duplicatePercentage: float,
     *     pendingDuplicateCount: int,
     *     topVotedIssues: Collection,
     *     averageTriageMinutes: ?float,
     *     sources: array{
     *         githubPatRepository: ?string,
     *         githubAppRepository: ?string,
     *         csvLastImportedAt: ?string,
     *         githubLastImportedAt: ?string
     *     }
     * }
     */
    public function forProject(Project $project): array
    {
        $projectId = $project->getKey();
        $statusCounts = array_fill_keys(self::STATUSES, 0);

        $groupedStatuses = Issue::query()
            ->where('project_id', $projectId)
            ->select('status', DB::raw('COUNT(*) as issue_count'))
            ->groupBy('status')
            ->pluck('issue_count', 'status');

        foreach ($groupedStatuses as $status => $count) {
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = (int) $count;
            }
        }

        $totalIssueCount = (int) $groupedStatuses->sum();
        $duplicateIssueCount = Issue::query()
            ->where('project_id', $projectId)
            ->whereNotNull('duplicate_of')
            ->count();

        $statusDistribution = [];
        foreach (self::STATUSES as $status) {
            $statusDistribution[] = [
                'status' => $status,
                'count' => $statusCounts[$status],
                'percentage' => $totalIssueCount > 0
                    ? round(($statusCounts[$status] / $totalIssueCount) * 100, 1)
                    : 0.0,
            ];
        }

        $pendingDuplicateCount = DB::table('duplicate_candidates')
            ->join('issues as source_issues', 'source_issues.id', '=', 'duplicate_candidates.issue_id')
            ->join('issues as candidate_issues', 'candidate_issues.id', '=', 'duplicate_candidates.candidate_issue_id')
            ->where('source_issues.project_id', $projectId)
            ->where('candidate_issues.project_id', $projectId)
            ->whereColumn('source_issues.id', '<>', 'candidate_issues.id')
            ->where('duplicate_candidates.decision', 'PENDING')
            ->count();

        $topVotedIssues = DB::table('issues')
            ->leftJoin('votes', 'votes.issue_id', '=', 'issues.id')
            ->where('issues.project_id', $projectId)
            ->whereNull('issues.duplicate_of')
            ->groupBy('issues.id', 'issues.title')
            ->select(
                'issues.id',
                'issues.title',
                DB::raw('COUNT(votes.id) as vote_count')
            )
            ->orderByDesc('vote_count')
            ->orderBy('issues.id')
            ->limit(5)
            ->get();

        return [
            'totalIssueCount' => $totalIssueCount,
            'statusCounts' => $statusCounts,
            'statusDistribution' => $statusDistribution,
            'duplicateIssueCount' => $duplicateIssueCount,
            'duplicatePercentage' => $totalIssueCount > 0
                ? round(($duplicateIssueCount / $totalIssueCount) * 100, 1)
                : 0.0,
            'pendingDuplicateCount' => $pendingDuplicateCount,
            'topVotedIssues' => $topVotedIssues,
            'averageTriageMinutes' => $this->averageTriageMinutes($projectId),
            'sources' => $this->sourceSummary($projectId),
        ];
    }

    private function averageTriageMinutes(int|string $projectId): ?float
    {
        $issues = Issue::query()
            ->where('project_id', $projectId)
            ->whereNotNull('created_at')
            ->pluck('created_at', 'id')
            ->map(fn ($createdAt) => CarbonImmutable::parse($createdAt));

        if ($issues->isEmpty()) {
            return null;
        }

        $logs = DB::table('activity_logs')
            ->where('project_id', $projectId)
            ->whereIn('action', [
                'DUPLICATE_REVIEW',
                'ISSUE_STATUS_CHANGED',
                'DUPLICATE_AUTO_CLOSED',
            ])
            ->whereNotNull('created_at')
            ->select([
                'id',
                'issue_id',
                'action',
                'old_values',
                'new_values',
                'created_at',
            ])
            ->lazyById(500);

        $firstTriageTimes = [];

        foreach ($logs as $log) {
            $issueId = filter_var(
                $log->issue_id,
                FILTER_VALIDATE_INT
            );

            if ($issueId === false || $issueId < 1 || ! $issues->has($issueId)) {
                continue;
            }

            $newValues = $this->decodeValues($log->new_values);

            if ($newValues === null) {
                continue;
            }

            $eventTime = CarbonImmutable::parse($log->created_at);

            if ($eventTime->lessThan($issues->get($issueId))) {
                continue;
            }

            $affectedIssues = [$issueId];

            if ($log->action === 'DUPLICATE_REVIEW') {
                $candidateId = filter_var(
                    $newValues['candidate_issue_id'] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (
                    ! in_array(
                        $newValues['decision'] ?? null,
                        ['DUPLICATE', 'NOT_DUPLICATE'],
                        true
                    )
                    || $candidateId === false
                    || $candidateId < 1
                    || $candidateId === $issueId
                    || ! $issues->has($candidateId)
                    || $eventTime->lessThan($issues->get($candidateId))
                ) {
                    continue;
                }

                if (
                    array_key_exists('issue_id', $newValues)
                    && filter_var(
                        $newValues['issue_id'],
                        FILTER_VALIDATE_INT
                    ) !== $issueId
                ) {
                    continue;
                }

                $affectedIssues[] = $candidateId;
            } else {
                $oldValues = $this->decodeValues($log->old_values);

                $oldStatus = $oldValues['status'] ?? null;
                $newStatus = $newValues['status'] ?? null;

                if (
                    ! in_array($oldStatus, self::STATUSES, true)
                    || ! in_array($newStatus, self::STATUSES, true)
                    || $oldStatus === $newStatus
                ) {
                    continue;
                }

                if (
                    $log->action === 'DUPLICATE_AUTO_CLOSED'
                    && $newStatus !== 'CLOSED'
                ) {
                    continue;
                }
            }

            foreach ($affectedIssues as $affectedId) {
                if (
                    ! isset($firstTriageTimes[$affectedId])
                    || $eventTime->lessThan($firstTriageTimes[$affectedId])
                ) {
                    $firstTriageTimes[$affectedId] = $eventTime;
                }
            }
        }

        if ($firstTriageTimes === []) {
            return null;
        }

        $totalMinutes = 0.0;

        foreach ($firstTriageTimes as $issueId => $triagedAt) {
            $totalMinutes += $issues->get($issueId)
                ->diffInSeconds($triagedAt) / 60;
        }

        return round(
            $totalMinutes / count($firstTriageTimes),
            1
        );
    }

    private function sourceSummary(int|string $projectId): array
    {
        // Read non-sensitive metadata only. Never fetch or decrypt tokens.
        $lastImports = DB::table('import_batches')
            ->where('project_id', $projectId)
            ->where('status', 'COMPLETED')
            ->groupBy('source_type')
            ->select('source_type', DB::raw('MAX(created_at) as imported_at'))
            ->pluck('imported_at', 'source_type');

        return [
            'githubPatRepository' => DB::table('github_configs')
                ->where('project_id', $projectId)
                ->value('repo_full_name'),

            'githubAppRepository' => DB::table('github_app_connections')
                ->where('project_id', $projectId)
                ->value('repo_full_name'),

            'csvLastImportedAt' => $this->formatImportTime(
                $lastImports->get('CSV')
            ),

            'githubLastImportedAt' => $this->formatImportTime(
                $lastImports->get('GITHUB')
            ),
        ];
    }

    private function formatImportTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value, config('app.timezone'))
            ->setTimezone('Asia/Jakarta')
            ->format('d/m/Y H:i') . ' WIB';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeValues(?string $json): ?array
    {
        $values = json_decode($json ?? '', true);

        return is_array($values) ? $values : null;
    }
}
