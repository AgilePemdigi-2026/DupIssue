<?php

namespace Tests\Feature\Nlp;

use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class IssueRecommendationDisplayTest extends TestCase
{
    use DatabaseMigrations;

    private function setupReporter(): array
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

    private function makeIssue(Project $project, User $reporter, string $title, string $state): Issue
    {
        return Issue::forceCreate([
            'title' => $title,
            'description' => 'Deskripsi simulasi untuk pengujian rekomendasi issue.',
            'project_id' => $project->id,
            'author_id' => $reporter->id,
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => $state,
        ]);
    }

    public function test_reporter_sees_scored_candidate_and_project_scoped_link(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $source = $this->makeIssue($project, $reporter, 'Issue sumber', 'COMPLETED');
        $candidate = $this->makeIssue($project, $reporter, 'Issue pembanding autentikasi', 'COMPLETED');
        DuplicateCandidate::query()->create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $candidate->id,
            'similarity_score' => 1.0,
        ]);

        $this->actingAs($reporter)
            ->get(route('issues.show', ['project' => $project, 'issue' => $source]))
            ->assertOk()
            ->assertSee('Rekomendasi Issue Serupa')
            ->assertSee('Issue pembanding autentikasi')
            ->assertSee('100,00%')
            ->assertSee(route('issues.show', ['project' => $project, 'issue' => $candidate]), false)
            ->assertSee('Keputusan duplikat tetap dilakukan Maintainer');

        // Project owner/Maintainer can inspect the same recommendations.
        $this->actingAs($project->owner)
            ->get(route('issues.show', ['project' => $project, 'issue' => $source]))
            ->assertOk()
            ->assertSee('Issue pembanding autentikasi');
    }

    public function test_completed_issue_without_candidates_shows_empty_state(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, $reporter, 'Issue tanpa kandidat', 'COMPLETED');

        $this->actingAs($reporter)
            ->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()
            ->assertSee('Belum ada kandidat yang memenuhi batas similarity');
    }

    public function test_pending_issue_displays_processing_notice(): void
    {
        [$project, $reporter] = $this->setupReporter();
        $issue = $this->makeIssue($project, $reporter, 'Issue menunggu NLP', 'PENDING');

        $this->actingAs($reporter)
            ->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()
            ->assertSee('Deteksi kemiripan sedang diproses')
            ->assertDontSee('Rekomendasi Issue Serupa');
    }
}
