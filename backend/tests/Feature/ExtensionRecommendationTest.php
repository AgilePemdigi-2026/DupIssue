<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExtensionRecommendationTest extends TestCase
{
    use DatabaseMigrations;

    private User $reporter;
    private Project $project;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::preventStrayRequests();
        Queue::fake();

        $this->reporter = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $this->project = $this->connectedProject($this->reporter);
        $auth = app(ExtensionAuthService::class);
        $this->token = $auth->exchange($auth->createPairingCode($this->reporter))['access_token'];
    }

    private function connectedProject(?User $reporter, string $repository = 'example/mobile-app'): Project
    {
        $project = Project::factory()->create();
        $connection = new GithubAppConnection();
        $connection->forceFill([
            'project_id' => $project->id,
            'installation_id' => 12345,
            'repository_id' => 67890,
            'repo_full_name' => $repository,
            'verified_github_user_id' => 99999,
            'verified_at' => now(),
        ])->save();

        if ($reporter !== null) {
            Membership::factory()->create([
                'project_id' => $project->id,
                'user_id' => $reporter->id,
                'is_active' => true,
            ]);
        }

        return $project;
    }

    private function issue(?Project $project = null, array $attributes = []): Issue
    {
        $project ??= $this->project;
        $issue = new Issue();
        $issue->forceFill(array_merge([
            'project_id' => $project->id,
            'author_id' => $project->owner_id,
            'title' => 'Existing login issue',
            'description' => 'The login button does not respond to clicks.',
            'status' => 'OPEN',
        ], $attributes))->save();

        return $issue;
    }

    private function url(): string
    {
        return route('extension.api.recommendations');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'repository' => 'EXAMPLE/MOBILE-APP',
            'project_id' => $this->project->id,
            'title' => 'Login button does not respond',
            'description' => 'The application does not respond after clicking the login button.',
        ], $overrides);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function assertNoWrites(int $issueCount): void
    {
        $this->assertDatabaseCount('issues', $issueCount);
        foreach (['votes', 'duplicate_candidates', 'jobs', 'activity_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNothingPushed();
    }

    public function test_bearer_token_is_required_even_with_web_session(): void
    {
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->actingAs($this->reporter)
            ->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->postJson($this->url(), $this->payload(), ['Authorization' => 'Bearer invalid'])
            ->assertUnauthorized();
        app(ExtensionAuthService::class)->revoke($this->token);
        $this->postJson($this->url(), $this->payload(), $this->headers())->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertNoWrites(0);
    }

    public function test_threshold_preserves_parent_resolution_and_web_ranking(): void
    {
        $parent = $this->issue();
        $child = $this->issue(attributes: ['status' => 'CLOSED', 'duplicate_of' => $parent->id]);
        $second = $this->issue();
        $third = $this->issue();
        $fourth = $this->issue();
        Http::fake(['http://nlp.test/candidates' => Http::response(['candidates' => [
            ['issue_id' => $fourth->id, 'similarity' => -0.1],
            ['issue_id' => $third->id, 'similarity' => 0.65],
            ['issue_id' => $second->id, 'similarity' => 0.65],
            ['issue_id' => $parent->id, 'similarity' => 0.8],
            ['issue_id' => $child->id, 'similarity' => 0.95],
        ]])]);
        $before = DB::table('issues')->orderBy('id')->get()->toJson();

        $extension = $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertOk()->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $child->id)
            ->assertJsonPath('recommendations.0.target_issue_id', $parent->id)
            ->assertJsonPath('recommendations.0.status', 'CLOSED')
            ->assertJsonPath('recommendations.0.detail_url', route('issues.show', [$this->project, $parent], false))
            ->assertJsonPath('recommendations.0.cosine_similarity', 0.95)
            ->assertJsonStructure(['recommendations' => [['issue_id', 'target_issue_id', 'title',
                'description', 'status', 'similarity', 'cosine_similarity', 'detail_url']]]);

        $web = $this->actingAs($this->reporter)
            ->postJson(route('issues.recommendations', $this->project), $this->payload())->assertOk();
        $web->assertJsonCount(3, 'recommendations')
            ->assertJsonPath('recommendations.1.issue_id', $second->id)
            ->assertJsonPath('recommendations.2.issue_id', $third->id)
            ->assertJsonPath('recommendations.2.cosine_similarity', 0.65);

        $this->assertSame(
            [$web->json('recommendations.0')],
            $extension->json('recommendations')
        );
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->data(), $requests[1][0]->data());
        $this->assertStringContainsString('no-store', $extension->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('issues')->orderBy('id')->get()->toJson());
        Http::assertSentCount(2);
        $this->assertNoWrites(5);
    }

    public function test_candidates_and_duplicate_parents_are_isolated_to_selected_project(): void
    {
        // Even another accessible project connected to the same repository must be isolated.
        $other = $this->connectedProject($this->reporter);
        $foreign = $this->issue($other);
        $valid = $this->issue();
        $invalid = $this->issue(attributes: ['status' => 'DUPLICATE', 'duplicate_of' => $foreign->id]);
        Http::fake(['http://nlp.test/candidates' => Http::response(['candidates' => [
            ['issue_id' => $foreign->id, 'similarity' => 1],
            ['issue_id' => $invalid->id, 'similarity' => 0.99],
            ['issue_id' => $valid->id, 'similarity' => 0.85],
        ]])]);

        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertOk()->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.issue_id', $valid->id);
        Http::assertSent(fn ($request) => $request['project_id'] === $this->project->id
            && array_column($request['candidates'], 'issue_id') === [$valid->id, $invalid->id]
            && array_unique(array_column($request['candidates'], 'project_id')) === [$this->project->id]);
        $this->assertNoWrites(3);
    }

    public function test_forged_projects_and_repositories_are_rejected_before_nlp(): void
    {
        $foreign = $this->connectedProject(null);
        $different = $this->connectedProject($this->reporter, 'example/website');
        foreach ([
            ['project_id' => $foreign->id],
            ['project_id' => $different->id],
            ['project_id' => 999999999],
            ['repository' => 'example/private-repo'],
        ] as $overrides) {
            $this->postJson($this->url(), $this->payload($overrides), $this->headers())->assertForbidden();
        }
        Http::assertNothingSent();
        $this->assertNoWrites(0);
    }

    public function test_membership_removal_and_repository_replacement_invalidate_previous_mapping(): void
    {
        $this->postJson(route('extension.api.repositories.resolve'),
            ['repository' => 'example/mobile-app'], $this->headers())
            ->assertOk()->assertJsonPath('projects.0.id', $this->project->id);

        $membership = Membership::where('project_id', $this->project->id)->firstOrFail();
        $membership->update(['is_active' => false]);
        $this->postJson($this->url(), $this->payload(), $this->headers())->assertForbidden();

        $membership->update(['is_active' => true]);
        DB::table('github_app_connections')->where('project_id', $this->project->id)
            ->update(['repo_full_name' => 'example/replacement', 'repository_id' => 98765]);
        $this->postJson($this->url(), $this->payload(), $this->headers())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_invalid_draft_and_mapping_input_are_rejected(): void
    {
        foreach ([
            'title' => [null, '', '1234', str_repeat('x', 201), ['invalid']],
            'description' => [null, '', '123456789', str_repeat('x', 10001), ['invalid']],
            'project_id' => [null, 0, -1, 1.5, ['invalid']],
            'repository' => [null, 'https://github.com/example/mobile-app', 'example/..', ['invalid']],
        ] as $field => $values) {
            foreach ($values as $value) {
                $this->postJson($this->url(), $this->payload([$field => $value]), $this->headers())
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
        Http::assertNothingSent();
        $this->assertNoWrites(0);
    }

    public function test_empty_project_accepts_boundary_lengths_without_nlp(): void
    {
        foreach ([[5, 10], [200, 10000]] as [$titleLength, $descriptionLength]) {
            $this->postJson($this->url(), $this->payload([
                'title' => str_repeat('x', $titleLength),
                'description' => str_repeat('x', $descriptionLength),
            ]), $this->headers())->assertOk()->assertExactJson(['recommendations' => []]);
        }
        Http::assertNothingSent();
        $this->assertNoWrites(0);
    }

    public function test_nlp_failure_returns_safe_503_without_upstream_details(): void
    {
        $this->issue();
        Http::fake(['http://nlp.test/candidates' => Http::sequence()
            ->push(['detail' => 'Private upstream diagnostic'], 500)
            ->push('invalid JSON', 200)
            ->push(['candidates' => [['issue_id' => 1, 'similarity' => 'NaN']]], 200)
            ->pushFailedConnection()]);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $response = $this->postJson($this->url(), $this->payload(), $this->headers())
                ->assertStatus(503)->assertExactJson([
                    'recommendations' => [],
                    'code' => 'NLP_UNAVAILABLE',
                    'message' => 'Rekomendasi belum dapat dimuat',
                ]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertSentCount(4);
        $this->assertNoWrites(1);
    }

    public function test_membership_revoked_during_nlp_discards_recommendations(): void
    {
        $issue = $this->issue();
        Http::fake(function () use ($issue) {
            Membership::where('project_id', $this->project->id)->delete();

            return Http::response(['candidates' => [['issue_id' => $issue->id, 'similarity' => 0.9]]]);
        });
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertForbidden()->assertJsonMissingPath('recommendations');
        Http::assertSentCount(1);
        $this->assertNoWrites(1);
    }

    public function test_token_revoked_during_nlp_discards_recommendations(): void
    {
        $issue = $this->issue();
        Http::fake(function () use ($issue) {
            app(ExtensionAuthService::class)->revoke($this->token);

            return Http::response(['candidates' => [['issue_id' => $issue->id, 'similarity' => 0.9]]]);
        });
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJsonPath('code', 'UNAUTHENTICATED')->assertJsonMissingPath('recommendations');
        Http::assertSentCount(1);
        $this->assertNoWrites(1);
    }

    public function test_auth_version_change_during_nlp_discards_recommendations(): void
    {
        $issue = $this->issue();
        Http::fake(function () use ($issue) {
            User::whereKey($this->reporter->id)->increment('auth_version');

            return Http::response(['candidates' => [['issue_id' => $issue->id, 'similarity' => 0.9]]]);
        });
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertUnauthorized()->assertJsonMissingPath('recommendations');
        Http::assertSentCount(1);
    }

    public function test_token_expiry_during_nlp_discards_recommendations(): void
    {
        $issue = $this->issue();
        Http::fake(function () use ($issue) {
            DB::table('extension_sessions')->where('user_id', $this->reporter->id)
                ->update(['access_expires_at' => now()]);

            return Http::response(['candidates' => [['issue_id' => $issue->id, 'similarity' => 0.9]]]);
        });
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertUnauthorized()->assertJsonMissingPath('recommendations');
        Http::assertSentCount(1);
    }

    public function test_repository_replaced_during_nlp_discards_recommendations(): void
    {
        $issue = $this->issue();
        Http::fake(function () use ($issue) {
            DB::table('github_app_connections')->where('project_id', $this->project->id)
                ->update(['repo_full_name' => 'example/replacement', 'repository_id' => 98765]);

            return Http::response(['candidates' => [['issue_id' => $issue->id, 'similarity' => 0.9]]]);
        });
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertForbidden()->assertJsonMissingPath('recommendations');
        Http::assertSentCount(1);
        $this->assertNoWrites(1);
    }

    public function test_revocation_is_checked_even_when_nlp_fails(): void
    {
        $this->issue();
        Http::fake(function () {
            app(ExtensionAuthService::class)->revoke($this->token);

            return Http::response(['detail' => 'Private upstream diagnostic'], 503);
        });
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertUnauthorized()->assertJsonMissingPath('recommendations');
        Http::assertSentCount(1);
    }

    public function test_recommendation_requests_are_rate_limited_before_nlp(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson($this->url(), $this->payload(), $this->headers())->assertOk();
        }
        $this->issue();
        $this->postJson($this->url(), $this->payload(), $this->headers())
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonMissingPath('recommendations');
        Http::assertNothingSent();
        $this->assertNoWrites(1);
    }

    public static function thresholdCases(): array
    {
        return [
            'none qualifies' => [
                [0.749999, 0.65, -0.4],
                [],
            ],
            'inclusive threshold' => [
                [0.75, 0.749999, -0.4],
                [0],
            ],
            'two qualify' => [
                [0.98, 0.80, 0.70],
                [0, 1],
            ],
            'three qualify' => [
                [0.98, 0.92, 0.85],
                [0, 1, 2],
            ],
            'maximum three sorted' => [
                [0.85, 0.99, 0.95, 0.90],
                [1, 2, 3],
            ],
            'equal scores use ascending issue ID' => [
                [0.90, 0.90, 0.90, 0.90],
                [0, 1, 2],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('thresholdCases')]
    public function test_extension_returns_only_eligible_top_three(
        array $scores,
        array $expectedIndexes
    ): void {
        $issues = [];
        $candidates = [];

        foreach ($scores as $index => $score) {
            $issue = $this->issue(attributes: [
                'title' => 'Threshold candidate '.$index,
            ]);

            $issues[] = $issue;

            $candidates[] = [
                'issue_id' => $issue->id,
                'similarity' => $score,
            ];
        }

        Http::fake([
            'http://nlp.test/candidates' => Http::response([
                'candidates' => array_reverse($candidates),
            ]),
        ]);

        $response = $this->postJson(
            $this->url(),
            $this->payload(),
            $this->headers()
        )->assertOk();

        $recommendations = $response->json('recommendations');

        $expectedIds = array_map(
            fn ($index) => (int) $issues[$index]->id,
            $expectedIndexes
        );

        $this->assertCount(
            count($expectedIndexes),
            $recommendations
        );

        $this->assertSame(
            $expectedIds,
            array_column($recommendations, 'issue_id')
        );

        foreach ($recommendations as $row) {
            $this->assertGreaterThanOrEqual(
                0.75,
                $row['cosine_similarity']
            );
        }

        Http::assertSentCount(1);
        $this->assertNoWrites(count($scores));
    }

}
