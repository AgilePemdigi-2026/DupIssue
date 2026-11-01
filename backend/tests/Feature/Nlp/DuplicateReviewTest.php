<?php

namespace Tests\Feature\Nlp;

use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DuplicateReviewTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): array
    {
        $project = Project::factory()->create();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);
        $source = $this->makeIssue($project, $reporter);
        $suggested = $this->makeIssue($project, $reporter);
        $pair = DuplicateCandidate::create([
            'issue_id' => $source->id,
            'candidate_issue_id' => $suggested->id,
            'similarity_score' => 1.0,
        ]);
        return [$project, $reporter, $source, $suggested, $pair];
    }

    private function makeIssue(Project $project, User $author): Issue
    {
        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $author->id,
            'title' => 'Issue simulasi',
            'description' => 'Uji validasi duplikat tanpa data privat.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'COMPLETED',
        ]);
    }

    private function payload(Issue $source, Issue $suggested, DuplicateCandidate $pair,
        string $decision, ?int $parent = null): array
    {
        $source->refresh();
        $suggested->refresh();
        $pair->refresh();
        return [
            'decision' => $decision,
            'parent_issue_id' => $parent,
            'expected_decision' => $pair->decision,
            'expected_source_status' => $source->status,
            'expected_candidate_status' => $suggested->status,
            'expected_source_parent' => $source->duplicate_of ?? 0,
            'expected_candidate_parent' => $suggested->duplicate_of ?? 0,
        ];
    }

    private function url(Project $project, Issue $source, DuplicateCandidate $pair): string
    {
        return route('issues.duplicates.decide', [
            'project' => $project, 'issue' => $source, 'candidate' => $pair,
        ]);
    }

    public function test_maintainer_can_confirm_duplicate_and_record_decision_and_history(): void
    {
        [$p, $reporter, $src, $other, $pair] = $this->fixture();
        $this->actingAs($p->owner)->get(route('issues.show', ['project' => $p, 'issue' => $src]))
            ->assertOk()->assertSee('Bandingkan dan Validasi');
        $this->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'duplicate_of' => $other->id, 'status' => 'DUPLICATE']);
        $this->assertDatabaseHas('duplicate_candidates', [
            'id' => $pair->id, 'decision' => 'DUPLICATE', 'decided_by' => $p->owner_id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $p->id, 'action' => 'DUPLICATE_REVIEW', 'user_id' => $p->owner_id,
        ]);
        $this->actingAs($reporter)->get(route('issues.show', ['project' => $p, 'issue' => $src]))
            ->assertOk()->assertSee('Issue Induk')->assertDontSee('Konfirmasi Duplikat');
    }

    public function test_reject_keeps_status_and_allows_later_re_review(): void
    {
        [$p, , $src, $other, $pair] = $this->fixture();
        $this->actingAs($p->owner)->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'NOT_DUPLICATE'))->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'status' => 'OPEN', 'duplicate_of' => null]);
        $this->assertDatabaseHas('duplicate_candidates', ['id' => $pair->id, 'decision' => 'NOT_DUPLICATE']);
        $this->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'status' => 'DUPLICATE']);
        $this->assertSame(2, DB::table('activity_logs')->where('project_id', $p->id)->count());
    }

    public function test_reporter_and_other_maintainer_cannot_decide(): void
    {
        [$p, $reporter, $src, $other, $pair] = $this->fixture();
        $payload = $this->payload($src, $other, $pair, 'DUPLICATE', $other->id);
        $this->actingAs($reporter)->post($this->url($p, $src, $pair), $payload)->assertForbidden();
        $outsider = User::factory()->create(['role' => 'MAINTAINER']);
        $this->actingAs($outsider)->post($this->url($p, $src, $pair), $payload)->assertForbidden();
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'duplicate_of' => null]);
    }

    public function test_forged_pair_or_cross_project_is_not_reviewable(): void
    {
        [$p, , $src, $other, $pair] = $this->fixture();
        $another = Project::factory()->create();
        $this->actingAs($p->owner)
            ->post(route('issues.duplicates.decide', [
                'project' => $another, 'issue' => $src, 'candidate' => $pair,
            ]), $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))
            ->assertForbidden();
        $alien = $this->makeIssue($p, $p->owner);
        $this->post(route('issues.duplicates.decide', [
            'project' => $p, 'issue' => $alien, 'candidate' => $pair,
        ]), $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))
            ->assertNotFound();
    }

    public function test_duplicate_parent_is_rejected(): void
    {
        [$p, , $src, $other, $pair] = $this->fixture();
        $root = $this->makeIssue($p, $p->owner);
        $other->duplicate_of = $root->id;
        $other->status = 'DUPLICATE';
        $other->save();
        $this->actingAs($p->owner)->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertStatus(409);
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'duplicate_of' => null]);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_parent_resolved_or_closed_closes_duplicate(): void
    {
        foreach (['RESOLVED', 'CLOSED'] as $parentStatus) {
            [$p, , $src, $other, $pair] = $this->fixture();
            $other->status = $parentStatus;
            $other->save();
            $this->actingAs($p->owner)->post($this->url($p, $src, $pair),
                $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertRedirect();
            $this->assertDatabaseHas('issues', [
                'id' => $src->id, 'duplicate_of' => $other->id, 'status' => 'CLOSED',
            ]);
        }
    }

    public function test_correct_duplicate_requires_reason_and_preserves_audit(): void
    {
        [$p, , $src, $other, $pair] = $this->fixture();
        $this->actingAs($p->owner)->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertRedirect();
        $url = route('issues.duplicates.cancel', ['project' => $p, 'issue' => $src]);
        $form = ['reason' => '', 'expected_duplicate_of' => $other->id, 'expected_status' => 'DUPLICATE'];
        $this->post($url, $form)->assertSessionHasErrors('reason');
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'status' => 'DUPLICATE']);
        $form['reason'] = 'Laporan ternyata berbeda setelah diteliti';
        $this->post($url, $form)->assertRedirect();
        $this->assertDatabaseHas('issues', ['id' => $src->id, 'duplicate_of' => null, 'status' => 'OPEN']);
        $this->assertDatabaseHas('duplicate_candidates', ['id' => $pair->id, 'decision' => 'PENDING']);
        $this->assertDatabaseHas('activity_logs', [
            'issue_id' => $src->id, 'action' => 'DUPLICATE_CORRECTED', 'reason' => $form['reason'],
        ]);
    }

    public function test_stale_form_cannot_overwrite_current_decision(): void
    {
        [$p, , $src, $other, $pair] = $this->fixture();
        $oldForm = $this->payload($src, $other, $pair, 'DUPLICATE', $other->id);
        $this->actingAs($p->owner)->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'NOT_DUPLICATE'))->assertRedirect();
        $this->post($this->url($p, $src, $pair), $oldForm)->assertStatus(409);
        $this->assertDatabaseHas('duplicate_candidates', ['id' => $pair->id, 'decision' => 'NOT_DUPLICATE']);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_no_decision_before_detection_completed_and_no_cycle_by_parent_with_child(): void
    {
        [$p, , $src, $other, $pair] = $this->fixture();
        $src->detection_status = 'PROCESSING';
        $src->save();
        $this->actingAs($p->owner)->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertStatus(409);
        $src->detection_status = 'COMPLETED';
        $src->save();
        $extra = $this->makeIssue($p, $p->owner);
        $extra->duplicate_of = $src->id;
        $extra->status = 'DUPLICATE';
        $extra->save();
        $this->post($this->url($p, $src, $pair),
            $this->payload($src, $other, $pair, 'DUPLICATE', $other->id))->assertStatus(409);
        $this->assertDatabaseCount('activity_logs', 0);
    }
}
