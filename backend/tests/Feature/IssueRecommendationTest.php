<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IssueRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nlp.base_url' => 'http://127.0.0.1:8001',
        ]);

        Http::preventStrayRequests();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function makeProject(): Project
    {
        $owner = $this->makeUser('MAINTAINER');

        $project = new Project([
            'name' => 'Recommendation Test '.uniqid(),
            'description' => 'Project pengujian Feature 8',
        ]);

        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    private function makeReporter(
        Project $project,
        bool $active = true
    ): User {
        $reporter = $this->makeUser('REPORTER');

        Membership::create([
            'project_id' => $project->id,
            'user_id' => $reporter->id,
            'is_active' => $active,
            'joined_at' => now(),
            'removed_at' => $active ? null : now(),
        ]);

        return $reporter;
    }

    private function makeIssue(
        Project $project,
        string $title
    ): Issue {
        $issue = new Issue([
            'title' => $title,
            'description' => 'Deskripsi pengujian issue serupa.',
        ]);

        $issue->project()->associate($project);
        $issue->author()->associate($project->owner);
        $issue->save();

        return $issue;
    }

    private function payload(): array
    {
        return [
            'title' => 'Tombol bayar tidak merespons',
            'description' => 'Checkout gagal saat pembayaran.',
        ];
    }

    public function test_returns_top_three_from_same_project(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);

        $a = $this->makeIssue($project, 'Issue A');
        $b = $this->makeIssue($project, 'Issue B');
        $c = $this->makeIssue($project, 'Issue C');
        $d = $this->makeIssue($project, 'Issue D');

        $otherProject = $this->makeProject();
        $outside = $this->makeIssue($otherProject, 'Foreign issue');

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $outside->id, 'similarity' => 0.99],
                    ['issue_id' => $a->id, 'similarity' => 0.20],
                    ['issue_id' => $d->id, 'similarity' => 0.70],
                    ['issue_id' => $b->id, 'similarity' => 0.91],
                    ['issue_id' => $c->id, 'similarity' => 0.80],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertOk()
            ->assertJsonCount(3, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $b->id)
            ->assertJsonPath('recommendations.1.issue_id', $c->id)
            ->assertJsonPath('recommendations.2.issue_id', $d->id)
            ->assertJsonPath('recommendations.0.similarity', 0.91);

        Http::assertSent(function ($request) use ($project, $outside) {
            $ids = array_column(
                $request['candidates'],
                'issue_id'
            );

            return (int) $request['project_id'] === (int) $project->id
                && $request['new_issue']['issue_id'] === 'draft'
                && ! in_array($outside->id, $ids, true);
        });

        Http::assertSentCount(1);
        $this->assertDatabaseCount('issues', 5);
    }

    public function test_empty_project_returns_empty_recommendations(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);

        Http::fake();

        $this->actingAs($reporter)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertOk()
            ->assertExactJson(['recommendations' => []]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_invalid_draft_is_rejected(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);

        Http::fake();

        $this->actingAs($reporter)
            ->postJson(
                route('issues.recommendations', $project),
                [
                    'title' => 'abc',
                    'description' => 'short',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title',
                'description',
            ]);

        Http::assertNothingSent();
    }

    public function test_guest_and_inactive_reporter_are_rejected(): void
    {
        $project = $this->makeProject();

        Http::fake();

        $this->postJson(
            route('issues.recommendations', $project),
            $this->payload()
        )->assertUnauthorized();

        $inactiveReporter = $this->makeReporter($project, false);

        $this->actingAs($inactiveReporter)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_nlp_failure_returns_safe_503_response(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);

        $this->makeIssue($project, 'Existing issue');

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'detail' => 'Service unavailable',
            ], 503),
        ]);

        $this->actingAs($reporter)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertStatus(503)
            ->assertJsonPath('code', 'NLP_UNAVAILABLE')
            ->assertJsonPath(
                'message',
                'Rekomendasi belum dapat dimuat'
            );

        $this->assertDatabaseCount('issues', 1);
    }


    public function test_duplicate_candidate_links_to_parent_and_avoids_repeated_target(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);

        $parent = $this->makeIssue($project, 'Issue Induk');

        $childA = $this->makeIssue($project, 'Duplikat A');
        $childA->status = 'DUPLICATE';
        $childA->duplicate_of = $parent->id;
        $childA->save();

        $childB = $this->makeIssue($project, 'Duplikat B');
        $childB->status = 'DUPLICATE';
        $childB->duplicate_of = $parent->id;
        $childB->save();

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $childB->id, 'similarity' => 0.85],
                    ['issue_id' => $parent->id, 'similarity' => 0.80],
                    ['issue_id' => $childA->id, 'similarity' => 0.95],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $childA->id)
            ->assertJsonPath('recommendations.0.target_issue_id', $parent->id)
            ->assertJsonPath('recommendations.0.status', 'DUPLICATE')
            ->assertJsonPath(
                'recommendations.0.detail_url',
                route('issues.show', [
                    'project' => $project,
                    'issue' => $parent,
                ], false)
            );

        $this->assertDatabaseCount('issues', 3);
    }

    public function test_duplicate_with_parent_in_another_project_is_excluded(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);

        $otherProject = $this->makeProject();
        $foreignParent = $this->makeIssue(
            $otherProject,
            'Induk dari Project Lain'
        );

        $invalidDuplicate = $this->makeIssue(
            $project,
            'Duplikat Tidak Valid'
        );
        $invalidDuplicate->status = 'DUPLICATE';
        $invalidDuplicate->duplicate_of = $foreignParent->id;
        $invalidDuplicate->save();

        $valid = $this->makeIssue($project, 'Issue Valid');

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $invalidDuplicate->id, 'similarity' => 0.99],
                    ['issue_id' => $valid->id, 'similarity' => 0.65],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $valid->id)
            ->assertJsonPath('recommendations.0.target_issue_id', $valid->id);

        Http::assertSent(function ($request) use ($foreignParent) {
            $ids = array_column($request['candidates'], 'issue_id');

            return ! in_array($foreignParent->id, $ids, true);
        });
    }


    public function test_closed_child_with_duplicate_of_links_to_original_parent(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);
        $parent = $this->makeIssue($project, 'Original checkout issue');
        $child = $this->makeIssue($project, 'Previously closed duplicate');
        $child->status = 'CLOSED';
        $child->duplicate_of = $parent->id;
        $child->save();

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $child->id, 'similarity' => 0.87],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(route('issues.recommendations', $project), $this->payload())
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $child->id)
            ->assertJsonPath('recommendations.0.target_issue_id', $parent->id)
            ->assertJsonPath('recommendations.0.status', 'CLOSED')
            ->assertJsonPath('recommendations.0.cosine_similarity', 0.87)
            ->assertJsonPath('recommendations.0.detail_url', route('issues.show', [
                'project' => $project, 'issue' => $parent,
            ], false));

        $child->refresh();
        $this->assertSame('CLOSED', $child->status);
        $this->assertSame((int) $parent->id, (int) $child->duplicate_of);
        $this->assertDatabaseCount('issues', 2);
    }

    public function test_recommendations_skip_self_parent_and_nested_parent(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);
        $root = $this->makeIssue($project, 'Root issue');
        $intermediate = $this->makeIssue($project, 'Intermediate duplicate');
        $intermediate->status = 'DUPLICATE';
        $intermediate->duplicate_of = $root->id;
        $intermediate->save();
        $nested = $this->makeIssue($project, 'Nested child');
        $nested->status = 'CLOSED';
        $nested->duplicate_of = $intermediate->id;
        $nested->save();
        $self = $this->makeIssue($project, 'Self reference');
        $self->status = 'CLOSED';
        $self->duplicate_of = $self->id;
        $self->save();
        $orphan = $this->makeIssue($project, 'Unlinked duplicate');
        $orphan->status = 'DUPLICATE';
        $orphan->save();

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $nested->id, 'similarity' => 0.97],
                    ['issue_id' => $self->id, 'similarity' => 0.96],
                    ['issue_id' => $orphan->id, 'similarity' => 0.95],
                    ['issue_id' => $root->id, 'similarity' => 0.65],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(route('issues.recommendations', $project), $this->payload())
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $root->id)
            ->assertJsonPath('recommendations.0.target_issue_id', $root->id);
    }

    public function test_signed_cosine_and_nonnegative_display_score_include_boundaries(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);
        $negative = $this->makeIssue($project, 'Negative cosine');
        $zero = $this->makeIssue($project, 'Zero cosine');
        $positive = $this->makeIssue($project, 'Positive cosine');

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $negative->id, 'similarity' => -1.0],
                    ['issue_id' => $zero->id, 'similarity' => 0.0],
                    ['issue_id' => $positive->id, 'similarity' => 1.0],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(route('issues.recommendations', $project), $this->payload())
            ->assertOk()
            ->assertJsonCount(3, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $positive->id)
            ->assertJsonPath('recommendations.0.similarity', 1)
            ->assertJsonPath('recommendations.0.cosine_similarity', 1)
            ->assertJsonPath('recommendations.1.issue_id', $zero->id)
            ->assertJsonPath('recommendations.1.similarity', 0)
            ->assertJsonPath('recommendations.1.cosine_similarity', 0)
            ->assertJsonPath('recommendations.2.issue_id', $negative->id)
            ->assertJsonPath('recommendations.2.similarity', 0)
            ->assertJsonPath('recommendations.2.cosine_similarity', -1);
    }

    public function test_non_finite_and_out_of_range_cosine_scores_are_ignored(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);
        $nonFinite = $this->makeIssue($project, 'Overflow cosine');
        $outOfRange = $this->makeIssue($project, 'Invalid cosine');
        $valid = $this->makeIssue($project, 'Valid cosine');

        // Valid JSON containing an exponent that PHP decodes to INF.
        $rawJson = '{"candidates":['
            .'{"issue_id":'.$nonFinite->id.',"similarity":1e400},'
            .'{"issue_id":'.$outOfRange->id.',"similarity":1.5},'
            .'{"issue_id":'.$valid->id.',"similarity":0.64}'
            .']}';

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response(
                $rawJson,
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $this->actingAs($reporter)
            ->postJson(route('issues.recommendations', $project), $this->payload())
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $valid->id)
            ->assertJsonPath('recommendations.0.cosine_similarity', 0.64);
    }

    public function test_invalid_string_similarity_is_rejected_safely(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeReporter($project);
        $candidate = $this->makeIssue($project, 'Invalid string score');

        Http::fake([
            'http://127.0.0.1:8001/candidates' => Http::response([
                'candidates' => [
                    ['issue_id' => $candidate->id, 'similarity' => 'NaN'],
                ],
            ], 200),
        ]);

        $this->actingAs($reporter)
            ->postJson(route('issues.recommendations', $project), $this->payload())
            ->assertStatus(503)
            ->assertJsonPath('code', 'NLP_UNAVAILABLE');
    }

    public function test_maintainer_and_non_member_reporter_are_rejected(): void
    {
        $project = $this->makeProject();

        Http::fake();

        $this->actingAs($project->owner)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertForbidden();

        $nonMember = $this->makeUser('REPORTER');

        $this->actingAs($nonMember)
            ->postJson(
                route('issues.recommendations', $project),
                $this->payload()
            )
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
