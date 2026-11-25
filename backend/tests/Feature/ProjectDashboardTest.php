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
            ->assertSee(route('sources.index', ['project' => $project]))
            ->assertSee('Impor CSV atau Hubungkan GitHub')
            ->assertDontSee('Impor CSV dan koneksi GitHub belum tersedia');
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

    public function test_average_triage_time_uses_first_valid_review_or_status_change(): void
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

        $response->assertViewHas('stats.averageTriageMinutes', 46.7);
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
            ['status' => 'OPEN'],
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

    public function test_triage_counts_only_the_earliest_event_per_issue(): void
    {
        [$owner, $project] = $this->ownerAndProject();

        $createdAt = now()->subHours(2);

        $source = $this->createIssue(
            $project, 'Source', 'OPEN', null, $createdAt
        );

        $candidate = $this->createIssue(
            $project, 'Candidate', 'OPEN', null, $createdAt
        );

        // Insert later activity first to verify chronological selection.
        $this->addActivity(
            $project,
            $source,
            'DUPLICATE_REVIEW',
            ['decision' => 'PENDING'],
            [
                'candidate_issue_id' => $candidate->id,
                'decision' => 'NOT_DUPLICATE',
            ],
            $createdAt->copy()->addMinutes(30)
        );

        $this->addActivity(
            $project,
            $source,
            'ISSUE_STATUS_CHANGED',
            ['status' => 'OPEN'],
            ['status' => 'IN_PROGRESS'],
            $createdAt->copy()->addMinutes(10)
        );

        $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]))
            ->assertOk()
            ->assertViewHas('stats.averageTriageMinutes', 20.0);
    }

    public function test_status_only_and_auto_close_events_are_valid_triage(): void
    {
        [$owner, $project] = $this->ownerAndProject();

        $createdAt = now()->subHours(2);

        $parent = $this->createIssue(
            $project, 'Parent', 'RESOLVED', null, $createdAt
        );

        $child = $this->createIssue(
            $project, 'Child', 'CLOSED', $parent->id, $createdAt
        );

        $this->addActivity(
            $project,
            $parent,
            'ISSUE_STATUS_CHANGED',
            ['status' => 'IN_PROGRESS'],
            ['status' => 'RESOLVED'],
            $createdAt->copy()->addMinutes(20)
        );

        $this->addActivity(
            $project,
            $child,
            'DUPLICATE_AUTO_CLOSED',
            ['status' => 'DUPLICATE', 'duplicate_of' => $parent->id],
            ['status' => 'CLOSED', 'duplicate_of' => $parent->id],
            $createdAt->copy()->addMinutes(20)
        );

        $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]))
            ->assertOk()
            ->assertViewHas('stats.averageTriageMinutes', 20.0);
    }

    public function test_pending_candidates_exclude_cross_project_pairs(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        [, $foreignProject] = $this->ownerAndProject();

        $source = $this->createIssue($project, 'Source');
        $local = $this->createIssue($project, 'Local');
        $foreign = $this->createIssue($foreignProject, 'Foreign');

        // Create a legitimate candidate through the existing model.
        DuplicateCandidate::create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $local->id,
            'similarity_score' => 0.9,
        ]);

        // Simulate anomalous legacy data in the isolated test database.
        // Bypass Eloquent validation only for this regression fixture.
        DB::table('duplicate_candidates')->insert([
            'issue_id' => $source->id,
            'candidate_issue_id' => $foreign->id,
            'similarity_score' => 0.9,
            'decision' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]))
            ->assertOk()
            ->assertViewHas('stats.pendingDuplicateCount', 1);
    }

    public function test_model_rejects_cross_project_duplicate_candidates(): void
    {
        [, $project] = $this->ownerAndProject();
        [, $foreignProject] = $this->ownerAndProject();

        $source = $this->createIssue($project, 'Source');
        $foreign = $this->createIssue($foreignProject, 'Foreign');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Duplicate candidates must belong to the same project.'
        );

        DuplicateCandidate::create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $foreign->id,
            'similarity_score' => 0.9,
        ]);
    }

    public function test_source_summary_is_project_scoped_and_never_exposes_credentials(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        [$foreignOwner, $foreignProject] = $this->ownerAndProject();

        DB::table('github_configs')->insert([
            [
                'project_id' => $project->id,
                'repo_full_name' => 'local/project',
                'pat_encrypted' => 'synthetic-local-secret',
            ],
            [
                'project_id' => $foreignProject->id,
                'repo_full_name' => 'foreign/private-project',
                'pat_encrypted' => 'synthetic-foreign-secret',
            ],
        ]);

        DB::table('github_app_connections')->insert([
            'project_id' => $project->id,
            'installation_id' => 100,
            'repository_id' => 200,
            'repo_full_name' => 'local/project',
            'verified_github_user_id' => 300,
            'verified_at' => now(),
        ]);

        foreach ([
            [$project, $owner, 'CSV', 'COMPLETED'],
            [$project, $owner, 'GITHUB', 'COMPLETED'],
            [$project, $owner, 'CSV', 'FAILED'],
            [$foreignProject, $foreignOwner, 'CSV', 'COMPLETED'],
        ] as [$batchProject, $actor, $source, $status]) {
            DB::table('import_batches')->insert([
                'project_id' => $batchProject->id,
                'actor_id' => $actor->id,
                'source_type' => $source,
                'status' => $status,
                'created_at' => $status === 'FAILED'
                    ? '2026-11-21 02:00:00'
                    : '2026-11-20 02:00:00',
            ]);
        }

        $response = $this->actingAs($owner)
            ->get(route('projects.dashboard', ['project' => $project]));

        $response->assertOk()
            ->assertSee('local/project')
            ->assertDontSee('foreign/private-project')
            ->assertDontSee('synthetic-local-secret')
            ->assertDontSee('synthetic-foreign-secret')
            ->assertViewHas('stats.sources.githubPatRepository', 'local/project')
            ->assertViewHas('stats.sources.githubAppRepository', 'local/project')
            ->assertViewHas('stats.sources.csvLastImportedAt', '20/11/2026 09:00 WIB')
            ->assertViewHas('stats.sources.githubLastImportedAt', '20/11/2026 09:00 WIB');
    }

    public function test_dashboard_loads_5000_issues_in_under_three_seconds(): void
    {
        [$owner, $project] = $this->ownerAndProject();
        $now = now()->subHour();
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

        // All fixtures are generated inside guarded SQLite memory.
        $issues = DB::table('issues')
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->get(['id', 'status'])
            ->groupBy('status');

        $open = $issues->get('OPEN')->values();
        $inProgress = $issues->get('IN_PROGRESS')->values();
        $resolved = $issues->get('RESOLVED')->values();
        $closed = $issues->get('CLOSED')->values();
        $duplicates = $issues->get('DUPLICATE')->values();

        $parentId = $open->first()->id;

        DB::table('issues')
            ->where('project_id', $project->id)
            ->where('status', 'DUPLICATE')
            ->update(['duplicate_of' => $parentId]);

        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
        ]);

        // 1,000 actual votes from an active project member.
        $votes = [];

        foreach ($open as $issue) {
            $votes[] = [
                'issue_id' => $issue->id,
                'voter_id' => $reporter->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($votes, 200) as $chunk) {
            DB::table('votes')->insert($chunk);
        }

        DB::table('issues')
            ->where('project_id', $project->id)
            ->where('status', 'OPEN')
            ->update(['vote_count' => 1]);

        // 500 pending candidate pairs in the same project.
        $candidates = [];

        for ($i = 0; $i < 500; $i++) {
            $candidates[] = [
                'issue_id' => $open[$i]->id,
                'candidate_issue_id' => $inProgress[$i]->id,
                'similarity_score' => 0.85,
                'decision' => 'PENDING',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($candidates, 200) as $chunk) {
            DB::table('duplicate_candidates')->insert($chunk);
        }

        // 4,000 audit events, all occurring 20 minutes after creation.
        $eventTime = $now->copy()->addMinutes(20);
        $logs = [];

        foreach ([
            ['OPEN', 'IN_PROGRESS', $inProgress],
            ['IN_PROGRESS', 'RESOLVED', $resolved],
            ['RESOLVED', 'CLOSED', $closed],
        ] as [$oldStatus, $newStatus, $group]) {
            foreach ($group as $issue) {
                $logs[] = [
                    'project_id' => $project->id,
                    'user_id' => $owner->id,
                    'issue_id' => $issue->id,
                    'action' => 'ISSUE_STATUS_CHANGED',
                    'old_values' => json_encode([
                        'status' => $oldStatus,
                    ], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode([
                        'status' => $newStatus,
                    ], JSON_THROW_ON_ERROR),
                    'reason' => null,
                    'created_at' => $eventTime,
                    'updated_at' => $eventTime,
                ];
            }
        }

        foreach ($duplicates as $issue) {
            $logs[] = [
                'project_id' => $project->id,
                'user_id' => $owner->id,
                'issue_id' => $issue->id,
                'action' => 'DUPLICATE_REVIEW',
                'old_values' => json_encode([
                    'decision' => 'PENDING',
                ], JSON_THROW_ON_ERROR),
                'new_values' => json_encode([
                    'issue_id' => $issue->id,
                    'candidate_issue_id' => $parentId,
                    'decision' => 'DUPLICATE',
                ], JSON_THROW_ON_ERROR),
                'reason' => null,
                'created_at' => $eventTime,
                'updated_at' => $eventTime,
            ];
        }

        foreach (array_chunk($logs, 200) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        // Confirm fixture size before measuring dashboard response.
        $this->assertCount(1000, $votes);
        $this->assertCount(500, $candidates);
        $this->assertCount(4000, $logs);

        $this->actingAs($owner);

        $startedAt = hrtime(true);

        $response = $this->get(
            route('projects.dashboard', ['project' => $project])
        );

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        $response->assertOk()
            ->assertViewHas('stats.totalIssueCount', 5000)
            ->assertViewHas('stats.duplicateIssueCount', 1000)
            ->assertViewHas('stats.duplicatePercentage', 20.0)
            ->assertViewHas('stats.pendingDuplicateCount', 500)
            ->assertViewHas('stats.averageTriageMinutes', 20.0);

        $response->assertViewHas(
            'stats.topVotedIssues',
            fn ($items): bool => $items->count() === 5
                && $items->every(
                    fn ($issue): bool => (int) $issue->vote_count === 1
                )
        );

        fwrite(STDOUT, sprintf(
            "\n[F13 PERFORMANCE] SQLite | 5000 issues | 1000 votes | 500 pending candidates | 4000 activity logs | response: %.3f seconds\n",
            $elapsedSeconds
        ));

        $this->assertLessThan(
            3.0,
            $elapsedSeconds,
            sprintf(
                'Dashboard took %.3f seconds with representative data.',
                $elapsedSeconds
            )
        );
    }
}
