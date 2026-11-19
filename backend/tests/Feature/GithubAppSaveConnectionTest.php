<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppInstallationTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubAppSaveConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Project $project;

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
    }

    private function pending(): array
    {
        return [
            'project_id' => (int) $this->project->id,
            'actor_id' => (int) $this->owner->id,
            'installation_id' => 12345,
            'github_user_id' => 999,
            'repositories' => [
                [
                    'id' => 5678,
                    'full_name' => 'example/test-repo',
                ],
            ],
            'expires_at' => now()->addMinutes(10)->timestamp,
        ];
    }

    private function saveUrl(): string
    {
        return route('sources.github.app.save', [
            'project' => $this->project->id,
        ]);
    }

    private function mockTokenService(
        bool $shouldSucceed = true
    ): void {
        $mock = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        if ($shouldSucceed) {
            $mock->expects($this->once())
                ->method('createForRepository')
                ->with(12345, 5678)
                ->willReturn('fake-installation-token');
        } else {
            $mock->expects($this->once())
                ->method('createForRepository')
                ->willThrowException(
                    new RuntimeException('Simulated GitHub failure.')
                );
        }

        $this->app->instance(
            GithubAppInstallationTokenService::class,
            $mock
        );
    }

    public function test_verified_repository_can_be_saved(): void
    {
        $this->mockTokenService();

        Http::fake([
            'https://api.github.com/repos/example/test-repo' =>
                Http::response([
                    'id' => 5678,
                    'full_name' => 'example/test-repo',
                ], 200),
        ]);

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->pending(),
            ])
            ->post($this->saveUrl(), [
                'repository_id' => 5678,
            ]);

        $response->assertRedirect(
            route('sources.index', [
                'project' => $this->project->id,
            ])
        );

        $response->assertSessionMissing(
            'source_issue.github_app_verified_repositories'
        );

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $this->project->id,
            'installation_id' => 12345,
            'repository_id' => 5678,
            'repo_full_name' => 'example/test-repo',
            'verified_github_user_id' => 999,
        ]);

        $this->assertSame(
            1,
            GithubAppConnection::query()->count()
        );

        Http::assertSentCount(1);

        Http::assertSent(fn ($request) =>
            $request->method() === 'GET'
            && $request->url() ===
                'https://api.github.com/repos/example/test-repo'
            && $request->hasHeader(
                'Authorization',
                'Bearer fake-installation-token'
            )
        );
    }

    public function test_unverified_repository_is_rejected(): void
    {
        Http::fake();

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->pending(),
            ])
            ->post($this->saveUrl(), [
                'repository_id' => 99999,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount(
            'github_app_connections',
            0
        );

        Http::assertNothingSent();
    }

    public function test_mismatched_repository_identity_is_rejected(): void
    {
        $this->mockTokenService();

        Http::fake([
            'https://api.github.com/repos/example/test-repo' =>
                Http::response([
                    'id' => 99999,
                    'full_name' => 'example/test-repo',
                ], 200),
        ]);

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->pending(),
            ])
            ->post($this->saveUrl(), [
                'repository_id' => 5678,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount(
            'github_app_connections',
            0
        );
    }

    public function test_non_owner_cannot_save_connection(): void
    {
        Http::fake();

        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->actingAs($other)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->pending(),
            ])
            ->post($this->saveUrl(), [
                'repository_id' => 5678,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount(
            'github_app_connections',
            0
        );

        Http::assertNothingSent();
    }

    public function test_expired_verification_is_rejected(): void
    {
        Http::fake();

        $pending = $this->pending();

        $pending['expires_at'] = now()
            ->subMinute()
            ->timestamp;

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $pending,
            ])
            ->post($this->saveUrl(), [
                'repository_id' => 5678,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount(
            'github_app_connections',
            0
        );

        Http::assertNothingSent();
    }

    public function test_token_failure_does_not_save_connection(): void
    {
        $this->mockTokenService(false);

        Http::fake();

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_verified_repositories'
                    => $this->pending(),
            ])
            ->post($this->saveUrl(), [
                'repository_id' => 5678,
            ])
            ->assertStatus(502);

        $this->assertDatabaseCount(
            'github_app_connections',
            0
        );

        Http::assertNothingSent();
    }
}
