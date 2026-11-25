<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubAppOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Project $project;

    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->id,
        ]);

        config()->set('services.github_app.client_id', 'test-client-id');
        config()->set('services.github_app.client_secret', 'fake-test-secret');
        config()->set(
            'services.github_app.callback_url',
            'http://127.0.0.1:8000/integrations/github/callback'
        );
    }

    private function pending(): array
    {
        return [
            'state_hash' => hash('sha256', self::STATE),
            'code_verifier' => str_repeat('v', 43),
            'project_id' => (int) $this->project->id,
            'actor_id' => (int) $this->owner->id,
            'installation_id' => 12345,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ];
    }

    private function callbackUrl(string $state = self::STATE): string
    {
        return '/integrations/github/callback?'
            . http_build_query([
                'state' => $state,
                'code' => 'test-authorization-code',
            ]);
    }

    private function fakeSuccessfulGithub(): void
    {
        Http::fake([
            'https://github.com/login/oauth/access_token' =>
                Http::response([
                    'access_token' => 'fake-user-token',
                    'token_type' => 'bearer',
                ], 200),

            'https://api.github.com/user' =>
                Http::response([
                    'id' => 999,
                    'login' => 'test-maintainer',
                ], 200),

            'https://api.github.com/user/installations/12345/repositories*' =>
                Http::response([
                    'total_count' => 1,
                    'repositories' => [
                        [
                            'id' => 5678,
                            'full_name' => 'example/test-repo',
                        ],
                    ],
                ], 200),
        ]);
    }

    public function test_successful_callback_verifies_repository(): void
    {
        $this->fakeSuccessfulGithub();

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_oauth' => $this->pending(),
            ])
            ->get($this->callbackUrl());

        $response->assertRedirect(
            route('sources.index', [
                'project' => $this->project->id,
            ])
        );

        $response->assertSessionHas(
            'source_issue.github_app_verified_repositories',
            function ($data) {
                return $data['project_id'] === (int) $this->project->id
                    && $data['actor_id'] === (int) $this->owner->id
                    && $data['installation_id'] === 12345
                    && $data['github_user_id'] === 999
                    && $data['repositories'] === [
                        [
                            'id' => 5678,
                            'full_name' => 'example/test-repo',
                        ],
                    ]
                    && ! array_key_exists('access_token', $data);
            }
        );

        $response->assertSessionMissing(
            'source_issue.github_app_oauth'
        );

        Http::assertSentCount(3);
    }

    public function test_callback_cannot_reuse_consumed_state(): void
    {
        $this->fakeSuccessfulGithub();

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_oauth' => $this->pending(),
            ])
            ->get($this->callbackUrl())
            ->assertRedirect();

        $this->get($this->callbackUrl())
            ->assertForbidden();

        Http::assertSentCount(3);
    }

    public function test_wrong_state_is_rejected_without_api_calls(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_oauth' => $this->pending(),
            ])
            ->get($this->callbackUrl(str_repeat('b', 64)))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_inaccessible_installation_is_rejected(): void
    {
        Http::fake([
            'https://github.com/login/oauth/access_token' =>
                Http::response([
                    'access_token' => 'fake-user-token',
                    'token_type' => 'bearer',
                ], 200),

            'https://api.github.com/user' =>
                Http::response([
                    'id' => 999,
                    'login' => 'test-maintainer',
                ], 200),

            'https://api.github.com/user/installations/12345/repositories*' =>
                Http::response([
                    'message' => 'Resource not accessible',
                ], 403),
        ]);

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_oauth' => $this->pending(),
            ])
            ->get($this->callbackUrl())
            ->assertForbidden()
            ->assertSessionMissing(
                'source_issue.github_app_verified_repositories'
            );

        Http::assertSentCount(3);
    }
}
