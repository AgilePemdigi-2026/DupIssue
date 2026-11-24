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

    public function test_pat_only_project_requires_confirmed_migration_to_github_app(): void
    {
        [$owner, $project] = $this->fixtures();

        $legacy = new \App\Models\GithubConfig();
        $legacy->project_id = $project->id;
        $legacy->repo_full_name = 'example/legacy-repo';
        $legacy->pat_encrypted = 'fake-legacy-pat-for-testing';
        $legacy->last_synced_at = now()->subDay();
        $legacy->save();

        $encryptedPat = $legacy->getRawOriginal('pat_encrypted');
        $previousSync = $legacy->last_synced_at->toDateTimeString();

        $legacyPreview = [
            'project_id' => (int) $project->id,
            'actor_id' => (int) $owner->id,
            'config_id' => (int) $legacy->id,
            'repo_full_name' => 'example/legacy-repo',
            'fingerprint' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];

        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [
                $this->repository(333, 'example/new-repo'),
            ]]
        );

        $this->mockInstallationToken(111, 333);

        $params = ['project' => $project->id];
        $pageUrl = route('sources.index', $params);
        $saveUrl = route('sources.github.app.save', $params);

        $this->actingAs($owner)
            ->withSession([
                'source_issue.github_preview' => $legacyPreview,
            ])
            ->post(route('sources.github.app.connect', $params))
            ->assertRedirect(route('sources.github.app.authorize'));

        $authorization = $this->get(
            route('sources.github.app.authorize')
        )->assertStatus(302);

        parse_str(
            (string) parse_url(
                $authorization->headers->get('Location'),
                PHP_URL_QUERY
            ),
            $oauthQuery
        );

        $this->assertArrayHasKey('state', $oauthQuery);

        // Even one accessible repository must require migration consent.
        $response = $this->get(
            route('sources.github.app.callback', [
                'state' => $oauthQuery['state'],
                'code' => 'valid_code',
            ])
        );

        $response->assertRedirect($pageUrl);

        $response->assertSessionHas(
            'source_issue.github_app_verified_repositories',
            fn ($data) => count($data['repositories']) === 1
                && $data['repositories'][0]['id'] === 333
        );

        $this->assertDatabaseCount('github_app_connections', 0);

        Http::assertSentCount(4);

        // The selection page must expose explicit migration consent.
        $this->get($pageUrl)
            ->assertOk()
            ->assertSee('example/legacy-repo')
            ->assertSee('example/new-repo')
            ->assertSee('name="confirm_replace"', false);

        // Refuse migration before explicit confirmation.
        $this->from($pageUrl)
            ->post($saveUrl, [
                'repository_id' => 333,
            ])
            ->assertSessionHasErrors('confirm_replace');

        $this->assertDatabaseCount('github_app_connections', 0);

        Http::assertSentCount(4);

        // Complete the migration only after consent.
        $this->post($saveUrl, [
            'repository_id' => 333,
            'confirm_replace' => '1',
        ])->assertRedirect($pageUrl)
            ->assertSessionMissing(
                'source_issue.github_app_verified_repositories'
            )
            ->assertSessionMissing('source_issue.github_preview');

        $this->assertDatabaseCount('github_app_connections', 1);

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 111,
            'repository_id' => 333,
            'repo_full_name' => 'example/new-repo',
            'last_synced_at' => null,
        ]);

        $this->assertDatabaseHas('github_configs', [
            'id' => $legacy->id,
            'project_id' => $project->id,
            'repo_full_name' => 'example/legacy-repo',
        ]);

        $this->assertTrue(hash_equals(
            $encryptedPat,
            $legacy->fresh()->getRawOriginal('pat_encrypted')
        ));

        $this->assertSame(
            $previousSync,
            $legacy->fresh()->last_synced_at->toDateTimeString()
        );

        Http::assertSentCount(5);
    }

    private function createLegacyPatConfig(
        Project $project,
        string $repository
    ): \App\Models\GithubConfig {
        $legacy = new \App\Models\GithubConfig();
        $legacy->project_id = $project->id;
        $legacy->repo_full_name = $repository;
        $legacy->pat_encrypted = 'fake-legacy-pat-for-testing';
        $legacy->last_synced_at = now()->subDay();
        $legacy->save();

        return $legacy;
    }

    public function test_same_repository_pat_migration_requires_explicit_consent(): void
    {
        [$owner, $project] = $this->fixtures();

        $legacy = $this->createLegacyPatConfig(
            $project,
            'example/same-repo'
        );

        $encryptedPat = $legacy->getRawOriginal('pat_encrypted');

        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [
                $this->repository(222, 'example/same-repo'),
            ]]
        );

        $params = ['project' => $project->id];
        $pageUrl = route('sources.index', $params);
        $saveUrl = route('sources.github.app.save', $params);

        $response = $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));

        $response->assertRedirect($pageUrl);

        $response->assertSessionHas(
            'source_issue.github_app_verified_repositories'
        );

        $this->assertDatabaseCount('github_app_connections', 0);

        Http::assertSentCount(4);

        $this->get($pageUrl)
            ->assertOk()
            ->assertSee('name="confirm_replace"', false)
            ->assertSee('Konfirmasi Migrasi GitHub App');

        // Missing consent must not activate GitHub App.
        $this->from($pageUrl)
            ->post($saveUrl, [
                'repository_id' => 222,
            ])->assertSessionHasErrors('confirm_replace');

        $this->assertDatabaseCount('github_app_connections', 0);

        Http::assertSentCount(4);

        // Explicit rejection must also prevent migration.
        $this->post($saveUrl, [
            'repository_id' => 222,
            'confirm_replace' => '0',
        ])->assertSessionHasErrors('confirm_replace');

        $this->assertDatabaseCount('github_app_connections', 0);

        Http::assertSentCount(4);

        $this->mockInstallationToken(111, 222);

        // Activate GitHub App only after explicit consent.
        $this->post($saveUrl, [
            'repository_id' => 222,
            'confirm_replace' => '1',
        ])->assertRedirect($pageUrl);

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 111,
            'repository_id' => 222,
            'repo_full_name' => 'example/same-repo',
            'last_synced_at' => null,
        ]);

        $this->assertDatabaseCount('github_app_connections', 1);
        $this->assertDatabaseCount('github_configs', 1);

        $this->assertTrue(hash_equals(
            $encryptedPat,
            $legacy->fresh()->getRawOriginal('pat_encrypted')
        ));

        // The retired PAT endpoint cannot be used after migration.
        $this->postJson(
            "/projects/{$project->id}/sources/github/preview"
        )->assertNotFound();

        Http::assertSentCount(5);
    }

    public function test_pat_migration_rejects_changed_legacy_repository_after_oauth(): void
    {
        [$owner, $project] = $this->fixtures();

        $legacy = $this->createLegacyPatConfig(
            $project,
            'example/legacy-repo'
        );

        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [
                $this->repository(333, 'example/new-repo'),
            ]]
        );

        $params = ['project' => $project->id];

        $response = $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]));

        $response->assertRedirect(
            route('sources.index', $params)
        );

        $response->assertSessionHas(
            'source_issue.github_app_verified_repositories',
            fn ($data) => $data['expected_legacy_pat'] === [
                'id' => (int) $legacy->id,
                'repo_full_name' => 'example/legacy-repo',
            ]
        );

        // Simulate a legacy configuration change after OAuth.
        $legacy->repo_full_name = 'example/changed-repo';
        $legacy->save();

        $mock = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        $mock->expects($this->never())
            ->method('createForRepository');

        $this->app->instance(
            GithubAppInstallationTokenService::class,
            $mock
        );

        $this->post(
            route('sources.github.app.save', $params),
            [
                'repository_id' => 333,
                'confirm_replace' => '1',
            ]
        )->assertStatus(409);

        $this->assertDatabaseCount('github_app_connections', 0);

        $this->assertDatabaseHas('github_configs', [
            'id' => $legacy->id,
            'project_id' => $project->id,
            'repo_full_name' => 'example/changed-repo',
        ]);

        Http::assertSentCount(4);
    }

    public function test_pat_migration_rechecks_legacy_snapshot_inside_transaction(): void
    {
        [$owner, $project] = $this->fixtures();

        $legacy = $this->createLegacyPatConfig(
            $project,
            'example/legacy-repo'
        );

        $encryptedPat = $legacy->getRawOriginal('pat_encrypted');

        $this->fakeGithub(
            [$this->installation(111)],
            [111 => [
                $this->repository(333, 'example/new-repo'),
            ]]
        );

        $params = ['project' => $project->id];

        $this->actingAs($owner)
            ->withSession($this->oauthSession($owner, $project))
            ->get(route('sources.github.app.callback', [
                'state' => str_repeat('a', 64),
                'code' => 'valid_code',
            ]))
            ->assertRedirect(
                route('sources.index', $params)
            );

        $mock = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        $mock->expects($this->once())
            ->method('createForRepository')
            ->with(111, 333)
            ->willReturnCallback(
                function () use ($legacy): string {
                    // Simulate a change during external token
                    // verification, after the initial snapshot check.
                    $legacy->repo_full_name =
                        'example/concurrently-changed-repo';

                    $legacy->save();

                    return 'fake-installation-token';
                }
            );

        $this->app->instance(
            GithubAppInstallationTokenService::class,
            $mock
        );

        $this->post(
            route('sources.github.app.save', $params),
            [
                'repository_id' => 333,
                'confirm_replace' => '1',
            ]
        )->assertStatus(409);

        // The transaction must not activate an obsolete selection.
        $this->assertDatabaseCount('github_app_connections', 0);

        $this->assertDatabaseHas('github_configs', [
            'id' => $legacy->id,
            'project_id' => $project->id,
            'repo_full_name' => 'example/concurrently-changed-repo',
        ]);

        $this->assertTrue(hash_equals(
            $encryptedPat,
            $legacy->fresh()->getRawOriginal('pat_encrypted')
        ));

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
        // Regression: verified repositories must be selectable in the UI
        // when OAuth returns repositories from multiple installations.
        $page = $this->actingAs($owner)->get(
            route('sources.index', ['project' => $project->id])
        );

        $page->assertOk();
        $page->assertViewHas(
            'githubAppVerifiedRepositories',
            [
                ['id' => 222, 'full_name' => 'example/first'],
                ['id' => 555, 'full_name' => 'example/second'],
            ]
        );

        $page->assertSee('example/first');
        $page->assertSee('example/second');
        $page->assertSee('name="repository_id"', false);
        $page->assertSee('value="222"', false);
        $page->assertSee('value="555"', false);

        // Opening the selection page must not save a connection.
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

    public function test_connected_project_can_replace_repository_across_installations_and_import(): void
    {
        [$owner, $project] = $this->fixtures();

        \Illuminate\Support\Facades\Bus::fake();

        $old = new \App\Models\GithubAppConnection();
        $old->project_id = $project->id;
        $old->installation_id = 111;
        $old->repository_id = 222;
        $old->repo_full_name = 'example/first';
        $old->verified_github_user_id = 999;
        $old->verified_at = now();
        $old->last_synced_at = now()->subDay()->startOfSecond();
        $old->save();

        $previousSync = $old->last_synced_at->toDateTimeString();

        $historicalIssue = new \App\Models\Issue([
            'title' => 'Historical GitHub issue',
            'description' => 'This issue must remain after repository replacement.',
        ]);
        $historicalIssue->project_id = $project->id;
        $historicalIssue->author_id = null;
        $historicalIssue->source_type = 'GITHUB';
        $historicalIssue->source_id = null;
        $historicalIssue->github_repo = 'example/first';
        $historicalIssue->github_number = 1;
        $historicalIssue->source_url = 'https://github.com/example/first/issues/1';
        $historicalIssue->status = 'OPEN';
        $historicalIssue->detection_status = 'PENDING';
        $historicalIssue->vote_count = 0;
        $historicalIssue->duplicate_of = null;
        $historicalIssue->save();

        $this->fakeGithub(
            [$this->installation(111), $this->installation(444)],
            [
                111 => [$this->repository(222, 'example/first')],
                444 => [$this->repository(555, 'example/second')],
            ]
        );

        $params = ['project' => $project->id];
        $pageUrl = route('sources.index', $params);
        $connectUrl = route('sources.github.app.connect', $params);
        $saveUrl = route('sources.github.app.save', $params);
        $previewUrl = route('sources.github.app.preview', $params);
        $importUrl = route('sources.github.app.import', $params);

        $fetch = $this->createMock(
            \App\Services\GithubAppIssueFetchService::class
        );

        $expectedRepositories = [
            [111, 222, 'example/first'],
            [444, 555, 'example/second'],
            [444, 555, 'example/second'],
        ];

        $fetchIndex = 0;

        $fetch->expects($this->exactly(3))
            ->method('fetchIssues')
            ->willReturnCallback(
                function ($connection) use (
                    &$fetchIndex,
                    $expectedRepositories
                ) {
                    $this->assertSame(
                        $expectedRepositories[$fetchIndex++],
                        [
                            (int) $connection->installation_id,
                            (int) $connection->repository_id,
                            $connection->repo_full_name,
                        ]
                    );

                    return [[
                        'number' => 1,
                        'title' => 'Repository issue',
                        'body' => 'A sufficiently long issue description.',
                    ]];
                }
            );

        $this->app->instance(
            \App\Services\GithubAppIssueFetchService::class,
            $fetch
        );

        // Existing connection and replacement button.
        $this->actingAs($owner)->get($pageUrl)
            ->assertOk()
            ->assertSee('example/first')
            ->assertSee('Ganti Repository')
            ->assertSee('data-bs-target="#githubAppReplaceModal"', false)
            ->assertSee($connectUrl);

        // Create a preview for the old repository.
        $this->postJson($previewUrl)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertSessionHas('source_issue.github_app_preview');

        $this->assertSame(
            $previousSync,
            $old->fresh()->last_synced_at->toDateTimeString()
        );

        // Start replacement through the actual connection endpoint.
        $this->post($connectUrl)
            ->assertRedirect(route('sources.github.app.authorize'));

        $authorization = $this->get(
            route('sources.github.app.authorize')
        )->assertStatus(302);

        $redirect = $authorization->headers->get('Location');

        $this->assertSame(
            'github.com',
            parse_url($redirect, PHP_URL_HOST)
        );

        parse_str(
            (string) parse_url($redirect, PHP_URL_QUERY),
            $oauthQuery
        );

        $this->assertArrayHasKey('state', $oauthQuery);

        // OAuth callback discovers both installations.
        $this->get(route('sources.github.app.callback', [
            'state' => $oauthQuery['state'],
            'code' => 'valid_code',
        ]))->assertRedirect($pageUrl);

        $this->get($pageUrl)
            ->assertOk()
            ->assertSee('example/first')
            ->assertSee('example/second')
            ->assertSee('name="repository_id"', false)
            ->assertSee('name="confirm_replace"', false)
            ->assertSee('Konfirmasi Ganti Repository');

        // The old repository remains active until confirmation.
        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'repository_id' => 222,
        ]);

        $this->mockInstallationToken(444, 555);

        $this->assertSame(
            $previousSync,
            $old->fresh()->last_synced_at->toDateTimeString()
        );

        // Confirm the replacement.
        $this->post($saveUrl, [
            'repository_id' => 555,
            'confirm_replace' => '1',
        ])->assertRedirect($pageUrl)
            ->assertSessionMissing('source_issue.github_app_preview')
            ->assertSessionMissing('source_issue.github_app_verified_repositories');

        $this->assertDatabaseCount('github_app_connections', 1);

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 444,
            'repository_id' => 555,
            'repo_full_name' => 'example/second',
        ]);

        $this->assertNull(
            $old->fresh()->last_synced_at
        );

        // Old preview must no longer authorize an import.
        $this->postJson($importUrl, [
            'confirmed' => true,
        ])->assertStatus(409);

        $this->assertNull(
            $old->fresh()->last_synced_at
        );

        $this->freezeTime();

        // Preview and import from the replacement repository.
        $this->postJson($previewUrl)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preview.valid_count', 1);

        $this->assertNull(
            $old->fresh()->last_synced_at
        );

        $this->postJson($importUrl, [
            'confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.imported_count', 1)
            ->assertSessionMissing('source_issue.github_app_preview');

        $this->assertDatabaseHas('issues', [
            'id' => $historicalIssue->id,
            'project_id' => $project->id,
            'github_repo' => 'example/first',
            'github_number' => 1,
        ]);

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'GITHUB',
            'github_repo' => 'example/second',
            'github_number' => 1,
        ]);

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('github_app_connections', 1);

        $this->assertSame(
            now()->toDateTimeString(),
            $old->fresh()->last_synced_at->toDateTimeString()
        );
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
