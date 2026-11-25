<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(): array
    {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        return [$owner, $project];
    }

    public function test_owner_can_test_github_connection(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/issues')) {
                return Http::response([], 200);
            }

            return Http::response([
                'full_name' => 'demo/bugs',
                'private' => true,
                'has_issues' => true,
            ], 200);
        });

        $response = $this->actingAs($owner)->postJson(
            route('sources.github.test', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'secret-test-token',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'repository.repo_full_name',
                'demo/bugs'
            );

        $this->assertStringNotContainsString(
            'secret-test-token',
            $response->getContent()
        );

        $this->assertDatabaseCount('github_configs', 0);

        Http::assertSentCount(2);
    }

    public function test_reporter_cannot_test_connection(): void
    {
        [, $project] = $this->makeProject();

        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        Http::fake();

        $this->actingAs($reporter)
            ->postJson(
                route('sources.github.test', $project),
                [
                    'repo_full_name' => 'demo/bugs',
                    'pat' => 'dummy-token',
                ]
            )
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_other_maintainer_cannot_test_connection(): void
    {
        [, $project] = $this->makeProject();

        $otherMaintainer = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        Http::fake();

        $this->actingAs($otherMaintainer)
            ->postJson(
                route('sources.github.test', $project),
                [
                    'repo_full_name' => 'demo/bugs',
                    'pat' => 'dummy-token',
                ]
            )
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_guest_cannot_test_connection(): void
    {
        [, $project] = $this->makeProject();

        Http::fake();

        $this->postJson(
            route('sources.github.test', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'dummy-token',
            ]
        )->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_invalid_input_is_rejected(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake();

        $this->actingAs($owner)
            ->postJson(
                route('sources.github.test', $project),
                [
                    'repo_full_name' => '',
                    'pat' => '',
                ]
            )
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors([
                'repo_full_name',
                'pat',
            ]);

        Http::assertNothingSent();

        $this->assertDatabaseCount('github_configs', 0);
    }

    public function test_invalid_github_token_returns_safe_error(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake([
            '*' => Http::response([
                'message' => 'Bad credentials',
            ], 401),
        ]);

        $response = $this->actingAs($owner)
            ->postJson(
                route('sources.github.test', $project),
                [
                    'repo_full_name' => 'demo/bugs',
                    'pat' => 'secret-invalid-token',
                ]
            );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertStringNotContainsString(
            'secret-invalid-token',
            $response->getContent()
        );

        $this->assertDatabaseCount('github_configs', 0);
    }

    public function test_github_rate_limit_returns_http_429(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake([
            '*' => Http::response([
                'message' => 'API rate limit exceeded',
            ], 403, [
                'X-RateLimit-Remaining' => '0',
            ]),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('sources.github.test', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'secret-test-token',
            ]
        );

        $response->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertStringContainsString(
            'rate limit',
            $response->json('message')
        );

        $this->assertStringNotContainsString(
            'secret-test-token',
            $response->getContent()
        );

        $this->assertDatabaseCount('github_configs', 0);
    }
}
