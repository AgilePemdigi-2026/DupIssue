<?php

namespace Tests\Feature;

use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\IssueVote;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function ownerAndProject(): array
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        return [$owner, $project];
    }

    private function createIssue(
        Project $project,
        string $title,
        string $status = 'OPEN',
        ?int $duplicateOf = null,
        mixed $createdAt = null,
        ?int $voteCount = 0
    ): Issue {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => null,
            'title' => $title,
            'description' => 'Dashboard test issue',
            'source_type' => 'FORM',
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'vote_count' => $voteCount,
            'duplicate_of' => $duplicateOf,
            'created_at' => $createdAt ?? now()->subDay(),
            'updated_at' => now(),
        ]);
    }

    private function addActivity(
        Project $project,
        Issue $issue,
        string $action,
        array $oldValues,
        array $newValues,
        mixed $createdAt
    ): void {
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => null,
            'issue_id' => $issue->id,
            'action' => $action,
            'old_values' => json_encode($oldValues, JSON_THROW_ON_ERROR),
            'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR),
            'reason' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_empty_project_shows_zero_statistics_and_available_navigation(): void
    {
        [$owner, $project] = $this->ownerAndProject();

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertOk()
            ->assertSee('Belum ada issue pada project ini')
            ->assertSee('Belum ada data')
            ->assertSee(route('issues.index', ['project' => $project]))
            ->assertSee(route('projects.duplicates.index', ['project' => $project]))
            ->assertSee('Impor CSV dan koneksi GitHub belum tersedia');
        $response->assertViewHas('stats', function (array $stats): bool {
            return $stats['totalIssueCount'] === 0
                && $stats['duplicatePercentage'] === 0.0
                && $stats['averageTriageMinutes'] === null
                && collect($stats['statusDistribution'])->every(
                    fn (array $status): bool => $status['percentage'] === 0.0
                );
        });
    }

    public function test_summary_cards_count_each_of_the_four_required_statuses(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $parent = $this->createIssue($project, 'Parent issue', 'RESOLVED');
        $this->createIssue($project, 'Open issue');
        $this->createIssue($project, 'In progress issue', 'IN_PROGRESS');
        $this->createIssue($project, 'Resolved issue', 'RESOLVED');
        $this->createIssue($project, 'Closed issue', 'CLOSED');
        $this->createIssue($project, 'Closed duplicate', 'CLOSED', $parent->id);

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertOk();
        $response->assertViewHas('stats', function (array $stats): bool {
            return $stats['totalIssueCount'] === 6
                && $stats['statusCounts'] === [
                    'OPEN' => 1,
                    'IN_PROGRESS' => 1,
                    'RESOLVED' => 2,
                    'CLOSED' => 2,
                    'DUPLICATE' => 0,
                ];
        });
    }

    public function test_distribution_contains_all_five_statuses_and_uses_project_total(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $parent = $this->createIssue($project, 'Parent', 'OPEN');
        $this->createIssue($project, 'In progress', 'IN_PROGRESS');
        $this->createIssue($project, 'Resolved', 'RESOLVED');
        $this->createIssue($project, 'Closed', 'CLOSED');
        $this->createIssue($project, 'Linked duplicate', 'CLOSED', $parent->id);
        $this->createIssue($project, 'Unlinked duplicate status', 'DUPLICATE');

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertOk();
        $response->assertViewHas('stats', function (array $stats): bool {
            $distribution = collect($stats['statusDistribution'])->keyBy('status');

            return $distribution->keys()->all() === [
                'OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE',
            ]
                && $distribution['OPEN']['percentage'] === 16.7
                && $distribution['CLOSED']['percentage'] === 33.3
                && $distribution['DUPLICATE']['count'] === 1
                && $distribution['DUPLICATE']['percentage'] === 16.7;
        });
    }

    public function test_duplicate_count_uses_duplicate_relationship_and_rounds_percentage(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $parent = $this->createIssue($project, 'Parent issue');
        $this->createIssue($project, 'Closed child', 'CLOSED', $parent->id);
        $this->createIssue($project, 'Duplicate status without relation', 'DUPLICATE');
        $this->createIssue($project, 'Regular issue');

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertViewHas('stats', function (array $stats): bool {
            return $stats['duplicateIssueCount'] === 1
                && $stats['duplicatePercentage'] === 25.0;
        });
    }

    public function test_pending_duplicate_validation_counts_candidate_pairs_not_issues(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $source = $this->createIssue($project, 'Source issue');
        $candidateOne = $this->createIssue($project, 'Candidate one');
        $candidateTwo = $this->createIssue($project, 'Candidate two');

        DuplicateCandidate::create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $candidateOne->id,
            'similarity_score' => 0.8,
            'decision' => 'PENDING',
        ]);
        DuplicateCandidate::create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $candidateTwo->id,
            'similarity_score' => 0.7,
            'decision' => 'PENDING',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertViewHas('stats.pendingDuplicateCount', 2);
    }

    public function test_top_five_uses_actual_votes_excludes_duplicates_and_orders_ties_by_issue_id(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        [, $otherProject] = $this->ownerAndProject();
        $issues = [];
        foreach (range(1, 6) as $number) {
            $issues[$number] = $this->createIssue($project, "Issue {$number}");
        }
        $duplicate = $this->createIssue($project, 'Duplicate with most votes', 'CLOSED', $issues[1]->id);
        $foreignIssue = $this->createIssue($otherProject, 'Foreign project issue');

        $votesPerIssue = [1 => 4, 2 => 3, 3 => 3, 4 => 2, 5 => 2, 6 => 1, 7 => 10];
        foreach ($votesPerIssue as $issueNumber => $voteCount) {
            $issue = $issueNumber === 7 ? $duplicate : $issues[$issueNumber];
            for ($vote = 0; $vote < $voteCount; $vote++) {
                $reporter = User::factory()->create(['role' => 'REPORTER']);
                IssueVote::create([
                    'issue_id' => $issue->id,
                    'voter_id' => $reporter->id,
                ]);
            }
        }
        for ($vote = 0; $vote < 20; $vote++) {
            $reporter = User::factory()->create(['role' => 'REPORTER']);
            IssueVote::create([
                'issue_id' => $foreignIssue->id,
                'voter_id' => $reporter->id,
            ]);
        }

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertViewHas('stats', function (array $stats) use ($issues, $duplicate): bool {
            return $stats['topVotedIssues']->pluck('id')->all() === [
                $issues[1]->id,
                $issues[2]->id,
                $issues[3]->id,
                $issues[4]->id,
                $issues[5]->id,
            ]
                && $stats['topVotedIssues']->pluck('vote_count')->all() === [4, 3, 3, 2, 2]
                && ! $stats['topVotedIssues']->contains('id', $duplicate->id);
        });
    }

    public function test_average_triage_time_uses_first_valid_duplicate_decision_for_both_issues(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $source = $this->createIssue($project, 'Source', 'OPEN', null, now()->subMinutes(100));
        $candidate = $this->createIssue($project, 'Candidate', 'OPEN', null, now()->subMinutes(80));
        $statusIssue = $this->createIssue($project, 'Status issue', 'OPEN', null, now()->subMinutes(80));

        $this->addActivity(
            $project,
            $source,
            'DUPLICATE_REVIEW',
            ['decision' => 'PENDING'],
            [
                'candidate_issue_id' => $candidate->id,
                'decision' => 'NOT_DUPLICATE',
            ],
            now()->subMinutes(50)
        );
        $this->addActivity(
            $project,
            $source,
            'DUPLICATE_REVIEW',
            ['decision' => 'NOT_DUPLICATE'],
            [
                'candidate_issue_id' => $candidate->id,
                'decision' => 'DUPLICATE',
            ],
            now()->subMinutes(10)
        );
        $this->addActivity(
            $project,
            $statusIssue,
            'ISSUE_STATUS_CHANGED',
            ['status' => 'OPEN'],
            ['status' => 'IN_PROGRESS'],
            now()->subMinutes(20)
        );
        $this->addActivity(
            $project,
            $statusIssue,
            'ISSUE_STATUS_CHANGED',
            ['status' => 'IN_PROGRESS'],
            ['status' => 'RESOLVED'],
            now()
        );

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertViewHas('stats.averageTriageMinutes', 40.0);
    }

    public function test_average_triage_time_is_unavailable_without_valid_activity_history(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $issue = $this->createIssue($project, 'Untouched issue', 'OPEN', null, now()->subDays(5));
        $candidate = $this->createIssue($project, 'Candidate created later', 'OPEN', null, now()->subHours(12));

        $this->addActivity(
            $project,
            $issue,
            'ISSUE_STATUS_CHANGED',
            ['status' => 'OPEN'],
            ['status' => 'IN_PROGRESS'],
            now()->subDay()
        );
        $this->addActivity(
            $project,
            $issue,
            'DUPLICATE_REVIEW',
            ['decision' => 'PENDING'],
            ['candidate_issue_id' => $issue->id, 'decision' => 'DUPLICATE'],
            now()->subDay()
        );
        $this->addActivity(
            $project,
            $issue,
            'DUPLICATE_REVIEW',
            ['decision' => 'PENDING'],
            ['candidate_issue_id' => $candidate->id, 'decision' => 'NOT_DUPLICATE'],
            now()->subDay()
        );

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertSee('Belum ada data')
            ->assertViewHas('stats.averageTriageMinutes', null);
    }

    public function test_duplicate_review_for_issue_from_another_project_is_not_triage_data(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        [, $otherProject] = $this->ownerAndProject();
        $source = $this->createIssue($project, 'Project source', 'OPEN', null, now()->subDays(2));
        $foreignCandidate = $this->createIssue(
            $otherProject,
            'Foreign candidate',
            'OPEN',
            null,
            now()->subDays(2)
        );

        $this->addActivity(
            $project,
            $source,
            'DUPLICATE_REVIEW',
            ['decision' => 'PENDING'],
            [
                'candidate_issue_id' => $foreignCandidate->id,
                'decision' => 'DUPLICATE',
            ],
            now()->subDay()
        );

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertViewHas('stats.averageTriageMinutes', null);
    }

    public function test_maintainer_owner_can_access_project_dashboard(): void
    {
        [$owner, $project] = $this->ownerAndProject();

        $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]))
            ->assertOk();
    }

    public function test_reporter_cannot_access_project_dashboard(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
        ]);

        $this->actingAs($reporter)
            ->get(route('projects.dashboard', ['project' => $project]))
            ->assertForbidden();
    }

    public function test_non_owner_maintainer_cannot_access_project_dashboard(): void
    {
        [, $project] = $this->ownerAndProject();
        $nonOwner = User::factory()->create(['role' => 'MAINTAINER']);

        $this->actingAs($nonOwner)
            ->get(route('projects.dashboard', ['project' => $project]))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_project_dashboard(): void
    {
        [, $project] = $this->ownerAndProject();

        $this->get(route('projects.dashboard', ['project' => $project]))
            ->assertRedirect('/login');
    }

    public function test_project_page_links_to_dashboard_only_for_the_owner(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
        ]);
        $dashboardUrl = route('projects.dashboard', ['project' => $project]);

        $this->actingAs($owner)
            ->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()
            ->assertSee($dashboardUrl);

        $this->actingAs($reporter)
            ->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()
            ->assertDontSee($dashboardUrl);
    }

    public function test_dashboard_data_and_links_are_scoped_to_the_current_project(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        [, $otherProject] = $this->ownerAndProject();
        $this->createIssue($project, 'Visible project issue');
        $foreignIssue = $this->createIssue($otherProject, 'Foreign project secret', 'RESOLVED');

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertOk()
            ->assertSee('Visible project issue')
            ->assertDontSee('Foreign project secret')
            ->assertViewHas('stats.totalIssueCount', 1);
        $response->assertViewHas('stats.topVotedIssues', function ($issues) use ($foreignIssue): bool {
            return ! $issues->contains('id', $foreignIssue->id);
        });
    }

    public function test_dashboard_loads_5000_issues_in_under_three_seconds(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $now = now();
        $rows = [];
        for ($number = 1; $number <= 5000; $number++) {
            $rows[] = [
                'project_id' => $project->id,
                'author_id' => null,
                'title' => "Performance issue {$number}",
                'description' => 'Dashboard performance fixture',
                'source_type' => 'FORM',
                'status' => ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED', 'DUPLICATE'][$number % 5],
                'detection_status' => 'COMPLETED',
                'vote_count' => 0,
                'duplicate_of' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('issues')->insert($chunk);
        }

        $startedAt = microtime(true);
        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));
        $elapsedSeconds = microtime(true) - $startedAt;

        $response->assertOk();
        $this->assertLessThan(
            3.0,
            $elapsedSeconds,
            sprintf('Dashboard took %.3f seconds for 5,000 issues.', $elapsedSeconds)
        );
    }
}
