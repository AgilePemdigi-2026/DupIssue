<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppInstallationTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubAppAutomaticConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.github_app.app_id', '1234');
        config()->set('services.github_app.slug', 'test-github-app');
        config()->set('services.github_app.client_id', 'test-client');
        config()->set('services.github_app.client_secret', 'test-secret');
        config()->set(
            'services.github_app.callback_url',
            'http://127.0.0.1:8000/integrations/github/callback'
        );
    }

    private function fixtures(): array
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);
        return [$owner, $project];
    }

    private function oauthSession(User $owner, Project $project): array
    {
        $state = str_repeat('a', 64);
        return [
            'source_issue.github_app_oauth' => [
                'state_hash' => hash('sha256', $state),
                'code_verifier' => str_repeat('v', 43),
                'project_id' => (int) $project->id,
                'actor_id' => (int) $owner->id,
                'installation_id' => null,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ];
    }

    private function fakeGithub(array $installations, array $repos): void
    {
        Http::fake(function (HttpRequest $request) use ($installations, $repos) {
            $url = $request->url();

            if (
                $request->method() === 'POST'
                && str_starts_with(
                    $url, 'https://github.com/login/oauth/access_token'
                )
            ) {
                return Http::response([
                    'access_token' => 'fake-user-token',
                    'token_type' => 'bearer',
                ], 200);
            }

            if (str_starts_with($url, 'https://api.github.com/user/installations?')) {
                return Http::response([
                    'total_count' => count($installations),
                    'installations' => $installations,
                ], 200);
            }

            if (preg_match(
                '~^https://api\\.github\\.com/user/installations/([0-9]+)/repositories\\?~',
                $url, $match
            )) {
                $id = (int) $match[1];
                $items = $repos[$id] ?? [];
                return Http::response([
                    'total_count' => count($items),
                    'repositories' => $items,
                ], 200);
            }

            if ($url === 'https://api.github.com/user') {
                return Http::response([
                    'id' => 999,
                    'login' => 'example-user',
                ], 200);
            }

            if (preg_match('~^https://api\\.github\\.com/repos/(.+)$~', $url, $match)) {
                foreach ($repos as $items) {
                    foreach ($items as $repo) {
                        if ($repo['full_name'] === $match[1]) {
                            return Http::response($repo, 200);
                        }
                    }
                }
            }

            return Http::response(['message' => 'Not mocked'], 404);
        });
    }

    private function installation(int $id = 111): array
    {
        return [
            'id' => $id,
            'app_id' => 1234,
            'permissions' => ['issues' => 'read'],
            'suspended_at' => null,
        ];
    }

    private function repository(int $id, string $fullName): array
    {
        return ['id' => $id, 'full_name' => $fullName];
    }

    private function mockInstallationToken(
        int $installationId,
        int $repositoryId
    ): void {
        $mock = $this->createMock(GithubAppInstallationTokenService::class);
        $mock->expects($this->once())
            ->method('createForRepository')
            ->with($installationId, $repositoryId)
            ->willReturn('fake-installation-token');
        $this->app->instance(GithubAppInstallationTokenService::class, $mock);
    }

    public function test_connect_button_starts_oauth_without_installation_id(): void
    {
        [$owner, $project] = $this->fixtures();
        $response = $this->actingAs($owner)->post(route(
            'sources.github.app.connect', ['project' => $project->id]
        ));

        $response->assertRedirect(route('sources.github.app.authorize'));
        $response->assertSessionHas(
            'source_issue.github_app_pending_oauth',
            fn ($data) => $data['project_id'] === (int) $project->id
                && $data['actor_id'] === (int) $owner->id
                && array_key_exists('installation_id', $data)
                && $data['installation_id'] === null
        );
        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_start_auto_connection(): void
    {
        [$owner, $project] = $this->fixtures();
        $other = User::factory()->create(['role' => 'MAINTAINER']);
        $this->actingAs($other)
            ->post(route('sources.github.app.connect', ['project' => $project->id]))
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_one_accessible_repo_is_saved_automatically(): void
    {
        [$owner, $project] = $this->fixtures();
        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [$this->repository(222, 'example/single-repo')]]
        );
        $this->mockInstallationToken(111, 222);

        $response = $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));

        $response->assertRedirect(route('sources.index', [
            'project' => $project->id,
        ]));
        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 111,
            'repository_id' => 222,
            'repo_full_name' => 'example/single-repo',
        ]);
        $response->assertSessionMissing(
            'source_issue.github_app_verified_repositories'
        );
        Http::assertSentCount(5);
    }

    public function test_multiple_repos_require_selection_without_autosaving(): void
    {
        [$owner, $project] = $this->fixtures();
        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [
                $this->repository(222, 'example/first'),
                $this->repository(333, 'example/second'),
            ]]
        );

        $response = $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));

        $response->assertRedirect(route('sources.index', [
            'project' => $project->id,
        ]));
        $this->assertDatabaseCount('github_app_connections', 0);
        $response->assertSessionHas(
            'source_issue.github_app_verified_repositories',
            fn ($data) => count($data['repositories']) === 2
        );

        $this->mockInstallationToken(111, 333);
        $this->actingAs($owner)->post(
            route('sources.github.app.save', ['project' => $project->id]),
            ['repository_id' => 333]
        )->assertRedirect(route('sources.index', ['project' => $project->id]));

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 111,
            'repository_id' => 333,
            'repo_full_name' => 'example/second',
        ]);
    }

    public function test_multiple_installations_bind_repo_to_its_own_installation(): void
    {
        [$owner, $project] = $this->fixtures();
        $this->fakeGithub(
            [$this->installation(111), $this->installation(444)],
            [
                111 => [$this->repository(222, 'example/first')],
                444 => [$this->repository(555, 'example/second')],
            ]
        );

        $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]))
            ->assertSessionHas(
                'source_issue.github_app_verified_repositories',
                fn ($data) => count($data['repositories']) === 2
                    && $data['installation_id'] === null
            );

        $this->assertDatabaseCount('github_app_connections', 0);
        $this->mockInstallationToken(444, 555);
        $this->actingAs($owner)->post(
            route('sources.github.app.save', ['project' => $project->id]),
            ['repository_id' => 555]
        )->assertRedirect(route('sources.index', ['project' => $project->id]));

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 444,
            'repository_id' => 555,
            'repo_full_name' => 'example/second',
        ]);
    }

    public function test_forged_repository_cannot_be_saved(): void
    {
        [$owner, $project] = $this->fixtures();
        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [
                $this->repository(222, 'example/first'),
                $this->repository(333, 'example/second'),
            ]]
        );
        $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));

        $this->actingAs($owner)->post(
            route('sources.github.app.save', ['project' => $project->id]),
            ['repository_id' => 999]
        )->assertForbidden();

        $this->assertDatabaseCount('github_app_connections', 0);
    }

    public function test_missing_installation_redirects_to_original_install_flow(): void
    {
        [$owner, $project] = $this->fixtures();
        $this->fakeGithub([], []);
        $response = $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith(
            'https://github.com/apps/test-github-app/installations/new?',
            $response->headers->get('Location')
        );
        $response->assertSessionHas('source_issue.github_app_install');
        $this->assertDatabaseCount('github_app_connections', 0);
    }

    public function test_wrong_app_is_not_treated_as_installed(): void
    {
        [$owner, $project] = $this->fixtures();
        $install = $this->installation(111);
        $install['app_id'] = 98765;
        $this->fakeGithub([$install], []);
        $response = $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));
        $this->assertStringStartsWith(
            'https://github.com/apps/test-github-app/installations/new?',
            $response->headers->get('Location')
        );
        $this->assertDatabaseCount('github_app_connections', 0);
    }

    public function test_callback_without_valid_state_is_rejected_without_api_call(): void
    {
        [$owner, $project] = $this->fixtures();
        $this->actingAs($owner)->get(route('sources.github.app.callback', [
            'state' => str_repeat('f', 64),
            'code' => 'valid_code',
        ]))->assertForbidden();
        Http::assertNothingSent();
    }
}
