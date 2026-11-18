<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppJwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubAppSetupTest extends TestCase
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

        config()->set('services.github_app.app_id', 5814379);

        $jwtService = $this->createMock(
            GithubAppJwtService::class
        );

        $jwtService->method('generate')
            ->willReturn('fake.jwt.signature');

        $this->app->instance(
            GithubAppJwtService::class,
            $jwtService
        );
    }

    private function pending(): array
    {
        return [
            'state_hash' => hash('sha256', self::STATE),
            'project_id' => (int) $this->project->id,
            'actor_id' => (int) $this->owner->id,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];
    }

    private function setupUrl(
        string $state = self::STATE
    ): string {
        return '/integrations/github/setup?'
            . http_build_query([
                'state' => $state,
                'installation_id' => '12345',
                'setup_action' => 'install',
            ]);
    }

    private function fakeInstallation(
        int $status = 200,
        ?array $body = null
    ): void {
        Http::fake([
            'https://api.github.com/app/installations/12345' =>
                Http::response(
                    $body ?? [
                        'id' => 12345,
                        'app_id' => 5814379,
                        'permissions' => [
                            'issues' => 'read',
                            'metadata' => 'read',
                        ],
                        'suspended_at' => null,
                    ],
                    $status
                ),
        ]);
    }

    public function test_valid_setup_redirects_to_oauth(): void
    {
        $this->fakeInstallation();

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_install' =>
                    $this->pending(),
            ])
            ->get($this->setupUrl());

        $response->assertRedirect(
            route('sources.github.app.authorize')
        );

        $response->assertSessionMissing(
            'source_issue.github_app_install'
        );

        $response->assertSessionHas(
            'source_issue.github_app_pending_oauth',
            function ($data) {
                return $data['project_id'] ===
                        (int) $this->project->id
                    && $data['actor_id'] ===
                        (int) $this->owner->id
                    && $data['installation_id'] === 12345
                    && $data['expires_at'] > now()->timestamp;
            }
        );

        Http::assertSentCount(1);
    }

    public function test_wrong_state_is_rejected(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_install' =>
                    $this->pending(),
            ])
            ->get($this->setupUrl(str_repeat('b', 64)))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_expired_session_is_rejected(): void
    {
        Http::fake();

        $pending = $this->pending();

        $pending['expires_at'] = now()
            ->subMinute()
            ->timestamp;

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_install' => $pending,
            ])
            ->get($this->setupUrl())
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_different_user_is_rejected(): void
    {
        Http::fake();

        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->actingAs($other)
            ->withSession([
                'source_issue.github_app_install' =>
                    $this->pending(),
            ])
            ->get($this->setupUrl())
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_unverified_installation_is_rejected(): void
    {
        $this->fakeInstallation(404, [
            'message' => 'Not Found',
        ]);

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_install' =>
                    $this->pending(),
            ])
            ->get($this->setupUrl())
            ->assertForbidden()
            ->assertSessionMissing(
                'source_issue.github_app_pending_oauth'
            );

        Http::assertSentCount(1);
    }

    public function test_missing_issue_permission_is_rejected(): void
    {
        $this->fakeInstallation(200, [
            'id' => 12345,
            'app_id' => 5814379,
            'permissions' => [
                'metadata' => 'read',
            ],
            'suspended_at' => null,
        ]);

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_install' =>
                    $this->pending(),
            ])
            ->get($this->setupUrl())
            ->assertForbidden()
            ->assertSessionMissing(
                'source_issue.github_app_pending_oauth'
            );

        Http::assertSentCount(1);
    }
}
