<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\GithubConfig;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GithubLegacyEndpointRetirementTest extends TestCase
{
    use RefreshDatabase;

    public static function projectStates(): array
    {
        return [
            'new project' => [false, false],
            'legacy PAT-only project' => [true, false],
            'active GitHub App project' => [true, true],
        ];
    }

    #[DataProvider('projectStates')]
    public function test_retired_pat_endpoints_are_unavailable(
        bool $hasLegacy,
        bool $hasApp
    ): void {
        Http::fake();
        Bus::fake();

        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $legacy = null;
        $encryptedPat = null;

        if ($hasLegacy) {
            $legacy = new GithubConfig();
            $legacy->project_id = $project->id;
            $legacy->repo_full_name = 'example/legacy-repo';
            $legacy->pat_encrypted = 'fake-legacy-test-token';
            $legacy->save();

            $encryptedPat = $legacy->getRawOriginal('pat_encrypted');
        }

        if ($hasApp) {
            $connection = new GithubAppConnection();
            $connection->project_id = $project->id;
            $connection->installation_id = 111;
            $connection->repository_id = 222;
            $connection->repo_full_name = 'example/app-repo';
            $connection->verified_github_user_id = 999;
            $connection->verified_at = now();
            $connection->save();
        }

        $this->actingAs($owner);

        foreach (['test', 'save', 'preview', 'import'] as $action) {
            $this->assertFalse(
                Route::has("sources.github.{$action}")
            );

            $this->postJson(
                "/projects/{$project->id}/sources/github/{$action}",
                [
                    'repo_full_name' => 'example/forbidden-repo',
                    'pat' => 'fake-replacement-test-token',
                    'confirmed' => true,
                ]
            )->assertNotFound();
        }

        foreach ([
            'sources.index',
            'sources.csv.preview',
            'sources.csv.import',
            'sources.github.app.connect',
            'sources.github.app.setup',
            'sources.github.app.authorize',
            'sources.github.app.callback',
            'sources.github.app.save',
            'sources.github.app.preview',
            'sources.github.app.import',
        ] as $route) {
            $this->assertTrue(Route::has($route));
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount(
            'github_configs',
            $hasLegacy ? 1 : 0
        );
        $this->assertDatabaseCount(
            'github_app_connections',
            $hasApp ? 1 : 0
        );

        if ($legacy !== null) {
            $this->assertTrue(hash_equals(
                $encryptedPat,
                $legacy->fresh()->getRawOriginal('pat_encrypted')
            ));

            $this->assertSame(
                'example/legacy-repo',
                $legacy->fresh()->repo_full_name
            );
        }

        if ($hasApp) {
            $this->assertDatabaseHas('github_app_connections', [
                'project_id' => $project->id,
                'repository_id' => 222,
                'repo_full_name' => 'example/app-repo',
            ]);
        }

        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }
}
