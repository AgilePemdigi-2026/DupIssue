<?php

namespace Tests\Feature\Nlp;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class IssueIndexNavigationTest extends TestCase
{
    use DatabaseMigrations;

    private function setupProject(): array
    {
        $project = Project::factory()->create();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);
        return [$project, $reporter];
    }

    private function makeIssue(Project $project, User $author, string $title): Issue
    {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $author->id,
            'title' => $title,
            'description' => 'Deskripsi sintetis untuk pengujian navigasi issue.',
            'status' => 'OPEN',
            'source_type' => 'FORM',
            'detection_status' => 'COMPLETED',
        ]);
    }

    public function test_reporter_can_open_issue_list_from_project_and_see_detail_link(): void
    {
        [$project, $reporter] = $this->setupProject();
        $issue = $this->makeIssue($project, $reporter, 'Issue login pengujian');
        $listUrl = route('issues.index', ['project' => $project]);
        $detailUrl = route('issues.show', ['project' => $project, 'issue' => $issue]);

        $this->actingAs($reporter)->get(route('projects.show', ['id' => $project->id]))
            ->assertOk()->assertSee($listUrl, false)->assertSee('Lihat Issue');
        $this->get($listUrl)
            ->assertOk()->assertSee('Issue login pengujian')
            ->assertSee('COMPLETED')->assertSee($detailUrl, false);
        $this->get($detailUrl)->assertOk();
    }

    public function test_maintainer_owner_can_open_issue_list(): void
    {
        [$project, $reporter] = $this->setupProject();
        $this->makeIssue($project, $reporter, 'Issue visible bagi Maintainer');

        $this->actingAs($project->owner)
            ->get(route('issues.index', ['project' => $project]))
            ->assertOk()->assertSee('Issue visible bagi Maintainer')
            ->assertDontSee('Submit Issue</a>', false);
    }

    public function test_issue_list_is_scoped_to_requested_project(): void
    {
        [$project, $reporter] = $this->setupProject();
        $foreignProject = Project::factory()->create();
        $this->makeIssue($project, $reporter, 'Issue project sendiri');
        $this->makeIssue($foreignProject, $foreignProject->owner, 'Rahasia project lain');

        $this->actingAs($reporter)
            ->get(route('issues.index', ['project' => $project]))
            ->assertOk()->assertSee('Issue project sendiri')
            ->assertDontSee('Rahasia project lain');
        $this->get(route('issues.index', ['project' => $foreignProject]))
            ->assertForbidden();
    }

    public function test_inactive_member_cannot_view_list(): void
    {
        [$project, $reporter] = $this->setupProject();
        Membership::query()->where('project_id', $project->id)
            ->where('user_id', $reporter->id)->update(['is_active' => false]);

        $this->actingAs($reporter)
            ->get(route('issues.index', ['project' => $project]))
            ->assertForbidden();
    }

    public function test_empty_project_displays_empty_state(): void
    {
        [$project, $reporter] = $this->setupProject();
        $this->actingAs($reporter)
            ->get(route('issues.index', ['project' => $project]))
            ->assertOk()->assertSee('Belum ada issue pada project ini.');
    }
}
