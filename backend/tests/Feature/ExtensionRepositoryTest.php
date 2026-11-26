<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ExtensionAuthService;
use App\Services\ExtensionRepositoryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtensionRepositoryTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    private function reporter(): User
    {
        return User::factory()->create([
            'role' => 'REPORTER',
        ])->refresh();
    }

    private function headers(User $reporter): array
    {
        $auth = app(ExtensionAuthService::class);

        $result = $auth->exchange(
            $auth->createPairingCode($reporter)
        );

        $this->assertNotNull($result);

        return [
            'Authorization' =>
                'Bearer '.$result['access_token'],
        ];
    }

    private function connectedProject(
        ?User $reporter,
        string $repository,
        bool $active = true
    ): Project {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ])->refresh();

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $connection = new GithubAppConnection();

        $connection->forceFill([
            'project_id' => $project->id,
            'installation_id' => 12345,
            'repository_id' => 67890,
            'repo_full_name' => $repository,
            'verified_github_user_id' => 99999,
            'verified_at' => now(),
            'last_synced_at' => null,
        ])->save();

        if ($reporter !== null) {
            Membership::factory()->create([
                'project_id' => $project->id,
                'user_id' => $reporter->id,
                'is_active' => $active,
            ]);
        }

        return $project;
    }

    private function resolveUrl(): string
    {
        return route(
            'extension.api.repositories.resolve'
        );
    }

    public function test_repository_resolution_requires_bearer_token(): void
    {
        $reporter = $this->reporter();

        $this->connectedProject(
            $reporter,
            'example/mobile-app'
        );

        $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'example/mobile-app']
        )->assertUnauthorized();

        $this->actingAs($reporter)
            ->postJson(
                $this->resolveUrl(),
                ['repository' => 'example/mobile-app']
            )
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_only_active_member_projects_for_repository_are_returned(): void
    {
        $reporter = $this->reporter();

        $first = $this->connectedProject(
            $reporter,
            'Example/Mobile-App'
        );

        $second = $this->connectedProject(
            $reporter,
            'example/mobile-app'
        );

        // Unauthorized project with the same repository.
        $foreign = $this->connectedProject(
            null,
            'example/mobile-app'
        );

        // Inactive membership.
        $inactive = $this->connectedProject(
            $reporter,
            'example/mobile-app',
            false
        );

        // Active membership in an unrelated repository.
        $different = $this->connectedProject(
            $reporter,
            'example/website'
        );

        // A project without GitHub App connection.
        $unconnected = Project::factory()->create();

        Membership::factory()->create([
            'project_id' => $unconnected->id,
            'user_id' => $reporter->id,
            'is_active' => true,
        ]);

        $response = $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'EXAMPLE/MOBILE-APP'],
            $this->headers($reporter)
        )
            ->assertOk()
            ->assertExactJson([
                'repository' => 'example/mobile-app',
                'projects' => [
                    [
                        'id' => $first->id,
                        'name' => $first->name,
                    ],
                    [
                        'id' => $second->id,
                        'name' => $second->name,
                    ],
                ],
            ]);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control')
        );

        foreach ([
            $foreign,
            $inactive,
            $different,
            $unconnected,
        ] as $excluded) {
            $this->assertNotContains(
                $excluded->id,
                collect($response->json('projects'))
                    ->pluck('id')
                    ->all()
            );
        }

        $this->assertDatabaseCount(
            'github_app_connections',
            5
        );

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('votes', 0);

        Http::assertNothingSent();
    }

    public function test_unknown_and_unauthorized_repositories_return_empty_projects(): void
    {
        $reporter = $this->reporter();

        $this->connectedProject(
            null,
            'example/private-repo'
        );

        $headers = $this->headers($reporter);

        foreach ([
            'example/private-repo',
            'example/nonexistent',
        ] as $repository) {
            $this->postJson(
                $this->resolveUrl(),
                ['repository' => $repository],
                $headers
            )
                ->assertOk()
                ->assertExactJson([
                    'repository' => $repository,
                    'projects' => [],
                ]);
        }

        Http::assertNothingSent();
    }

    public function test_invalid_repository_input_is_rejected(): void
    {
        $reporter = $this->reporter();

        $headers = $this->headers($reporter);

        foreach ([
            null,
            '',
            ['example/mobile-app'],
            'https://github.com/example/mobile-app',
            'example/mobile-app/issues/new',
            'example/../private',
            'example/..',
            'example/.',
            'example/mobile app',
            'example/mobile-app?project=5',
            'example/'.str_repeat('x', 101),
        ] as $repository) {
            $this->postJson(
                $this->resolveUrl(),
                ['repository' => $repository],
                $headers
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('repository');
        }

        $this->assertDatabaseCount(
            'github_app_connections',
            0
        );

        Http::assertNothingSent();
    }

    public function test_membership_removal_invalidates_previous_repository_mapping(): void
    {
        $reporter = $this->reporter();

        $project = $this->connectedProject(
            $reporter,
            'example/mobile-app'
        );

        $headers = $this->headers($reporter);

        $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'example/mobile-app'],
            $headers
        )
            ->assertOk()
            ->assertJsonPath(
                'projects.0.id',
                $project->id
            );

        Membership::query()
            ->where('project_id', $project->id)
            ->where('user_id', $reporter->id)
            ->update([
                'is_active' => false,
                'removed_at' => now(),
            ]);

        $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'example/mobile-app'],
            $headers
        )
            ->assertOk()
            ->assertExactJson([
                'repository' => 'example/mobile-app',
                'projects' => [],
            ]);

        $this->assertNull(
            app(ExtensionRepositoryService::class)
                ->resolveProject(
                    $reporter,
                    'example/mobile-app',
                    $project->id
                )
        );

        Http::assertNothingSent();
    }

    public function test_repository_replacement_invalidates_old_mapping(): void
    {
        $reporter = $this->reporter();

        $project = $this->connectedProject(
            $reporter,
            'example/old-repo'
        );

        $headers = $this->headers($reporter);

        $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'example/old-repo'],
            $headers
        )
            ->assertOk()
            ->assertJsonPath(
                'projects.0.id',
                $project->id
            );

        // Simulate a repository replacement that has already
        // been authorized and committed by the existing
        // GitHub App connection workflow.
        DB::table('github_app_connections')
            ->where('project_id', $project->id)
            ->update([
                'repo_full_name' => 'example/new-repo',
                'repository_id' => 98765,
                'last_synced_at' => null,
                'updated_at' => now(),
            ]);

        $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'example/old-repo'],
            $headers
        )
            ->assertOk()
            ->assertJsonPath('projects', []);

        $this->postJson(
            $this->resolveUrl(),
            ['repository' => 'example/new-repo'],
            $headers
        )
            ->assertOk()
            ->assertJsonPath(
                'projects.0.id',
                $project->id
            );

        $service = app(
            ExtensionRepositoryService::class
        );

        $this->assertNull(
            $service->resolveProject(
                $reporter,
                'example/old-repo',
                $project->id
            )
        );

        $this->assertSame(
            $project->id,
            $service->resolveProject(
                $reporter,
                'example/new-repo',
                $project->id
            )?->id
        );

        Http::assertNothingSent();
    }

    public function test_explicit_project_selection_rejects_forged_project_ids(): void
    {
        $reporter = $this->reporter();

        $allowed = $this->connectedProject(
            $reporter,
            'example/mobile-app'
        );

        $foreign = $this->connectedProject(
            null,
            'example/mobile-app'
        );

        $different = $this->connectedProject(
            $reporter,
            'example/website'
        );

        $inactive = $this->connectedProject(
            $reporter,
            'example/mobile-app',
            false
        );

        $service = app(
            ExtensionRepositoryService::class
        );

        $this->assertSame(
            $allowed->id,
            $service->resolveProject(
                $reporter,
                'example/mobile-app',
                $allowed->id
            )?->id
        );

        foreach ([
            $foreign->id,
            $different->id,
            $inactive->id,
            999999999,
        ] as $projectId) {
            $this->assertNull(
                $service->resolveProject(
                    $reporter,
                    'example/mobile-app',
                    $projectId
                )
            );
        }

        Http::assertNothingSent();
    }
}
