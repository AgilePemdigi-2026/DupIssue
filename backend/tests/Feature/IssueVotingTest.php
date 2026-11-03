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

        // Buat issue dengan mengisi atribut satu per satu.
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

        // Reporter memiliki akses ke project kedua,
        // tetapi issue yang digunakan berasal dari project pertama.
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
}
