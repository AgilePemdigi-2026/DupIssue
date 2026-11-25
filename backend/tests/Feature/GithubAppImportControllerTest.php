<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubAppConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppIssueFetchService;
use App\Services\GithubIssuePreviewService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubAppImportControllerTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Project $project;
    private GithubAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Http::preventStrayRequests();

        $this->owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->id,
        ]);

        $this->connection = new GithubAppConnection();
        $this->connection->project_id = $this->project->id;
        $this->connection->installation_id = 12345;
        $this->connection->repository_id = 5678;
        $this->connection->repo_full_name = 'example/test-repo';
        $this->connection->verified_github_user_id = 999;
        $this->connection->verified_at = now();
        $this->connection->save();
    }

    private function issues(): array
    {
        return [
            [
                'number' => 101,
                'title' => 'GitHub App sample issue',
                'body' => 'Description used for the import confirmation test.',
            ],
        ];
    }

    private function pending(array $issues): array
    {
        $preview = app(
            GithubIssuePreviewService::class
        )->preview(
            $this->project,
            'example/test-repo',
            $issues
        );

        return [
            'project_id' => (int) $this->project->id,
            'actor_id' => (int) $this->owner->id,
            'connection_id' => (int) $this->connection->id,
            'installation_id' => 12345,
            'repository_id' => 5678,
            'repo_full_name' => 'example/test-repo',
            'fingerprint' => hash(
                'sha256',
                json_encode(
                    $preview['rows'],
                    JSON_THROW_ON_ERROR
                )
            ),
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
    }

    private function importUrl(): string
    {
        return route('sources.github.app.import', [
            'project' => $this->project->id,
        ]);
    }

    private function mockFetch(
        array $issues = [],
        ?RuntimeException $failure = null
    ): void {
        $mock = $this->createMock(
            GithubAppIssueFetchService::class
        );

        $expectation = $mock->expects($this->once())
            ->method('fetchIssues');

        if ($failure !== null) {
            $expectation->willThrowException($failure);
        } else {
            $expectation->willReturn($issues);
        }

        $this->app->instance(
            GithubAppIssueFetchService::class,
            $mock
        );
    }

    public function test_confirmed_import_succeeds(): void
    {
        $issues = $this->issues();

        $this->mockFetch($issues);

        $patPreview = ['marker' => 'existing-pat-preview'];

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' =>
                    $this->pending($issues),
                'source_issue.github_preview' => $patPreview,
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'result.imported_count',
            1
        );

        $response->assertSessionMissing(
            'source_issue.github_app_preview'
        );

        $response->assertSessionHas(
            'source_issue.github_preview',
            $patPreview
        );

        $this->assertDatabaseHas('issues', [
            'project_id' => $this->project->id,
            'source_type' => 'GITHUB',
            'github_repo' => 'example/test-repo',
            'github_number' => 101,
        ]);

        $this->assertDatabaseCount('import_batches', 1);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action' => 'IMPORT_GITHUB',
        ]);

        Bus::assertDispatched(
            DetectIssueCandidatesJob::class,
            1
        );

        Http::assertNothingSent();
    }

    public function test_import_without_preview_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('issues', 0);
        Http::assertNothingSent();
    }

    public function test_explicit_confirmation_is_required(): void
    {
        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' =>
                    $this->pending($this->issues()),
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => false,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('issues', 0);
        Http::assertNothingSent();
    }

    public function test_expired_preview_is_rejected(): void
    {
        $pending = $this->pending($this->issues());

        $pending['expires_at'] = now()
            ->subMinute()
            ->timestamp;

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' => $pending,
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ])
            ->assertStatus(409)
            ->assertSessionMissing(
                'source_issue.github_app_preview'
            );

        $this->assertDatabaseCount('issues', 0);
        Http::assertNothingSent();
    }

    public function test_changed_connection_is_rejected(): void
    {
        $pending = $this->pending($this->issues());

        $this->connection->repository_id = 99999;
        $this->connection->save();

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' => $pending,
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('issues', 0);
        Http::assertNothingSent();
    }

    public function test_changed_issue_data_is_rejected(): void
    {
        $original = $this->issues();
        $changed = $this->issues();

        $changed[0]['body'] =
            'This description changed after the original preview.';

        $this->mockFetch($changed);

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' =>
                    $this->pending($original),
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ])
            ->assertStatus(422)
            ->assertSessionHas(
                'source_issue.github_app_preview'
            );

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }

    public function test_non_owner_cannot_import(): void
    {
        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->actingAs($other)
            ->withSession([
                'source_issue.github_app_preview' =>
                    $this->pending($this->issues()),
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('issues', 0);
        Http::assertNothingSent();
    }

    public function test_fetch_failure_preserves_preview(): void
    {
        $this->mockFetch(
            [],
            new RuntimeException(
                'Simulated GitHub access failure.',
                403
            )
        );

        $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' =>
                    $this->pending($this->issues()),
            ])
            ->postJson($this->importUrl(), [
                'confirmed' => true,
            ])
            ->assertStatus(422)
            ->assertSessionHas(
                'source_issue.github_app_preview'
            );

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }
}
