<?php

namespace Tests\Feature;

use App\Models\GithubConfig;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(bool $configured = true): array
    {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        if ($configured) {
            $config = new GithubConfig();
            $config->project_id = $project->id;
            $config->repo_full_name = 'demo/bugs';
            $config->pat_encrypted = 'secret-preview-token';
            $config->save();
        }

        return [$owner, $project];
    }

    private function githubIssue(int $number): array
    {
        return [
            'number' => $number,
            'title' => 'Tombol pembayaran tidak berfungsi',
            'body' => 'Tombol tidak merespons ketika diklik.',
        ];
    }

    public function test_owner_can_preview_github_issues(): void
    {
        [$owner, $project] = $this->makeProject();

        Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => null,
            'title' => 'Issue yang sudah diimpor',
            'description' => 'Issue GitHub yang sudah tersimpan.',
            'status' => 'OPEN',
            'detection_status' => 'PENDING',
            'source_type' => 'GITHUB',
            'github_repo' => 'demo/bugs',
            'github_number' => 102,
            'source_url' => 'https://github.com/demo/bugs/issues/102',
        ]);

        $invalid = $this->githubIssue(103);
        $invalid['title'] = 'Bug';

        $pullRequest = $this->githubIssue(104);
        $pullRequest['pull_request'] = [
            'url' => 'https://api.github.com/repos/demo/bugs/pulls/104',
        ];

        Http::fake([
            '*' => Http::response([
                $this->githubIssue(101),
                $this->githubIssue(102),
                $invalid,
                $pullRequest,
            ], 200),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('sources.github.preview', $project)
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('preview.total_rows', 3)
            ->assertJsonPath('preview.valid_count', 1)
            ->assertJsonPath('preview.invalid_count', 1)
            ->assertJsonPath(
                'preview.already_imported_count',
                1
            )
            ->assertJsonPath(
                'preview.rows.0.status',
                'VALID'
            )
            ->assertJsonPath(
                'preview.rows.1.status',
                'ALREADY_IMPORTED'
            )
            ->assertJsonPath(
                'preview.rows.2.status',
                'INVALID'
            );

        // Preview must not create new issues.
        $this->assertDatabaseCount('issues', 1);

        // Session metadata must be scoped to owner/project.
        $response->assertSessionHas(
            'source_issue.github_preview'
        );

        $pending = session('source_issue.github_preview');

        $this->assertIsArray($pending);
        $this->assertSame(
            (int) $project->id,
            $pending['project_id']
        );
        $this->assertSame(
            (int) $owner->id,
            $pending['actor_id']
        );
        $this->assertSame(
            'demo/bugs',
            $pending['repo_full_name']
        );
        $this->assertSame(
            64,
            strlen($pending['fingerprint'])
        );

        // Never store PAT or full issue data in session.
        $this->assertArrayNotHasKey('pat', $pending);
        $this->assertArrayNotHasKey('issues', $pending);

        $this->assertStringNotContainsString(
            'secret-preview-token',
            json_encode($pending)
        );

        $this->assertStringNotContainsString(
            'secret-preview-token',
            $response->getContent()
        );

        Http::assertSentCount(1);
    }

    public function test_missing_config_returns_conflict(): void
    {
        [$owner, $project] = $this->makeProject(false);

        Http::fake();

        $this->actingAs($owner)->postJson(
            route('sources.github.preview', $project)
        )
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_reporter_cannot_preview(): void
    {
        [, $project] = $this->makeProject();

        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        Http::fake();

        $this->actingAs($reporter)->postJson(
            route('sources.github.preview', $project)
        )->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_other_maintainer_cannot_preview(): void
    {
        [, $project] = $this->makeProject();

        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        Http::fake();

        $this->actingAs($other)->postJson(
            route('sources.github.preview', $project)
        )->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_guest_cannot_preview(): void
    {
        [, $project] = $this->makeProject();

        Http::fake();

        $this->postJson(
            route('sources.github.preview', $project)
        )->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_rate_limit_returns_http_429(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake([
            '*' => Http::response([
                'message' => 'API rate limit exceeded',
            ], 403, [
                'X-RateLimit-Remaining' => '0',
            ]),
        ]);

        $this->actingAs($owner)->postJson(
            route('sources.github.preview', $project)
        )
            ->assertStatus(429)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_github_api_failure_does_not_save_preview(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake([
            '*' => Http::response([
                'message' => 'Internal Server Error',
            ], 500),
        ]);

        $response = $this->actingAs($owner)->postJson(
            route('sources.github.preview', $project)
        );

        $response->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertSessionMissing(
                'source_issue.github_preview'
            );

        $this->assertDatabaseCount('issues', 0);
    }
}
