<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubConfig;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubIssueImportService;
use App\Services\GithubIssuePreviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class GithubIssueImportServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function makeProject(
        bool $withConfig = true
    ): array {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $config = null;

        if ($withConfig) {
            $config = new GithubConfig();
            $config->project_id = $project->id;
            $config->repo_full_name = 'demo/bugs';
            $config->pat_encrypted = 'secret-import-token';
            $config->save();
        }

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

    private function fingerprint(
        Project $project,
        array $issues,
        string $repo = 'demo/bugs'
    ): string {
        $preview = app(
            GithubIssuePreviewService::class
        )->preview($project, $repo, $issues);

        return hash(
            'sha256',
            json_encode(
                $preview['rows'],
                JSON_THROW_ON_ERROR
            )
        );
    }

    private function import(
        Project $project,
        User $actor,
        int $configId,
        array $issues,
        string $fingerprint,
        string $repo = 'demo/bugs'
    ): array {
        return app(
            GithubIssueImportService::class
        )->import(
            $project,
            $actor,
            $configId,
            $repo,
            $issues,
            $fingerprint
        );
    }

    private function existingIssue(
        Project $project,
        int $number
    ): void {
        Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => null,
            'title' => 'Issue yang sudah pernah diimpor',
            'description' => 'Deskripsi issue GitHub lama.',
            'status' => 'OPEN',
            'detection_status' => 'PENDING',
            'source_type' => 'GITHUB',
            'github_repo' => 'demo/bugs',
            'github_number' => $number,
            'source_url' =>
                "https://github.com/demo/bugs/issues/{$number}",
        ]);
    }

    public function test_successful_import_creates_batch_audit_and_nlp_jobs(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        $this->existingIssue($project, 102);

        $invalid = $this->githubIssue(103);
        $invalid['title'] = 'Bug';

        $pullRequest = $this->githubIssue(104);
        $pullRequest['pull_request'] = [
            'url' => 'https://api.github.com/repos/demo/bugs/pulls/104',
        ];

        $issues = [
            $this->githubIssue(101),
            $this->githubIssue(102),
            $invalid,
            $pullRequest,
            $this->githubIssue(105),
        ];

        $result = $this->import(
            $project,
            $owner,
            $config->id,
            $issues,
            $this->fingerprint($project, $issues)
        );

        $this->assertSame(2, $result['imported_count']);
        $this->assertSame(1, $result['invalid_count']);
        $this->assertSame(
            1,
            $result['already_imported_count']
        );

        // One existing issue and two newly imported issues.
        $this->assertDatabaseCount('issues', 3);
        $this->assertDatabaseCount('import_batches', 1);

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'GITHUB',
            'github_repo' => 'demo/bugs',
            'github_number' => 101,
            'detection_status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'GITHUB',
            'github_number' => 105,
        ]);

        $this->assertDatabaseMissing('issues', [
            'project_id' => $project->id,
            'source_type' => 'GITHUB',
            'github_number' => 104,
        ]);

        $this->assertDatabaseHas('import_batches', [
            'id' => $result['batch_id'],
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'source_type' => 'GITHUB',
            'total_rows' => 4,
            'valid_rows' => 2,
            'skipped_rows' => 2,
            'status' => 'COMPLETED',
        ]);

        $batch = DB::table('import_batches')
            ->where('id', $result['batch_id'])
            ->first();

        $this->assertNull($batch->file_name);

        $audit = DB::table('activity_logs')
            ->where('project_id', $project->id)
            ->where('action', 'IMPORT_GITHUB')
            ->first();

        $this->assertNotNull($audit);

        $metadata = json_decode(
            $audit->new_values,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame(2, $metadata['imported_count']);
        $this->assertSame(
            'demo/bugs',
            $metadata['repo_full_name']
        );

        $this->assertStringNotContainsString(
            'secret-import-token',
            $audit->new_values
        );

        $config->refresh();

        $this->assertNotNull($config->last_synced_at);

        // DatabaseMigrations allows afterCommit to execute.
        Bus::assertDispatched(
            DetectIssueCandidatesJob::class,
            2
        );
    }

    public function test_changed_preview_fingerprint_rejects_import(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        $original = [$this->githubIssue(201)];
        $changed = [
            $this->githubIssue(201),
            $this->githubIssue(202),
        ];

        try {
            $this->import(
                $project,
                $owner,
                $config->id,
                $changed,
                $this->fingerprint($project, $original)
            );

            $this->fail('Impor seharusnya ditolak.');
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

    public function test_changed_repository_rejects_import(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        $issues = [$this->githubIssue(301)];

        try {
            $this->import(
                $project,
                $owner,
                $config->id,
                $issues,
                $this->fingerprint($project, $issues),
                'demo/old-repository'
            );

            $this->fail('Repository lama harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'github',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_missing_github_configuration_rejects_import(): void
    {
        [$owner, $project] = $this->makeProject(false);

        $issues = [$this->githubIssue(401)];

        try {
            $this->import(
                $project,
                $owner,
                999,
                $issues,
                $this->fingerprint($project, $issues)
            );

            $this->fail('Konfigurasi kosong harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'github',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_non_owner_maintainer_cannot_import(): void
    {
        [, $project, $config] = $this->makeProject();

        $otherMaintainer = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $issues = [$this->githubIssue(501)];

        try {
            $this->import(
                $project,
                $otherMaintainer,
                $config->id,
                $issues,
                $this->fingerprint($project, $issues)
            );

            $this->fail('Maintainer bukan pemilik harus ditolak.');
        } catch (AuthorizationException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_all_already_imported_issues_are_rejected(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        $this->existingIssue($project, 601);

        $issues = [$this->githubIssue(601)];

        try {
            $this->import(
                $project,
                $owner,
                $config->id,
                $issues,
                $this->fingerprint($project, $issues)
            );

            $this->fail('Tidak boleh membuat batch kosong.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'github',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('import_batches', 0);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }

    public function test_issue_creation_failure_rolls_back_transaction(): void
    {
        [$owner, $project, $config] = $this->makeProject();

        $issues = [$this->githubIssue(701)];

        $fingerprint = $this->fingerprint(
            $project,
            $issues
        );

        // Simulate an unexpected database write failure
        // after the import batch has been created.
        Event::listen(
            'eloquent.creating: ' . Issue::class,
            static function (): void {
                throw new RuntimeException(
                    'Simulated issue creation failure'
                );
            }
        );

        try {
            $this->import(
                $project,
                $owner,
                $config->id,
                $issues,
                $fingerprint
            );

            $this->fail('Transaksi harus gagal.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated issue creation failure',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);

        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'IMPORT_GITHUB',
            'project_id' => $project->id,
        ]);

        $config->refresh();

        $this->assertNull($config->last_synced_at);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );
    }
}
