<?php

namespace Tests\Feature;

use App\Models\GithubConfig;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubSaveConnectionControllerTest extends TestCase
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

    private function fakeSuccessfulGithub(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/issues')) {
                return Http::response([], 200);
            }

            $repo = str_contains(
                $request->url(),
                '/repos/demo/new-repo'
            ) ? 'demo/new-repo' : 'demo/bugs';

            return Http::response([
                'full_name' => $repo,
                'private' => true,
                'has_issues' => true,
            ], 200);
        });
    }

    public function test_owner_saves_encrypted_pat(): void
    {
        [$owner, $project] = $this->makeProject();

        $this->fakeSuccessfulGithub();

        $token = 'secret-test-token';

        $response = $this->actingAs($owner)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => $token,
            ]
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'repository.repo_full_name',
                'demo/bugs'
            );

        $this->assertDatabaseCount('github_configs', 1);

        $rawToken = DB::table('github_configs')
            ->where('project_id', $project->id)
            ->value('pat_encrypted');

        $this->assertNotNull($rawToken);
        $this->assertNotSame($token, $rawToken);

        $config = GithubConfig::query()
            ->where('project_id', $project->id)
            ->firstOrFail();

        $this->assertSame($token, $config->pat_encrypted);

        $this->assertArrayNotHasKey(
            'pat_encrypted',
            $config->toArray()
        );

        $this->assertStringNotContainsString(
            $token,
            $response->getContent()
        );

        Http::assertSentCount(2);
    }

    public function test_updating_connection_keeps_single_config(): void
    {
        [$owner, $project] = $this->makeProject();

        $this->fakeSuccessfulGithub();

        $this->actingAs($owner)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'first-token',
            ]
        )->assertOk();

        $original = GithubConfig::query()->firstOrFail();
        $originalId = $original->id;

        $original->last_synced_at = now();
        $original->save();

        $this->actingAs($owner)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/new-repo',
                'pat' => 'second-token',
            ]
        )->assertOk();

        $this->assertDatabaseCount('github_configs', 1);

        $updated = GithubConfig::query()->firstOrFail();

        $this->assertSame($originalId, $updated->id);
        $this->assertSame(
            'demo/new-repo',
            $updated->repo_full_name
        );
        $this->assertSame(
            'second-token',
            $updated->pat_encrypted
        );

        $this->assertNull($updated->last_synced_at);
    }

    public function test_failed_connection_preserves_old_config(): void
    {
        [$owner, $project] = $this->makeProject();

        $config = new GithubConfig();
        $config->project_id = $project->id;
        $config->repo_full_name = 'demo/bugs';
        $config->pat_encrypted = 'original-token';
        $config->save();

        $originalEncrypted = DB::table('github_configs')
            ->where('project_id', $project->id)
            ->value('pat_encrypted');

        Http::fake([
            '*' => Http::response([
                'message' => 'Bad credentials',
            ], 401),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/new-repo',
                'pat' => 'invalid-new-token',
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $current = GithubConfig::query()->firstOrFail();

        $this->assertSame('demo/bugs', $current->repo_full_name);
        $this->assertSame(
            'original-token',
            $current->pat_encrypted
        );

        $this->assertSame(
            $originalEncrypted,
            DB::table('github_configs')
                ->where('project_id', $project->id)
                ->value('pat_encrypted')
        );

        $this->assertDatabaseCount('github_configs', 1);

        $this->assertStringNotContainsString(
            'invalid-new-token',
            $response->getContent()
        );
    }

    public function test_reporter_cannot_save_connection(): void
    {
        [, $project] = $this->makeProject();

        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        Http::fake();

        $this->actingAs($reporter)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'dummy-token',
            ]
        )->assertForbidden();

        Http::assertNothingSent();

        $this->assertDatabaseCount('github_configs', 0);
    }

    public function test_other_maintainer_cannot_save_connection(): void
    {
        [, $project] = $this->makeProject();

        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        Http::fake();

        $this->actingAs($other)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'dummy-token',
            ]
        )->assertForbidden();

        Http::assertNothingSent();

        $this->assertDatabaseCount('github_configs', 0);
    }

    public function test_guest_cannot_save_connection(): void
    {
        [, $project] = $this->makeProject();

        Http::fake();

        $this->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => 'demo/bugs',
                'pat' => 'dummy-token',
            ]
        )->assertUnauthorized();

        Http::assertNothingSent();

        $this->assertDatabaseCount('github_configs', 0);
    }

    public function test_invalid_input_is_not_saved(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake();

        $this->actingAs($owner)->postJson(
            route('sources.github.save', $project),
            [
                'repo_full_name' => '',
                'pat' => '',
            ]
        )->assertStatus(422)
            ->assertJsonValidationErrors([
                'repo_full_name',
                'pat',
            ]);

        Http::assertNothingSent();

        $this->assertDatabaseCount('github_configs', 0);
    }

    public function test_successful_save_clears_old_github_preview(): void
    {
        [$owner, $project] = $this->makeProject();

        $this->fakeSuccessfulGithub();

        $pending = [
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'repo_full_name' => 'demo/old-repo',
            'fingerprint' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];

        $response = $this->withSession([
            'source_issue.github_preview' => $pending,
        ])
            ->actingAs($owner)
            ->postJson(
                route('sources.github.save', $project),
                [
                    'repo_full_name' => 'demo/bugs',
                    'pat' => 'new-test-token',
                ]
            );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertSessionMissing(
                'source_issue.github_preview'
            );

        $this->assertDatabaseCount('github_configs', 1);
    }

    public function test_failed_save_preserves_github_preview(): void
    {
        [$owner, $project] = $this->makeProject();

        $config = new GithubConfig();
        $config->project_id = $project->id;
        $config->repo_full_name = 'demo/bugs';
        $config->pat_encrypted = 'original-token';
        $config->save();

        $pending = [
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'config_id' => $config->id,
            'repo_full_name' => 'demo/bugs',
            'fingerprint' => str_repeat('b', 64),
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];

        Http::fake([
            '*' => Http::response([
                'message' => 'Bad credentials',
            ], 401),
        ]);

        $response = $this->withSession([
            'source_issue.github_preview' => $pending,
        ])
            ->actingAs($owner)
            ->postJson(
                route('sources.github.save', $project),
                [
                    'repo_full_name' => 'demo/new-repo',
                    'pat' => 'invalid-new-token',
                ]
            );

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertSessionHas(
                'source_issue.github_preview',
                $pending
            );

        $config->refresh();

        $this->assertSame(
            'demo/bugs',
            $config->repo_full_name
        );

        $this->assertSame(
            'original-token',
            $config->pat_encrypted
        );

        $this->assertDatabaseCount('github_configs', 1);
    }

}
