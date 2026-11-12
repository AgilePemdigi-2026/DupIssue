<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubConfig;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubImportControllerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function makeProject(): array
    {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $config = new GithubConfig();
        $config->project_id = $project->id;
        $config->repo_full_name = 'demo/bugs';
        $config->pat_encrypted = 'secret-import-token';
        $config->save();

        return [$owner, $project, $config];
    }

    private function githubIssue(int $number): array
    {
        return [
            'number' => $number,
            'title' => 'Tombol pembayaran tidak berfungsi',
            'body' => 'Tombol pembayaran tidak merespons ketika diklik.',
        ];
    }

    private function preview(Project $project): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            route('sources.github.preview', $project)
        );
    }

    private function confirm(
        Project $project,
        array $payload = ['confirmed' => true]
    ): \Illuminate\Testing\TestResponse {
        return $this->postJson(
            route('sources.github.import', $project),
            $payload
        );
    }

    public function test_owner_can_preview_and_confirm_import(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        $issues = [
            $this->githubIssue(101),
            $this->githubIssue(102),
        ];

        Http::fake([
            '*' => Http::response($issues, 200),
        ]);

        $this->actingAs($owner);

        $this->preview($project)
            ->assertOk()
            ->assertJsonPath('preview.valid_count', 2)
            ->assertSessionHas('source_issue.github_preview');

        $this->assertDatabaseCount('issues', 0);

        $response = $this->confirm($project);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('result.imported_count', 2)
            ->assertSessionMissing('source_issue.github_preview');

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('import_batches', 1);

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'GITHUB',
            'github_repo' => 'demo/bugs',
            'github_number' => 101,
        ]);

        $this->assertDatabaseHas('import_batches', [
            'project_id' => $project->id,
            'source_type' => 'GITHUB',
            'valid_rows' => 2,
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'action' => 'IMPORT_GITHUB',
        ]);

        $config->refresh();
        $this->assertNotNull($config->last_synced_at);

        Bus::assertDispatched(
            DetectIssueCandidatesJob::class,
            2
        );

        $this->assertStringNotContainsString(
            'secret-import-token',
            $response->getContent()
        );

        Http::assertSentCount(2);
    }

    public function test_import_without_preview_is_rejected(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake();

        $this->actingAs($owner);

        $this->confirm($project)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Http::assertNothingSent();
    }

    public function test_import_requires_explicit_confirmation(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake();

        $this->actingAs($owner);

        $this->confirm($project, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmed');

        $this->confirm($project, ['confirmed' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmed');

        $this->assertDatabaseCount('issues', 0);

        Http::assertNothingSent();
    }

    public function test_changed_github_data_rejects_import(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fakeSequence()
            ->push([$this->githubIssue(201)], 200)
            ->push([
                $this->githubIssue(201),
                $this->githubIssue(202),
            ], 200);

        $this->actingAs($owner);

        $this->preview($project)
            ->assertOk()
            ->assertJsonPath('preview.valid_count', 1);

        $this->confirm($project)
            ->assertStatus(422)
            ->assertJsonValidationErrors('github');

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );

        Http::assertSentCount(2);
    }

    public function test_expired_preview_is_rejected(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        Http::fake();

        $this->withSession([
            'source_issue.github_preview' => [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $owner->id,
                'config_id' => (int) $config->id,
                'repo_full_name' => 'demo/bugs',
                'fingerprint' => str_repeat('a', 64),
                'expires_at' => now()->subMinute()->timestamp,
            ],
        ]);

        $this->actingAs($owner);

        $this->confirm($project)
            ->assertStatus(409)
            ->assertSessionMissing('source_issue.github_preview');

        $this->assertDatabaseCount('issues', 0);

        Http::assertNothingSent();
    }

    public function test_changed_configuration_rejects_import(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        Http::fake([
            '*' => Http::response([
                $this->githubIssue(301),
            ], 200),
        ]);

        $this->actingAs($owner);

        $this->preview($project)->assertOk();

        // Simulate a repository configuration change
        // after preview but before confirmation.
        $config->repo_full_name = 'demo/new-repository';
        $config->save();

        $this->confirm($project)
            ->assertStatus(409)
            ->assertSessionMissing('source_issue.github_preview');

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Http::assertSentCount(1);
    }

    public function test_successful_preview_cannot_be_reused(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fake([
            '*' => Http::response([
                $this->githubIssue(401),
            ], 200),
        ]);

        $this->actingAs($owner);

        $this->preview($project)->assertOk();

        $this->confirm($project)
            ->assertOk()
            ->assertJsonPath('result.imported_count', 1);

        // The preview was consumed by the first import.
        $this->confirm($project)
            ->assertStatus(409);

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('import_batches', 1);

        Http::assertSentCount(2);
    }

    public function test_other_maintainer_cannot_confirm_import(): void
    {
        [, $project] = $this->makeProject();

        $otherMaintainer = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        Http::fake();

        $this->actingAs($otherMaintainer);

        $this->confirm($project)->assertForbidden();

        $this->assertDatabaseCount('issues', 0);

        Http::assertNothingSent();
    }

    public function test_guest_cannot_confirm_import(): void
    {
        [, $project] = $this->makeProject();

        Http::fake();

        $this->confirm($project)->assertUnauthorized();

        $this->assertDatabaseCount('issues', 0);

        Http::assertNothingSent();
    }

    public function test_github_api_failure_preserves_preview(): void
    {
        [$owner, $project] = $this->makeProject();

        Http::fakeSequence()
            ->push([$this->githubIssue(501)], 200)
            ->push([
                'message' => 'Internal Server Error',
            ], 500);

        $this->actingAs($owner);

        $this->preview($project)
            ->assertOk()
            ->assertSessionHas('source_issue.github_preview');

        $this->confirm($project)
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertSessionHas('source_issue.github_preview');

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }
}
