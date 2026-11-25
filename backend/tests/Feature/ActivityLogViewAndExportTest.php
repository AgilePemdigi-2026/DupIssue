<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogViewAndExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_activity_index_and_export(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->get(route('projects.activity.index', ['project' => $project]))
            ->assertRedirect(route('login'));

        $this->get(route('projects.activity.export', ['project' => $project]))
            ->assertRedirect(route('login'));
    }

    public function test_reporter_is_rejected_with_403(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $reporter = User::factory()->create(['role' => 'REPORTER']);

        $this->actingAs($reporter)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertForbidden();

        $this->actingAs($reporter)
            ->get(route('projects.activity.export', ['project' => $project]))
            ->assertForbidden();
    }

    public function test_maintainer_non_owner_is_rejected_with_403(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $otherMaintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($otherMaintainer)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertForbidden();

        $this->actingAs($otherMaintainer)
            ->get(route('projects.activity.export', ['project' => $project]))
            ->assertForbidden();
    }

    public function test_maintainer_owner_of_another_project_is_rejected_with_403(): void
    {
        $ownerA = User::factory()->create(['role' => 'MAINTAINER']);
        $ownerB = User::factory()->create(['role' => 'MAINTAINER']);

        $projectA = Project::factory()->create(['owner_id' => $ownerA->id]);
        $projectB = Project::factory()->create(['owner_id' => $ownerB->id]);

        // Owner B attempts to access Project A
        $this->actingAs($ownerB)
            ->get(route('projects.activity.index', ['project' => $projectA]))
            ->assertForbidden();

        $this->actingAs($ownerB)
            ->get(route('projects.activity.export', ['project' => $projectA]))
            ->assertForbidden();
    }

    public function test_maintainer_owner_can_access_activity_index_and_project_data_is_isolated(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        $otherProject = Project::factory()->create();

        // Activity for own project
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'CREATE_PROJECT',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Activity for another project
        \DB::table('activity_logs')->insert([
            'project_id' => $otherProject->id,
            'user_id' => $otherProject->owner_id,
            'action' => 'UPDATE_PROJECT',
            'reason' => 'Secret foreign update',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk()
            ->assertSee('Riwayat &amp; Log Aktivitas', false)
            ->assertSee('CREATE_PROJECT')
            ->assertDontSee('Secret foreign update');
    }

    public function test_category_filter_and_keyword_filter(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \DB::table('activity_logs')->insert([
            [
                'project_id' => $project->id,
                'user_id' => $owner->id,
                'action' => 'INVITE_REPORTER',
                'reason' => 'Undangan anggota tim frontend',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project->id,
                'user_id' => $owner->id,
                'action' => 'ISSUE_STATUS_CHANGED',
                'reason' => 'Perubahan status issue utama',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Filter category MEMBERSHIP
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'category' => 'MEMBERSHIP']))
            ->assertOk()
            ->assertSee('INVITE_REPORTER')
            ->assertDontSee('ISSUE_STATUS_CHANGED');

        // Filter keyword on reason
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'keyword' => 'frontend']))
            ->assertOk()
            ->assertSee('INVITE_REPORTER')
            ->assertDontSee('ISSUE_STATUS_CHANGED');
    }

    public function test_wib_midnight_boundary_filter_half_open(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // Boundary test around 2026-11-18 in WIB (UTC+7):
        // 2026-11-18 00:00:00 WIB = 2026-11-17 17:00:00 UTC
        // 2026-11-18 23:59:59 WIB = 2026-11-18 16:59:59 UTC
        // 2026-11-19 00:00:00 WIB = 2026-11-18 17:00:00 UTC

        // Log 1: 1 sec before start of 2026-11-18 WIB (2026-11-17 23:59:59 WIB = 2026-11-17 16:59:59 UTC) -> EXCLUDED
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'action' => 'LOG_BEFORE_MIDNIGHT',
            'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-17 16:59:59', 'UTC'),
            'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-17 16:59:59', 'UTC'),
        ]);

        // Log 2: Exact start of 2026-11-18 WIB (2026-11-18 00:00:00 WIB = 2026-11-17 17:00:00 UTC) -> INCLUDED
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'action' => 'LOG_EXACT_START_MIDNIGHT',
            'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-17 17:00:00', 'UTC'),
            'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-17 17:00:00', 'UTC'),
        ]);

        // Log 3: 1 sec before end of 2026-11-18 WIB (2026-11-18 23:59:59 WIB = 2026-11-18 16:59:59 UTC) -> INCLUDED
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'action' => 'LOG_EXACT_END_OF_DAY',
            'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 16:59:59', 'UTC'),
            'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 16:59:59', 'UTC'),
        ]);

        // Log 4: Exact start of next day (2026-11-19 00:00:00 WIB = 2026-11-18 17:00:00 UTC) -> EXCLUDED
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'action' => 'LOG_NEXT_DAY_START',
            'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 17:00:00', 'UTC'),
            'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 17:00:00', 'UTC'),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.activity.index', [
                'project' => $project,
                'date_from' => '2026-11-18',
                'date_to' => '2026-11-18',
            ]))
            ->assertOk()
            ->assertSee('LOG_EXACT_START_MIDNIGHT')
            ->assertSee('LOG_EXACT_END_OF_DAY')
            ->assertDontSee('LOG_BEFORE_MIDNIGHT')
            ->assertDontSee('LOG_NEXT_DAY_START');
    }

    public function test_reduced_duplicates_calculation_with_re_decision_and_correction(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $masterIssue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Master Issue',
            'description' => 'Master issue description',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'duplicate_of' => null,
        ]);

        $child1 = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Child Issue 1',
            'description' => 'Child issue 1 description',
            'source_type' => 'FORM',
            'status' => 'DUPLICATE',
            'detection_status' => 'COMPLETED',
            'duplicate_of' => $masterIssue->id,
        ]);

        $child2 = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Child Issue 2',
            'description' => 'Child issue 2 description',
            'source_type' => 'FORM',
            'status' => 'CLOSED',
            'detection_status' => 'COMPLETED',
            'duplicate_of' => $masterIssue->id,
        ]);

        $independentIssue = Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $owner->id,
            'title' => 'Independent Issue',
            'description' => 'Independent issue description',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
            'duplicate_of' => null,
        ]);

        // Initial state: 2 unique reduced duplicates
        $response = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();
        $this->assertEquals(2, $response->viewData('reducedDuplicates'));

        // Case 1: Child 1 is corrected (cancelled) -> duplicate_of becomes null
        $child1->duplicate_of = null;
        $child1->status = 'OPEN';
        $child1->save();

        $responseAfterCorrection = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();
        $this->assertEquals(1, $responseAfterCorrection->viewData('reducedDuplicates'));

        // Case 2: Child 1 is re-decided as duplicate
        $child1->duplicate_of = $masterIssue->id;
        $child1->status = 'DUPLICATE';
        $child1->save();

        $responseAfterReDecision = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();
        $this->assertEquals(2, $responseAfterReDecision->viewData('reducedDuplicates'));
    }

    public function test_empty_state_and_pagination(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // Empty state check
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk()
            ->assertSee('Belum ada catatan aktivitas');

        // Create 25 logs to test pagination (20 per page)
        $now = now();
        $records = [];
        for ($i = 1; $i <= 25; $i++) {
            $records[] = [
                'project_id' => $project->id,
                'action' => 'VOTE_ADDED',
                'reason' => "Vote item {$i}",
                'created_at' => $now->copy()->addMinutes($i),
                'updated_at' => $now->copy()->addMinutes($i),
            ];
        }
        \DB::table('activity_logs')->insert($records);

        $responsePage1 = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();
        $this->assertCount(20, $responsePage1->viewData('logs'));
        $this->assertEquals(25, $responsePage1->viewData('logs')->total());

        $responsePage2 = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'page' => 2]))
            ->assertOk();
        $this->assertCount(5, $responsePage2->viewData('logs'));
    }

    public function test_invalid_filter_parameters_return_safe_validation_errors(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->get(route('projects.activity.index', [
                'project' => $project,
                'category' => 'INVALID_CAT_NAME',
                'date_from' => 'not-a-date',
                'date_to' => '2020-01-01',
            ]))
            ->assertRedirect(route('projects.activity.index', ['project' => $project]))
            ->assertSessionHasErrors(['category', 'date_from', 'date_to']);
    }

    public function test_export_csv_streaming_with_utf8_bom_and_formula_injection_defense(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER', 'name' => '-MaliciousActor']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => '=cmd|\'/C calc\'!A0',
            'reason' => '   +formulaTrigger',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.activity.export', ['project' => $project]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        // Must start with UTF-8 BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Header check
        $this->assertStringContainsString('Waktu (WIB)', $content);
        $this->assertStringContainsString('Pelaku', $content);
        $this->assertStringContainsString('Kategori', $content);

        // Formula injection sanitized check: must be prefixed with single quote
        $this->assertStringContainsString("'=cmd|'/C calc'!A0", $content);
        $this->assertStringContainsString("'   +formulaTrigger", $content);
        $this->assertStringContainsString("'-MaliciousActor", $content);
    }

    public function test_export_and_view_do_not_leak_secrets(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'UPDATE_PROJECT',
            'old_values' => json_encode([
                'name' => 'Old Alpha',
                'github_token' => 'ghp_secretTokenShouldNeverAppear123',
                'password' => 'superSecretPasswordPlain',
            ]),
            'new_values' => json_encode([
                'name' => 'New Alpha',
                'api_key' => 'apiKeySecret456',
                'status' => 'OPEN',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1. Check HTML view
        $viewResponse = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk()
            ->assertSee('New Alpha')
            ->assertSee('***REDACTED***')
            ->assertDontSee('ghp_secretTokenShouldNeverAppear123')
            ->assertDontSee('superSecretPasswordPlain')
            ->assertDontSee('apiKeySecret456');

        // 2. Check CSV export
        $exportResponse = $this->actingAs($owner)
            ->get(route('projects.activity.export', ['project' => $project]))
            ->assertOk();

        $csv = $exportResponse->streamedContent();
        $this->assertStringContainsString('New Alpha', $csv);
        $this->assertStringContainsString('***REDACTED***', $csv);
        $this->assertStringNotContainsString('ghp_secretTokenShouldNeverAppear123', $csv);
        $this->assertStringNotContainsString('superSecretPasswordPlain', $csv);
        $this->assertStringNotContainsString('apiKeySecret456', $csv);
    }

    public function test_keyword_search_escapes_like_wildcards_underscore_and_percent(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \DB::table('activity_logs')->insert([
            [
                'project_id' => $project->id,
                'action' => 'CREATE_PROJECT',
                'reason' => 'Exact underscore match',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project->id,
                'action' => 'CREATE PROJECT',
                'reason' => 'Space instead of underscore',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project->id,
                'action' => 'PERCENT_100%_ACTION',
                'reason' => 'Exact percent match',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project->id,
                'action' => 'PERCENT_1000_ACTION',
                'reason' => 'Zero instead of percent',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // 1. Searching for 'CREATE_PROJECT' must match only the one with underscore, not space
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'keyword' => 'CREATE_PROJECT']))
            ->assertOk()
            ->assertSee('Exact underscore match')
            ->assertDontSee('Space instead of underscore');

        // 2. Searching for '100%' must match only the literal percent sign
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'keyword' => '100%']))
            ->assertOk()
            ->assertSee('Exact percent match')
            ->assertDontSee('Zero instead of percent');
    }

    public function test_export_with_invalid_filter_is_redirected_with_errors_and_valid_matches_filter(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // Invalid export filter -> redirected back with session errors
        $this->actingAs($owner)
            ->get(route('projects.activity.export', [
                'project' => $project,
                'date_from' => 'invalid-date',
            ]))
            ->assertRedirect(route('projects.activity.index', ['project' => $project]))
            ->assertSessionHasErrors(['date_from']);

        // Valid export with filters
        \DB::table('activity_logs')->insert([
            [
                'project_id' => $project->id,
                'action' => 'INVITE_REPORTER',
                'reason' => 'Matching log item',
                'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 05:00:00', 'UTC'),
                'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 05:00:00', 'UTC'),
            ],
            [
                'project_id' => $project->id,
                'action' => 'CREATE_PROJECT',
                'reason' => 'Excluded category item',
                'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 05:00:00', 'UTC'),
                'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 05:00:00', 'UTC'),
            ],
        ]);

        $exportResponse = $this->actingAs($owner)
            ->get(route('projects.activity.export', [
                'project' => $project,
                'category' => 'MEMBERSHIP',
                'date_from' => '2026-11-18',
                'date_to' => '2026-11-18',
            ]))
            ->assertOk();

        $content = $exportResponse->streamedContent();
        $this->assertStringContainsString('Matching log item', $content);
        $this->assertStringContainsString('INVITE_REPORTER', $content);
        $this->assertStringNotContainsString('Excluded category item', $content);
    }

    public function test_target_issue_only_links_if_same_project_and_isolates_foreign_issues(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $projectA = Project::factory()->create(['owner_id' => $owner->id]);
        $projectB = Project::factory()->create();

        $issueSameProject = Issue::forceCreate([
            'project_id' => $projectA->id,
            'author_id' => $owner->id,
            'title' => 'Internal Issue Alpha',
            'description' => 'Description Alpha',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);

        $issueForeignProject = Issue::forceCreate([
            'project_id' => $projectB->id,
            'author_id' => $projectB->owner_id,
            'title' => 'Foreign Issue Beta Top Secret',
            'description' => 'Description Beta',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);

        \DB::table('activity_logs')->insert([
            [
                'project_id' => $projectA->id,
                'issue_id' => $issueSameProject->id,
                'action' => 'ISSUE_STATUS_CHANGED',
                'reason' => 'Update on same project',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $projectA->id,
                'issue_id' => $issueForeignProject->id,
                'action' => 'ISSUE_STATUS_CHANGED',
                'reason' => 'Anomalous foreign project link',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // HTML check: Same project has <a> link; foreign project renders '-' without ID or title
        $viewResponse = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $projectA]))
            ->assertOk();

        $expectedLink = route('issues.show', ['project' => $projectA, 'issue' => $issueSameProject]);
        $viewResponse->assertSee($expectedLink, false);
        $viewResponse->assertSee("#{$issueSameProject->id}");
        $viewResponse->assertSee('Internal Issue Alpha');

        // Zero data leakage: foreign issue ID and title must NEVER be visible
        $forbiddenForeignLink = route('issues.show', ['project' => $projectA, 'issue' => $issueForeignProject]);
        $viewResponse->assertDontSee($forbiddenForeignLink, false);
        $viewResponse->assertDontSee("#{$issueForeignProject->id}");
        $viewResponse->assertDontSee('Foreign Issue Beta Top Secret');

        // CSV check: foreign issue renders '-', zero data leakage
        $csvResponse = $this->actingAs($owner)
            ->get(route('projects.activity.export', ['project' => $projectA]))
            ->assertOk();
        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString("#{$issueSameProject->id} - Internal Issue Alpha", $csv);
        $this->assertStringNotContainsString("#{$issueForeignProject->id}", $csv);
        $this->assertStringNotContainsString('Foreign Issue Beta Top Secret', $csv);
    }

    public function test_eager_loading_prevents_n_plus_one_queries(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $issues = [];
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $user = User::factory()->create();
            $users[] = $user;
            $issues[] = Issue::forceCreate([
                'project_id' => $project->id,
                'author_id' => $user->id,
                'title' => "Issue {$i}",
                'description' => "Description {$i}",
                'source_type' => 'FORM',
                'status' => 'OPEN',
                'detection_status' => 'COMPLETED',
            ]);
        }

        $records = [];
        for ($i = 1; $i <= 20; $i++) {
            $records[] = [
                'project_id' => $project->id,
                'user_id' => $users[$i % 5]->id,
                'issue_id' => $issues[$i % 5]->id,
                'action' => 'VOTE_ADDED',
                'created_at' => now()->addMinutes($i),
                'updated_at' => now()->addMinutes($i),
            ];
        }
        \DB::table('activity_logs')->insert($records);

        \DB::flushQueryLog();
        \DB::enableQueryLog();

        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();

        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        // Without eager loading, 20 items accessing user and issue would trigger 41+ queries.
        // With eager loading (with(['user', 'issue'])), the entire request should execute <= 12 queries.
        $this->assertLessThanOrEqual(12, count($queries), 'Query count exceeds eager-loaded bound; potential N+1 detected.');
    }

    public function test_timestamp_displayed_in_wib(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // Created at 2026-11-18 10:30:00 UTC -> in WIB (UTC+7) it is 2026-11-18 17:30:00 WIB
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'action' => 'CREATE_PROJECT',
            'created_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 10:30:00', 'UTC'),
            'updated_at' => Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-18 10:30:00', 'UTC'),
        ]);

        // HTML check: 18 Nov 2026, 17:30 WIB
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk()
            ->assertSee('18 Nov 2026, 17:30 WIB');

        // CSV check: 2026-11-18 17:30:00 WIB
        $csv = $this->actingAs($owner)
            ->get(route('projects.activity.export', ['project' => $project]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('2026-11-18 17:30:00 WIB', $csv);
    }

    public function test_read_only_via_http_rejects_mutating_verbs(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $url = route('projects.activity.index', ['project' => $project]);

        $this->actingAs($owner)->post($url)->assertStatus(405);
        $this->actingAs($owner)->put($url)->assertStatus(405);
        $this->actingAs($owner)->patch($url)->assertStatus(405);
        $this->actingAs($owner)->delete($url)->assertStatus(405);
    }

    public function test_activity_log_link_on_projects_show_blade_is_only_visible_to_owner(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $nonOwnerMaintainer = User::factory()->create(['role' => 'MAINTAINER']);
        $reporter = User::factory()->create(['role' => 'REPORTER']);

        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // Add active membership for reporter
        \App\Models\Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);

        $expectedUrl = route('projects.activity.index', ['project' => $project]);

        // 1. Maintainer owner can view project page and sees the Riwayat & Log button
        $this->actingAs($owner)
            ->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()
            ->assertSee('Riwayat &amp; Log', false)
            ->assertSee($expectedUrl, false);

        // 2. Maintainer non-owner is rejected by ProjectService policy with 403
        $this->actingAs($nonOwnerMaintainer)
            ->get(route('projects.show', ['id' => $project->id]))
            ->assertForbidden();

        // 3. Active member Reporter can view project page (200) but CANNOT see the Riwayat & Log button or URL
        $this->actingAs($reporter)
            ->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()
            ->assertDontSee('Riwayat &amp; Log', false)
            ->assertDontSee($expectedUrl, false);
    }

    public function test_github_and_csv_import_activity_integration_and_sync_card(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // Prior to any import: summary card shows 'Belum pernah'
        $respInit = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();
        $this->assertEquals('Belum pernah', $respInit->viewData('lastSync'));

        // Import CSV log
        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'IMPORT_CSV',
            'reason' => 'Imported 10 issues from dataset.csv',
            'new_values' => json_encode(['imported_count' => 10, 'file_name' => 'dataset.csv']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Import GitHub log and batch
        $syncTime = Carbon::createFromFormat('Y-m-d H:i:s', '2026-11-25 09:15:00', 'UTC');
        \DB::table('import_batches')->insert([
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'source_type' => 'GITHUB',
            'status' => 'COMPLETED',
            'created_at' => $syncTime,
        ]);

        \DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'IMPORT_GITHUB',
            'reason' => 'Sinkronisasi GitHub repo octocat/Hello-World',
            'new_values' => json_encode([
                'repo_full_name' => 'octocat/Hello-World',
                'imported_count' => 5,
                'installation_access_token' => 'ghs_tokenSecret123',
                'pat_encrypted' => 'encrypted_blob_xyz',
            ]),
            'created_at' => $syncTime,
            'updated_at' => $syncTime,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project]))
            ->assertOk();

        // Check sync summary card shows WIB formatted time (09:15 UTC = 16:15 WIB)
        $expectedWibSync = '25 Nov 2026, 16:15 WIB';
        $this->assertEquals($expectedWibSync, $response->viewData('lastSync'));
        $response->assertSee($expectedWibSync);

        // Check categories and actions appear
        $response->assertSee('IMPORT_CSV');
        $response->assertSee('IMPORT_GITHUB');
        $response->assertSee('ISSUE_SOURCE');

        // Check sensitive tokens are completely masked
        $response->assertDontSee('ghs_tokenSecret123');
        $response->assertDontSee('encrypted_blob_xyz');
        $response->assertSee('***REDACTED***');
    }

    public function test_formula_injection_defense_with_leading_whitespace_and_control_chars(): void
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \DB::table('activity_logs')->insert([
            [
                'project_id' => $project->id,
                'user_id' => $owner->id,
                'action' => "\t=2+5",
                'reason' => "\n-cmd|'/C calc'!A0",
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project->id,
                'user_id' => $owner->id,
                'action' => "\r@SUM(A1:A10)",
                'reason' => "   +SUM(B1:B10)",
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.activity.export', ['project' => $project]))
            ->assertOk();

        $content = $response->streamedContent();

        // Formula characters preceded by whitespace or control chars must be neutralized with leading single quote
        $this->assertStringContainsString("'\t=2+5", $content);
        $this->assertStringContainsString("'\n-cmd|'/C calc'!A0", $content);
        $this->assertStringContainsString("'\r@SUM(A1:A10)", $content);
        $this->assertStringContainsString("'   +SUM(B1:B10)", $content);
    }

    public function test_create_issue_records_activity_log_in_transaction_and_displays_on_activity_log(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        \App\Models\Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);

        // Submit issue via IssueController::store()
        $this->actingAs($reporter)
            ->post(route('issues.store', ['project' => $project]), [
                'title' => 'Halaman profil tidak terbuka',
                'description' => 'Terjadi error 500 ketika membuka halaman profil user.',
            ])
            ->assertRedirect();

        $issue = Issue::where('project_id', $project->id)->first();
        $this->assertNotNull($issue);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'issue_id' => $issue->id,
            'action' => 'CREATE_ISSUE',
        ]);

        // Verify it renders on Activity Log under ISSUE_SOURCE category
        $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'category' => 'ISSUE_SOURCE']))
            ->assertOk()
            ->assertSee('CREATE_ISSUE')
            ->assertSee($reporter->name)
            ->assertSee("#{$issue->id}");
    }

    public function test_real_csv_github_pat_and_github_app_producers_display_on_activity_log(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        // 1. Real CSV Import producer
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'issues.csv',
            "source_id,title,description\nCSV-1,Bug CSV Import,Deskripsi bug impor CSV yang cukup panjang.\n"
        );
        $parsedCsv = app(\App\Services\CsvIssueReader::class)->read($file);
        app(\App\Services\CsvIssueImportService::class)->import($project, $owner, $parsedCsv, 'issues.csv');

        // 2. Real GitHub PAT Import producer
        $config = new \App\Models\GithubConfig();
        $config->project_id = $project->id;
        $config->repo_full_name = 'acme/pat-repo';
        $config->pat_encrypted = 'encrypted_pat_value';
        $config->save();

        $patIssues = [
            [
                'number' => 11,
                'title' => 'Bug dari GitHub PAT',
                'body' => 'Deskripsi bug dari GitHub PAT yang cukup panjang untuk validasi.',
            ],
        ];
        $patPreview = app(\App\Services\GithubIssuePreviewService::class)->preview($project, 'acme/pat-repo', $patIssues);
        $patFingerprint = hash('sha256', json_encode($patPreview['rows'], JSON_THROW_ON_ERROR));
        app(\App\Services\GithubIssueImportService::class)->import($project, $owner, $config->id, 'acme/pat-repo', $patIssues, $patFingerprint);

        // 3. Real GitHub App Import producer
        $appConn = new \App\Models\GithubAppConnection();
        $appConn->project_id = $project->id;
        $appConn->installation_id = 99911;
        $appConn->repository_id = 88822;
        $appConn->repo_full_name = 'acme/app-repo';
        $appConn->verified_github_user_id = 777;
        $appConn->verified_at = now();
        $appConn->save();

        $appIssues = [
            [
                'number' => 22,
                'title' => 'Bug dari GitHub App',
                'body' => 'Deskripsi bug dari GitHub App yang cukup panjang untuk validasi.',
            ],
        ];
        $appPreview = app(\App\Services\GithubIssuePreviewService::class)->preview($project, 'acme/app-repo', $appIssues);
        $appFingerprint = hash('sha256', json_encode($appPreview['rows'], JSON_THROW_ON_ERROR));
        app(\App\Services\GithubIssueImportService::class)->importFromApp($project, $owner, $appConn->id, 99911, 88822, 'acme/app-repo', $appIssues, $appFingerprint);

        // Verify all 3 appear on Activity Log under ISSUE_SOURCE and lastSync is updated
        $response = $this->actingAs($owner)
            ->get(route('projects.activity.index', ['project' => $project, 'category' => 'ISSUE_SOURCE']))
            ->assertOk()
            ->assertSee('IMPORT_CSV')
            ->assertSee('IMPORT_GITHUB')
            ->assertSee('acme/pat-repo')
            ->assertSee('acme/app-repo');

        $this->assertNotEquals('Belum pernah', $response->viewData('lastSync'));
    }
}
