<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppInstallationTokenService;
use App\Services\GithubIssuePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubAppRepositoryReplacementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('services.github_app.client_id', 'test-client');
        config()->set('services.github_app.client_secret', 'test-secret');
        config()->set('services.github_app.app_id', '1234');
        config()->set('services.github_app.callback_url',
            'http://127.0.0.1:8000/integrations/github/callback');
    }

    private function setupConnection(): array
    {
        $owner = User::factory()->create(['role' => 'MAINTAINER']);
        $project = Project::factory()->create(['owner_id' => $owner->id]);

        $connection = new GithubAppConnection();
        $connection->project_id = $project->id;
        $connection->installation_id = 111;
        $connection->repository_id = 222;
        $connection->repo_full_name = 'example/old-repo';
        $connection->verified_github_user_id = 999;
        $connection->verified_at = now();
        $connection->save();

        return [$owner, $project, $connection];
    }

    private function snapshot(GithubAppConnection $connection): array
    {
        return [
            'id' => (int) $connection->id,
            'installation_id' => 111,
            'repository_id' => 222,
            'repo_full_name' => 'example/old-repo',
        ];
    }

    private function verified(User $user, Project $project,
        GithubAppConnection $connection): array
    {
        return [
            'source_issue.github_app_verified_repositories' => [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $user->id,
                'installation_id' => 111,
                'github_user_id' => 999,
                'repositories' => [
                    ['id' => 333, 'full_name' => 'example/new-repo'],
                ],
                'expected_connection' => $this->snapshot($connection),
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ];
    }

    private function routeFor(Project $project): string
    {
        return route('sources.github.app.save', ['project' => $project->id]);
    }

    private function mockToken(): void
    {
        $mock = $this->createMock(GithubAppInstallationTokenService::class);
        $mock->method('createForRepository')
            ->willReturn('fake-installation-token');
        $this->app->instance(GithubAppInstallationTokenService::class, $mock);
        Http::fake([
            'https://api.github.com/repos/example/new-repo' =>
                Http::response([
                    'id' => 333,
                    'full_name' => 'example/new-repo',
                ], 200),
        ]);
    }

    public function test_legacy_pat_does_not_block_confirmed_app_repository_replacement(): void
    {
        [$owner, $project, $old] = $this->setupConnection();

        // Simulate an existing project with both authentication methods.
        $legacy = new \App\Models\GithubConfig();
        $legacy->project_id = $project->id;
        $legacy->repo_full_name = 'example/old-repo';
        $legacy->pat_encrypted = 'fake-legacy-pat-for-testing';
        $legacy->last_synced_at = now()->subDay();
        $legacy->save();

        $encryptedPat = $legacy->getRawOriginal('pat_encrypted');

        $this->mockToken();

        $pageUrl = route('sources.index', [
            'project' => $project->id,
        ]);

        $response = $this->actingAs($owner)
            ->withSession($this->verified($owner, $project, $old))
            ->post($this->routeFor($project), [
                'repository_id' => 333,
                'confirm_replace' => '1',
            ]);

        // Legacy PAT must not prevent a verified and confirmed replacement.
        $response->assertRedirect($pageUrl);

        $this->assertDatabaseCount('github_app_connections', 1);

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'installation_id' => 111,
            'repository_id' => 333,
            'repo_full_name' => 'example/new-repo',
        ]);

        // Preserve legacy configuration without modifying its credentials.
        $this->assertDatabaseHas('github_configs', [
            'id' => $legacy->id,
            'project_id' => $project->id,
            'repo_full_name' => 'example/old-repo',
        ]);

        $this->assertTrue(
            hash_equals(
                $encryptedPat,
                $legacy->fresh()->getRawOriginal('pat_encrypted')
            ),
            'Legacy encrypted credential must remain unchanged.'
        );

        $response->assertSessionMissing(
            'source_issue.github_app_verified_repositories'
        );
    }

    public function test_active_github_app_has_no_legacy_pat_endpoints(): void
    {
        [$owner, $project, $old] = $this->setupConnection();

        $legacy = new \App\Models\GithubConfig();
        $legacy->project_id = $project->id;
        $legacy->repo_full_name = 'example/old-repo';
        $legacy->pat_encrypted = 'fake-legacy-pat';
        $legacy->save();

        $encryptedPat = $legacy->getRawOriginal('pat_encrypted');

        Http::fake();

        $credentials = [
            'repo_full_name' => 'example/old-repo',
            'pat' => 'fake-replacement-pat',
        ];

        $this->actingAs($owner);

        $this->postJson(
            "/projects/{$project->id}/sources/github/test",
            $credentials
        )->assertNotFound();

        $this->postJson(
            "/projects/{$project->id}/sources/github/save",
            $credentials
        )->assertNotFound();

        $this->postJson(
            "/projects/{$project->id}/sources/github/preview"
        )->assertNotFound();

        $this->postJson(
            "/projects/{$project->id}/sources/github/import",
            ['confirmed' => true]
        )->assertNotFound();

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('github_app_connections', 1);

        $this->assertTrue(hash_equals(
            $encryptedPat,
            $legacy->fresh()->getRawOriginal('pat_encrypted')
        ));

        Http::assertNothingSent();
    }

    public function test_active_github_app_cannot_import_from_legacy_repository(): void
    {
        [$owner, $project, $connection] = $this->setupConnection();

        \Illuminate\Support\Facades\Bus::fake();

        $legacy = new \App\Models\GithubConfig();
        $legacy->project_id = $project->id;
        $legacy->repo_full_name = 'example/legacy-repo';
        $legacy->pat_encrypted = 'fake-retired-pat';
        $legacy->save();

        $encrypted = $legacy->getRawOriginal('pat_encrypted');

        $issues = [[
            'number' => 1,
            'title' => 'A valid legacy repository issue',
            'body' => 'This issue must not be imported through a retired connection.',
        ]];

        $preview = app(GithubIssuePreviewService::class)->preview(
            $project,
            $legacy->repo_full_name,
            $issues
        );

        $fingerprint = hash(
            'sha256',
            json_encode($preview['rows'], JSON_THROW_ON_ERROR)
        );

        $service = app(\App\Services\GithubIssueImportService::class);

        $this->assertFalse(method_exists($service, 'import'));

        try {
            $service->importFromApp(
                $project,
                $owner,
                $connection->id,
                111,
                222,
                $legacy->repo_full_name,
                $issues,
                $fingerprint
            );

            $this->fail('Repository PAT lama tidak boleh digunakan.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('github', $exception->errors());
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('github_configs', 1);
        $this->assertDatabaseCount('github_app_connections', 1);

        $this->assertSame(
            'example/old-repo',
            $connection->fresh()->repo_full_name
        );

        $this->assertTrue(hash_equals(
            $encrypted,
            $legacy->fresh()->getRawOriginal('pat_encrypted')
        ));

        \Illuminate\Support\Facades\Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_replacement_requires_explicit_consent(): void
    {
        [$owner, $project, $old] = $this->setupConnection();
        Http::fake();

        $this->actingAs($owner)
            ->withSession($this->verified($owner, $project, $old))
            ->post($this->routeFor($project), ['repository_id' => 333])
            ->assertSessionHasErrors('confirm_replace');

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'repository_id' => 222,
            'repo_full_name' => 'example/old-repo',
        ]);
        Http::assertNothingSent();
    }

    public function test_confirmed_replacement_keeps_old_issues_and_repo_scopes_duplicates(): void
    {
        [$owner, $project, $old] = $this->setupConnection();

        $issue = new Issue([
            'title' => 'Old GitHub issue retained',
            'description' => 'Historical issue must remain in this project.',
        ]);
        $issue->project_id = $project->id;
        $issue->author_id = null;
        $issue->source_type = 'GITHUB';
        $issue->source_id = null;
        $issue->github_repo = 'example/old-repo';
        $issue->github_number = 1;
        $issue->source_url = 'https://github.com/example/old-repo/issues/1';
        $issue->status = 'OPEN';
        $issue->detection_status = 'PENDING';
        $issue->vote_count = 0;
        $issue->duplicate_of = null;
        $issue->save();

        $this->mockToken();
        $this->actingAs($owner)
            ->withSession($this->verified($owner, $project, $old))
            ->post($this->routeFor($project), [
                'repository_id' => 333,
                'confirm_replace' => '1',
            ])->assertRedirect(route('sources.index', [
                'project' => $project->id,
            ]));

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'repository_id' => 333,
            'repo_full_name' => 'example/new-repo',
        ]);
        $this->assertSame(1, GithubAppConnection::query()->count());
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'project_id' => $project->id,
            'github_repo' => 'example/old-repo',
            'github_number' => 1,
        ]);

        $sample = [[
            'number' => 1,
            'title' => 'A valid new GitHub issue',
            'body' => 'A sufficiently long issue description.',
        ]];
        $service = app(GithubIssuePreviewService::class);
        $newPreview = $service->preview($project, 'example/new-repo', $sample);
        $oldPreview = $service->preview($project, 'example/old-repo', $sample);
        $this->assertSame(1, $newPreview['valid_count']);
        $this->assertSame(1, $oldPreview['already_imported_count']);
    }

    public function test_stale_oauth_snapshot_does_not_replace_current_connection(): void
    {
        [$owner, $project, $old] = $this->setupConnection();
        $pending = $this->verified($owner, $project, $old);
        $pending['source_issue.github_app_verified_repositories']
            ['expected_connection']['repository_id'] = 999;

        $this->mockToken();
        $this->actingAs($owner)
            ->withSession($pending)
            ->post($this->routeFor($project), [
                'repository_id' => 333,
                'confirm_replace' => '1',
            ])->assertStatus(409);

        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'repository_id' => 222,
        ]);
    }

    public function test_github_callback_with_new_single_repo_keeps_existing_connection(): void
    {
        [$owner, $project, $old] = $this->setupConnection();
        $state = str_repeat('a', 64);
        Http::fake([
            'https://github.com/login/oauth/access_token' =>
                Http::response([
                    'access_token' => 'fake-user-token',
                    'token_type' => 'bearer',
                ], 200),
            'https://api.github.com/user' =>
                Http::response(['id' => 999, 'login' => 'example-user'], 200),
            'https://api.github.com/user/installations?*' =>
                Http::response([
                    'total_count' => 1,
                    'installations' => [[
                        'id' => 111,
                        'app_id' => 1234,
                        'permissions' => ['issues' => 'read'],
                        'suspended_at' => null,
                    ]],
                ], 200),
            'https://api.github.com/user/installations/111/repositories?*' =>
                Http::response([
                    'total_count' => 1,
                    'repositories' => [[
                        'id' => 333,
                        'full_name' => 'example/new-repo',
                    ]],
                ], 200),
        ]);

        $session = [
            'source_issue.github_app_oauth' => [
                'state_hash' => hash('sha256', $state),
                'code_verifier' => str_repeat('v', 43),
                'project_id' => (int) $project->id,
                'actor_id' => (int) $owner->id,
                'installation_id' => null,
                'expected_connection' => $this->snapshot($old),
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ];

        $response = $this->actingAs($owner)
            ->withSession($session)
            ->get(route('sources.github.app.callback', [
                'state' => $state,
                'code' => 'safe_code',
            ]));
        $response->assertRedirect(route('sources.index', [
            'project' => $project->id,
        ]));
        $response->assertSessionHas(
            'source_issue.github_app_verified_repositories',
            fn ($data) => count($data['repositories']) === 1
                && $data['repositories'][0]['id'] === 333
        );
        $this->assertDatabaseHas('github_app_connections', [
            'project_id' => $project->id,
            'repository_id' => 222,
        ]);
    }
}
