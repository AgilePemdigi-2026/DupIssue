<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Tests persistence/navigation with the real Project module. Successful NLP
// enqueue is tested separately by SubmitIssueTest's nlp-integration group.
class SubmitIssueSuccessTest extends TestCase
{
    use DatabaseMigrations;

    private function activeReporter(Project $project): User
    {
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->create(['project_id' => $project->id, 'user_id' => $reporter->id]);

        return $reporter;
    }

    public function test_active_reporter_can_navigate_from_project_to_submit_and_back(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->activeReporter($project));
        $this->get(route('reporter.projects.index'))->assertOk()->assertViewIs('projects.index');
        $this->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()->assertSee('href="'.route('issues.create', $project).'"', false);
        $this->get(route('issues.create', $project))->assertOk()->assertViewIs('issues.create')
            ->assertSee('action="'.route('issues.store', $project).'"', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('href="'.route('projects.show', ['id' => $project->id]).'"', false);
    }

    public function test_project_owner_has_no_submit_link_and_cannot_bypass_it(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);
        $this->get(route('maintainer.dashboard'))->assertOk()->assertViewIs('projects.index');
        $this->get(route('projects.show', ['id' => $project->id]))->assertOk()
            ->assertDontSee('href="'.route('issues.create', $project).'"', false);
        $this->post(route('issues.store', $project), [
            'title' => 'Valid title', 'description' => 'Valid issue description',
        ])->assertForbidden();
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_submit_persists_server_fields_as_pending_before_after_commit_scheduling(): void
    {
        $project = Project::factory()->create();
        $reporter = $this->activeReporter($project);
        Http::preventStrayRequests();
        Http::fake([]);
        DB::beginTransaction();
        try {
            $response = $this->actingAs($reporter)->post(route('issues.store', $project), [
                'title' => 'Checkout tidak merespons',
                'description' => 'Tombol Bayar tidak merespons setelah diklik.',
                'project_id' => 999999, 'author_id' => 999999, 'status' => 'DUPLICATE',
                'detection_status' => 'COMPLETED', 'source_type' => 'GITHUB',
                'vote_count' => 99, 'duplicate_of' => 999999,
            ]);
            $issue = Issue::query()->sole();
            $response->assertRedirect(route('issues.show', ['project' => $project, 'issue' => $issue]));
            $this->assertDatabaseHas('issues', [
                'id' => $issue->id, 'project_id' => $project->id, 'author_id' => $reporter->id,
                'status' => 'OPEN', 'detection_status' => 'PENDING', 'source_type' => 'FORM',
                'vote_count' => 0, 'duplicate_of' => null,
            ]);
            $this->assertDatabaseCount('jobs', 0);
            Http::assertNothingSent();
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        // This proves committed persistence, not successful NLP enqueue. The
        // missing-job test separately verifies FAILED when PR #22 is absent.
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'status' => 'OPEN']);
        $this->assertDatabaseCount('issues', 1);
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('Issue berhasil dibuat')->assertSee($issue->title);
        Http::assertNothingSent();
    }
}
