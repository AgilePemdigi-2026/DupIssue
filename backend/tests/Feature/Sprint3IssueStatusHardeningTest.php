<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sprint 3 Feature 12 regression: existing status flow + narrow concurrency gaps.
 * DatabaseMigrations runs only against the isolated PHPUnit SQLite environment.
 */
final class Sprint3IssueStatusHardeningTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): array
    {
        $project = Project::factory()->create();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        $parent = $this->makeIssue($project, $reporter, 'IN_PROGRESS');

        return [$project, $reporter, $parent];
    }

    private function makeIssue(
        Project $project,
        ?User $reporter,
        string $status,
        ?Issue $parent = null,
        string $source = 'FORM'
    ): Issue {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $reporter?->id,
            'title' => 'Sprint 3 Feature 12 issue',
            'description' => 'Isolated test data for status transition and duplicate behavior.',
            'source_type' => $source,
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => $parent?->id,
        ]);
    }

    private function preview(Project $project, Issue $issue, string $newStatus): array
    {
        $issue->refresh();
        $this->post(route('issues.status.preview', [
            'project' => $project,
            'issue' => $issue,
        ]), [
            'new_status' => $newStatus,
            'expected_status' => $issue->status,
            'expected_updated_at' => $issue->getRawOriginal('updated_at') ?? '',
        ])->assertOk();

        $key = "issue-status-confirm.{$project->id}.{$issue->id}";
        $pending = app('session.store')->get($key);
        $this->assertIsArray($pending);
        $this->assertArrayHasKey('token', $pending);

        return $pending;
    }

    private function confirm(Project $project, Issue $issue, array $pending): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('issues.status.confirm', [
            'project' => $project,
            'issue' => $issue,
        ]), ['token' => $pending['token']]);
    }

    public function test_same_second_aba_status_change_invalidates_confirmation(): void
    {
        [$project, , $parent] = $this->fixture();
        $this->actingAs($project->owner);
        $pending = $this->preview($project, $parent, 'RESOLVED');
        $sameTimestamp = $parent->refresh()->getRawOriginal('updated_at');

        // Simulate another status writer updating twice within the same MySQL
        // DATETIME second: the old status and updated_at are both restored.
        DB::table('issues')->where('id', $parent->id)
            ->update(['status' => 'OPEN', 'updated_at' => $sameTimestamp]);
        DB::table('issues')->where('id', $parent->id)
            ->update(['status' => 'IN_PROGRESS', 'updated_at' => $sameTimestamp]);
        DB::table('activity_logs')->insert([
            'project_id' => $project->id,
            'user_id' => $project->owner_id,
            'issue_id' => $parent->id,
            'action' => 'ISSUE_STATUS_CHANGED',
            'old_values' => json_encode(['status' => 'OPEN']),
            'new_values' => json_encode(['status' => 'IN_PROGRESS']),
            'reason' => 'Simulated second writer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->confirm($project, $parent, $pending)->assertStatus(409);
        $this->assertDatabaseHas('issues', [
            'id' => $parent->id, 'status' => 'IN_PROGRESS',
        ]);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_changed_duplicate_identity_is_rejected_even_if_count_stays_equal(): void
    {
        [$project, $reporter, $parent] = $this->fixture();
        $first = $this->makeIssue($project, $reporter, 'DUPLICATE', $parent);
        $second = $this->makeIssue($project, $reporter, 'CLOSED', $parent);
        $this->actingAs($project->owner);
        $pending = $this->preview($project, $parent, 'RESOLVED');
        $this->assertSame(1, $pending['auto_close_count']);

        // A competing operation swaps which linked child needs auto-close.
        // Counting alone cannot distinguish this from the approved preview.
        DB::table('issues')->where('id', $first->id)->update(['status' => 'CLOSED']);
        DB::table('issues')->where('id', $second->id)->update(['status' => 'DUPLICATE']);

        $this->confirm($project, $parent, $pending)->assertStatus(409);
        $this->assertDatabaseHas('issues', [
            'id' => $parent->id, 'status' => 'IN_PROGRESS',
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $first->id, 'status' => 'CLOSED',
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $second->id, 'status' => 'DUPLICATE',
        ]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_status_changes_work_for_csv_and_github_issues_with_null_author(): void
    {
        [$project] = $this->fixture();
        $this->actingAs($project->owner);

        foreach (['CSV', 'GITHUB'] as $source) {
            // These source types already exist on main; no PR #48 metadata
            // columns or import service are required for this regression.
            $importedIssue = $this->makeIssue($project, null, 'OPEN', null, $source);
            $pending = $this->preview($project, $importedIssue, 'IN_PROGRESS');
            $this->confirm($project, $importedIssue, $pending)->assertRedirect();
            $this->assertDatabaseHas('issues', [
                'id' => $importedIssue->id,
                'author_id' => null,
                'source_type' => $source,
                'status' => 'IN_PROGRESS',
                'detection_status' => 'COMPLETED',
            ]);
            $this->assertDatabaseHas('activity_logs', [
                'project_id' => $project->id,
                'issue_id' => $importedIssue->id,
                'action' => 'ISSUE_STATUS_CHANGED',
            ]);
        }

        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_auto_close_never_touches_duplicate_in_another_project(): void
    {
        [$project, $reporter, $parent] = $this->fixture();
        $foreignProject = Project::factory()->create();
        $foreignChild = $this->makeIssue(
            $foreignProject, $reporter, 'DUPLICATE', $parent
        );
        $this->actingAs($project->owner);

        $pending = $this->preview($project, $parent, 'RESOLVED');
        $this->assertSame(0, $pending['auto_close_count']);
        $this->confirm($project, $parent, $pending)->assertRedirect();

        $this->assertDatabaseHas('issues', [
            'id' => $parent->id, 'status' => 'RESOLVED',
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $foreignChild->id,
            'project_id' => $foreignProject->id,
            'status' => 'DUPLICATE',
            'duplicate_of' => $parent->id,
        ]);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseMissing('activity_logs', [
            'issue_id' => $foreignChild->id,
        ]);
    }
}
