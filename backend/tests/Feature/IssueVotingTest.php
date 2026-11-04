<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueVote;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueVotingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Membuat data awal untuk pengujian voting.
     */
    private function createVotingFixture(): array
    {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
        ]);

        $issue = new Issue();
        $issue->project_id = $project->id;
        $issue->author_id = $reporter->id;
        $issue->title = 'Contoh issue untuk voting';
        $issue->description = 'Deskripsi issue untuk pengujian voting.';
        $issue->source_type = 'FORM';
        $issue->status = 'OPEN';
        $issue->detection_status = 'PENDING';
        $issue->vote_count = 0;
        $issue->save();

        return [$owner, $reporter, $project, $issue];
    }

    private function createRelatedIssue(
        Project $project,
        User $author,
        string $title,
        string $status = 'OPEN',
        ?int $duplicateOf = null
    ): Issue {
        $issue = new Issue();
        $issue->project_id = $project->id;
        $issue->author_id = $author->id;
        $issue->title = $title;
        $issue->description = 'Deskripsi issue untuk pengujian voting.';
        $issue->source_type = 'FORM';
        $issue->status = $status;
        $issue->detection_status = 'PENDING';
        $issue->vote_count = 0;
        $issue->duplicate_of = $duplicateOf;
        $issue->save();

        return $issue;
    }

    public function test_active_reporter_can_vote(): void
    {
        [, $reporter, $project, $issue] = $this->createVotingFixture();

        $response = $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('issue_votes', [
            'issue_id' => $issue->id,
            'user_id' => $reporter->id,
        ]);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'vote_count' => 1,
        ]);
    }

    public function test_same_user_cannot_vote_twice(): void
    {
        [, $reporter, $project, $issue] = $this->createVotingFixture();

        $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $this->assertDatabaseCount('issue_votes', 1);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'vote_count' => 1,
        ]);
    }

    public function test_user_can_unvote_and_vote_count_decreases(): void
    {
        [, $reporter, $project, $issue] = $this->createVotingFixture();

        $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $response = $this->actingAs($reporter)->delete(
            route('issues.unvote', [$project, $issue])
        );

        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('issue_votes', [
            'issue_id' => $issue->id,
            'user_id' => $reporter->id,
        ]);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'vote_count' => 0,
        ]);
    }

    public function test_repeated_unvote_does_not_make_vote_count_negative(): void
    {
        [, $reporter, $project, $issue] = $this->createVotingFixture();

        $this->actingAs($reporter)->delete(
            route('issues.unvote', [$project, $issue])
        );

        $this->actingAs($reporter)->delete(
            route('issues.unvote', [$project, $issue])
        );

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'vote_count' => 0,
        ]);
    }

    public function test_unauthenticated_user_cannot_vote(): void
    {
        [, , $project, $issue] = $this->createVotingFixture();

        $response = $this->post(
            route('issues.vote', [$project, $issue])
        );

        $response->assertRedirect(route('login'));

        $this->assertDatabaseCount('issue_votes', 0);
    }

    public function test_reporter_without_membership_cannot_vote(): void
    {
        [, , $project, $issue] = $this->createVotingFixture();

        $outsider = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        $response = $this->actingAs($outsider)->post(
            route('issues.vote', [$project, $issue])
        );

        $response->assertForbidden();

        $this->assertDatabaseCount('issue_votes', 0);
    }

    public function test_reporter_with_inactive_membership_cannot_vote(): void
    {
        [, $reporter, $project, $issue] = $this->createVotingFixture();

        Membership::where('project_id', $project->id)
            ->where('user_id', $reporter->id)
            ->update([
                'is_active' => false,
                'removed_at' => now(),
            ]);

        $response = $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $response->assertForbidden();

        $this->assertDatabaseCount('issue_votes', 0);
    }

    public function test_user_cannot_vote_on_issue_from_another_project(): void
    {
        [, $reporter, , $issue] = $this->createVotingFixture();

        $anotherOwner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $anotherProject = Project::factory()->create([
            'owner_id' => $anotherOwner->id,
        ]);

        Membership::factory()->active()->create([
            'project_id' => $anotherProject->id,
            'user_id' => $reporter->id,
        ]);

        $response = $this->actingAs($reporter)->post(
            route('issues.vote', [$anotherProject, $issue])
        );

        $response->assertNotFound();

        $this->assertDatabaseCount('issue_votes', 0);
    }

    public function test_vote_count_matches_issue_vote_records(): void
    {
        [, $reporter, $project, $issue] = $this->createVotingFixture();

        $secondReporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $secondReporter->id,
        ]);

        $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $this->actingAs($secondReporter)->post(
            route('issues.vote', [$project, $issue])
        );

        $actualVotes = IssueVote::where('issue_id', $issue->id)->count();

        $issue->refresh();

        $this->assertSame($actualVotes, (int) $issue->vote_count);
        $this->assertSame(2, $actualVotes);
    }

    /**
     * Maintainer tidak boleh melakukan Vote,
     * meskipun memiliki membership aktif.
     */
    public function test_maintainer_with_active_membership_cannot_vote(): void
    {
        [$owner, , $project, $issue] = $this->createVotingFixture();

        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->post(
            route('issues.vote', [$project, $issue])
        );

        $response->assertForbidden();

        $this->assertDatabaseCount('issue_votes', 0);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'vote_count' => 0,
        ]);
    }

    /**
     * Maintainer tidak boleh melakukan Unvote.
     * Vote milik Reporter tidak boleh ikut terhapus.
     */
    public function test_maintainer_with_active_membership_cannot_unvote(): void
    {
        [$owner, $reporter, $project, $issue] = $this->createVotingFixture();

        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
        ]);

        $vote = new IssueVote();
        $vote->issue_id = $issue->id;
        $vote->user_id = $reporter->id;
        $vote->save();

        $issue->vote_count = 1;
        $issue->save();

        $response = $this->actingAs($owner)->delete(
            route('issues.unvote', [$project, $issue])
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('issue_votes', [
            'issue_id' => $issue->id,
            'user_id' => $reporter->id,
        ]);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'vote_count' => 1,
        ]);
    }

    public function test_duplicate_vote_is_saved_on_parent_and_ignores_issue_parameter(): void
    {
        [, $reporter, $project, $parent] = $this->createVotingFixture();
        $child = $this->createRelatedIssue(
            $project,
            $reporter,
            'Issue duplikat',
            'DUPLICATE',
            $parent->id
        );
        $unrelatedIssue = $this->createRelatedIssue(
            $project,
            $reporter,
            'Issue lain'
        );

        $response = $this->actingAs($reporter)->post(
            route('issues.vote', [$project, $child]),
            ['issue_id' => $unrelatedIssue->id]
        );

        $response->assertRedirect(route('issues.show', [$project, $parent]));
        $this->assertDatabaseHas('issue_votes', [
            'issue_id' => $parent->id,
            'user_id' => $reporter->id,
        ]);
        $this->assertDatabaseMissing('issue_votes', [
            'issue_id' => $child->id,
            'user_id' => $reporter->id,
        ]);
        $this->assertDatabaseMissing('issue_votes', [
            'issue_id' => $unrelatedIssue->id,
            'user_id' => $reporter->id,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $parent->id,
            'vote_count' => 1,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'vote_count' => 0,
        ]);

        $this->actingAs($reporter)
            ->get(route('issues.show', [$project, $child]))
            ->assertOk()
            ->assertSee('Vote dicatat pada issue induk #'.$parent->id)
            ->assertSee('Batalkan Vote');
    }

    public function test_duplicate_unvote_removes_parent_vote_and_preserves_child_count(): void
    {
        [, $reporter, $project, $parent] = $this->createVotingFixture();
        $child = $this->createRelatedIssue(
            $project,
            $reporter,
            'Issue duplikat',
            'DUPLICATE',
            $parent->id
        );

        $this->actingAs($reporter)->post(route('issues.vote', [$project, $parent]));

        $response = $this->actingAs($reporter)
            ->delete(route('issues.unvote', [$project, $child]));

        $response->assertRedirect(route('issues.show', [$project, $parent]));
        $this->assertDatabaseMissing('issue_votes', [
            'issue_id' => $parent->id,
            'user_id' => $reporter->id,
        ]);
        $this->assertDatabaseMissing('issue_votes', [
            'issue_id' => $child->id,
            'user_id' => $reporter->id,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $parent->id,
            'vote_count' => 0,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'vote_count' => 0,
        ]);
    }

    public function test_duplicate_without_parent_is_rejected_for_vote_and_unvote(): void
    {
        [, $reporter, $project] = $this->createVotingFixture();
        $orphan = $this->createRelatedIssue(
            $project,
            $reporter,
            'Issue duplikat tanpa induk',
            'DUPLICATE'
        );

        $this->actingAs($reporter)
            ->post(route('issues.vote', [$project, $orphan]))
            ->assertStatus(409);
        $this->actingAs($reporter)
            ->delete(route('issues.unvote', [$project, $orphan]))
            ->assertStatus(409);
        $this->actingAs($reporter)
            ->get(route('issues.show', [$project, $orphan]))
            ->assertNotFound();

        $this->assertDatabaseCount('issue_votes', 0);
        $this->assertDatabaseHas('issues', [
            'id' => $orphan->id,
            'vote_count' => 0,
        ]);
    }

    public function test_duplicate_parent_with_duplicate_status_or_parent_link_is_rejected(): void
    {
        [, $reporter, $project, $grandparent] = $this->createVotingFixture();
        $duplicateStatusParent = $this->createRelatedIssue(
            $project,
            $reporter,
            'Parent berstatus duplikat',
            'DUPLICATE'
        );
        $linkedParent = $this->createRelatedIssue(
            $project,
            $reporter,
            'Parent dengan relasi induk',
            'OPEN',
            $grandparent->id
        );
        $childWithDuplicateStatusParent = $this->createRelatedIssue(
            $project,
            $reporter,
            'Child dengan parent berstatus duplikat',
            'DUPLICATE',
            $duplicateStatusParent->id
        );
        $childWithLinkedParent = $this->createRelatedIssue(
            $project,
            $reporter,
            'Child dengan parent yang punya induk',
            'DUPLICATE',
            $linkedParent->id
        );

        foreach ([$childWithDuplicateStatusParent, $childWithLinkedParent] as $child) {
            $this->actingAs($reporter)
                ->post(route('issues.vote', [$project, $child]))
                ->assertStatus(409);
            $this->actingAs($reporter)
                ->delete(route('issues.unvote', [$project, $child]))
                ->assertStatus(409);
            $this->actingAs($reporter)
                ->get(route('issues.show', [$project, $child]))
                ->assertNotFound();
        }

        $this->assertDatabaseCount('issue_votes', 0);
        $this->assertDatabaseHas('issues', [
            'id' => $grandparent->id,
            'vote_count' => 0,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $duplicateStatusParent->id,
            'vote_count' => 0,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $linkedParent->id,
            'vote_count' => 0,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $childWithDuplicateStatusParent->id,
            'vote_count' => 0,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $childWithLinkedParent->id,
            'vote_count' => 0,
        ]);
    }

    public function test_duplicate_parent_from_another_project_is_rejected(): void
    {
        [, $reporter, $project] = $this->createVotingFixture();
        $otherOwner = User::factory()->create(['role' => 'MAINTAINER']);
        $otherProject = Project::factory()->create(['owner_id' => $otherOwner->id]);
        $foreignParent = $this->createRelatedIssue(
            $otherProject,
            $reporter,
            'Parent dari project lain'
        );
        $child = $this->createRelatedIssue(
            $project,
            $reporter,
            'Issue duplikat lintas project',
            'DUPLICATE',
            $foreignParent->id
        );

        $this->actingAs($reporter)
            ->post(route('issues.vote', [$project, $child]))
            ->assertStatus(409);
        $this->actingAs($reporter)
            ->delete(route('issues.unvote', [$project, $child]))
            ->assertStatus(409);
        $this->actingAs($reporter)
            ->get(route('issues.show', [$project, $child]))
            ->assertNotFound();

        $this->assertDatabaseCount('issue_votes', 0);
        $this->assertDatabaseHas('issues', [
            'id' => $foreignParent->id,
            'vote_count' => 0,
        ]);
        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'vote_count' => 0,
        ]);
    }

    public function test_only_active_reporter_sees_voting_controls(): void
    {
        [$owner, $reporter, $project, $issue] = $this->createVotingFixture();

        $this->actingAs($reporter)
            ->get(route('issues.show', [$project, $issue]))
            ->assertOk()
            ->assertSee(route('issues.vote', [$project, $issue]));

        Membership::factory()->active()->create([
            'project_id' => $project->id,
            'user_id' => $owner->id,
        ]);
        $this->actingAs($owner)
            ->get(route('issues.show', [$project, $issue]))
            ->assertOk()
            ->assertSee('Jumlah Vote')
            ->assertDontSee(route('issues.vote', [$project, $issue]))
            ->assertDontSee(route('issues.unvote', [$project, $issue]));
    }
}
