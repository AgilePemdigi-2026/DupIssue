<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubAppConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubIssueImportService;
use App\Services\GithubIssuePreviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GithubAppIssueImportServiceTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;
    private Project $project;
    private GithubAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

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
                'title' => 'GitHub App test issue',
                'body' => 'This issue is used to test GitHub App import.',
            ],
        ];
    }

    private function fingerprint(array $issues): string
    {
        $preview = app(
            GithubIssuePreviewService::class
        )->preview(
            $this->project,
            'example/test-repo',
            $issues
        );

        return hash(
            'sha256',
            json_encode(
                $preview['rows'],
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function import(
        ?int $connectionId = null,
        ?int $installationId = null,
        ?int $repositoryId = null,
        ?string $fingerprint = null,
        ?User $actor = null
    ): array {
        $issues = $this->issues();

        return app(
            GithubIssueImportService::class
        )->importFromApp(
            $this->project,
            $actor ?? $this->owner,
            $connectionId ?? $this->connection->id,
            $installationId ?? 12345,
            $repositoryId ?? 5678,
            'example/test-repo',
            $issues,
            $fingerprint ?? $this->fingerprint($issues)
        );
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Import should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'github',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }

    public function test_import_succeeds_without_pat(): void
    {
        // Membuktikan GitHub App tidak memerlukan konfigurasi PAT.
        $this->assertDatabaseCount('github_configs', 0);

        $result = $this->import();

        $this->assertSame(1, $result['imported_count']);
        $this->assertSame(0, $result['invalid_count']);

        $this->assertDatabaseHas('issues', [
            'project_id' => $this->project->id,
            'source_type' => 'GITHUB',
            'github_repo' => 'example/test-repo',
            'github_number' => 101,
            'detection_status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('import_batches', [
            'id' => $result['batch_id'],
            'project_id' => $this->project->id,
            'source_type' => 'GITHUB',
            'status' => 'COMPLETED',
            'valid_rows' => 1,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'action' => 'IMPORT_GITHUB',
        ]);

        $audit = DB::table('activity_logs')
            ->where('project_id', $this->project->id)
            ->where('action', 'IMPORT_GITHUB')
            ->first();

        $this->assertNotNull($audit);

        $this->assertStringNotContainsString(
            'installation-token',
            $audit->new_values
        );

        Bus::assertDispatched(
            DetectIssueCandidatesJob::class,
            1
        );

        // Koneksi App tetap tersedia setelah impor.
        $this->assertDatabaseCount(
            'github_app_connections',
            1
        );
    }

    public function test_wrong_connection_id_is_rejected(): void
    {
        $this->assertRejected(
            fn () => $this->import(
                connectionId: $this->connection->id + 999
            )
        );
    }

    public function test_changed_installation_id_is_rejected(): void
    {
        $this->connection->installation_id = 77777;
        $this->connection->save();

        $this->assertRejected(
            fn () => $this->import()
        );
    }

    public function test_changed_repository_id_is_rejected(): void
    {
        $this->connection->repository_id = 88888;
        $this->connection->save();

        $this->assertRejected(
            fn () => $this->import()
        );
    }

    public function test_changed_fingerprint_is_rejected(): void
    {
        $this->assertRejected(
            fn () => $this->import(
                fingerprint: str_repeat('a', 64)
            )
        );
    }

    public function test_non_owner_cannot_import(): void
    {
        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        try {
            $this->import(actor: $other);
            $this->fail('Non-owner import must be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty(
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }
}
