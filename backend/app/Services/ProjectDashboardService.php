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
     *     averageTriageMinutes: ?float
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
            ->where('source_issues.project_id', $projectId)
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
        ];
    }

    private function averageTriageMinutes(int|string $projectId): ?float
    {
        $logs = DB::table('activity_logs')
            ->where('project_id', $projectId)
            ->where('action', 'DUPLICATE_REVIEW')
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['issue_id', 'new_values', 'created_at']);

        $events = [];
        $issueIds = [];

        foreach ($logs as $log) {
            $newValues = $this->decodeValues($log->new_values);
            $sourceIssueId = filter_var($log->issue_id, FILTER_VALIDATE_INT);
            $candidateIssueId = filter_var(
                $newValues['candidate_issue_id'] ?? null,
                FILTER_VALIDATE_INT
            );
            if ($newValues === null
                || ! in_array($newValues['decision'] ?? null, ['DUPLICATE', 'NOT_DUPLICATE'], true)
                || $sourceIssueId === false
                || $candidateIssueId === false
                || $sourceIssueId < 1
                || $candidateIssueId < 1
                || $sourceIssueId === $candidateIssueId) {
                continue;
            }

            $eventTime = CarbonImmutable::parse($log->created_at);
            $events[] = [
                'source_issue_id' => $sourceIssueId,
                'candidate_issue_id' => $candidateIssueId,
                'created_at' => $eventTime,
            ];
            $issueIds[$sourceIssueId] = $sourceIssueId;
            $issueIds[$candidateIssueId] = $candidateIssueId;
        }

        if ($issueIds === []) {
            return null;
        }

        $issues = Issue::query()
            ->where('project_id', $projectId)
            ->whereIn('id', array_values($issueIds))
            ->get(['id', 'created_at'])
            ->keyBy('id');

        $firstTriageTimes = [];
        foreach ($events as $event) {
            $sourceIssue = $issues->get($event['source_issue_id']);
            $candidateIssue = $issues->get($event['candidate_issue_id']);
            if (! $sourceIssue?->created_at || ! $candidateIssue?->created_at) {
                continue;
            }

            $sourceCreatedAt = CarbonImmutable::parse($sourceIssue->created_at);
            $candidateCreatedAt = CarbonImmutable::parse($candidateIssue->created_at);
            if ($event['created_at']->lessThan($sourceCreatedAt)
                || $event['created_at']->lessThan($candidateCreatedAt)) {
                continue;
            }

            $firstTriageTimes[$event['source_issue_id']] ??= $event['created_at'];
            $firstTriageTimes[$event['candidate_issue_id']] ??= $event['created_at'];
        }

        $durationsInMinutes = [];
        foreach ($firstTriageTimes as $issueId => $triagedAt) {
            $issueCreatedAt = CarbonImmutable::parse($issues->get($issueId)->created_at);
            $durationsInMinutes[] = $issueCreatedAt->diffInSeconds($triagedAt) / 60;
        }

        return $durationsInMinutes === []
            ? null
            : round(array_sum($durationsInMinutes) / count($durationsInMinutes), 1);
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
