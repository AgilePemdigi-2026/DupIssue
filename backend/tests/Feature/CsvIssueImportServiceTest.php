<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\CsvIssueImportService;
use App\Services\CsvIssueReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CsvIssueImportServiceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // Tangkap dispatch job tanpa menjalankan NLP.
        Bus::fake();
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function makeProject(User $owner): Project
    {
        $project = new Project([
            'name' => 'CSV Import Test '.uniqid(),
            'description' => 'Project pengujian Feature 11',
        ]);

        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    private function existingCsvIssue(
        Project $project,
        string $sourceId
    ): Issue {
        $issue = new Issue([
            'title' => 'Issue sebelumnya',
            'description' => 'Issue CSV yang sudah tersimpan.',
        ]);

        $issue->project()->associate($project);
        $issue->author_id = null;
        $issue->source_type = 'CSV';
        $issue->source_id = $sourceId;
        $issue->status = 'OPEN';
        $issue->detection_status = 'PENDING';
        $issue->save();

        return $issue;
    }

    private function parse(string $content): array
    {
        $file = UploadedFile::fake()->createWithContent(
            'dataset.csv',
            $content
        );

        return app(CsvIssueReader::class)->read($file);
    }

    private function validCsv(string $sourceId = 'BUG-001'): string
    {
        return "source_id,title,description\n".
            "{$sourceId},Login gagal,Pengguna tidak bisa masuk aplikasi\n";
    }

    private function importer(): CsvIssueImportService
    {
        return app(CsvIssueImportService::class);
    }

    public function test_imports_valid_new_rows_and_records_activity(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $this->existingCsvIssue($project, 'BUG-OLD');

        $parsed = $this->parse(
            "source_id,title,description\n".
            "BUG-001,Login gagal,Pengguna tidak bisa masuk aplikasi\n".
            "BUG-002,Dashboard error,Dashboard tidak bisa dibuka\n".
            "BUG-OLD,Issue lama,Issue ini sudah pernah diimpor\n".
            "BUG-BAD,No,pendek\n"
        );

        $result = $this->importer()->import(
            $project,
            $owner,
            $parsed,
            'dataset.csv'
        );

        $this->assertSame(2, $result['imported_count']);
        $this->assertSame(1, $result['invalid_count']);
        $this->assertSame(1, $result['already_imported_count']);
        $this->assertCount(2, $result['issue_ids']);

        $this->assertDatabaseCount('issues', 3);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('activity_logs', 1);

        $this->assertDatabaseHas('import_batches', [
            'id' => $result['batch_id'],
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'source_type' => 'CSV',
            'total_rows' => 4,
            'valid_rows' => 2,
            'skipped_rows' => 2,
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id,
            'source_type' => 'CSV',
            'source_id' => 'BUG-001',
            'author_id' => null,
            'status' => 'OPEN',
            'detection_status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'IMPORT_CSV',
        ]);

        Bus::assertDispatched(DetectIssueCandidatesJob::class, 2);

        foreach ($result['issue_ids'] as $id) {
            Bus::assertDispatched(
                DetectIssueCandidatesJob::class,
                fn ($job) =>
                    $job->issueId === $id
                    && $job->connection === 'database'
                    && $job->queue === 'nlp'
            );
        }
    }

    public function test_reimport_does_not_create_duplicate_issue(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);
        $parsed = $this->parse($this->validCsv());

        $this->importer()->import(
            $project,
            $owner,
            $parsed,
            'dataset.csv'
        );

        try {
            $this->importer()->import(
                $project,
                $owner,
                $parsed,
                'dataset.csv'
            );

            $this->fail('Impor ulang seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('activity_logs', 1);

        Bus::assertDispatched(DetectIssueCandidatesJob::class, 1);
    }

    public function test_reporter_cannot_import_csv(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $reporter = $this->makeUser('REPORTER');
        $project = $this->makeProject($owner);

        $parsed = $this->parse($this->validCsv());

        try {
            $this->importer()->import(
                $project,
                $reporter,
                $parsed,
                'dataset.csv'
            );

            $this->fail('Reporter seharusnya ditolak.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseCount('issues', 0);
            $this->assertDatabaseCount('import_batches', 0);
        }

        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_other_maintainer_cannot_import_csv(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $otherMaintainer = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $parsed = $this->parse($this->validCsv());

        try {
            $this->importer()->import(
                $project,
                $otherMaintainer,
                $parsed,
                'dataset.csv'
            );

            $this->fail('Maintainer lain seharusnya ditolak.');
        } catch (AuthorizationException $exception) {
            $this->assertDatabaseCount('issues', 0);
            $this->assertDatabaseCount('import_batches', 0);
        }

        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_failed_insert_rolls_back_entire_batch(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        // Simulasikan kegagalan pada insert issue kedua.
        DB::unprepared("
            CREATE TRIGGER fail_second_csv_insert
            BEFORE INSERT ON issues
            WHEN NEW.source_id = 'BUG-FAIL'
            BEGIN
                SELECT RAISE(ABORT, 'simulated insert failure');
            END
        ");

        $parsed = $this->parse(
            "source_id,title,description\n".
            "BUG-OK,Login gagal,Pengguna tidak bisa masuk aplikasi\n".
            "BUG-FAIL,Dashboard error,Dashboard tidak bisa dibuka\n"
        );

        
        try {
            $this->importer()->import(
                $project,
                $owner,
                $parsed,
                'dataset.csv'
            );

            $this->fail('Insert kedua seharusnya gagal.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'simulated insert failure',
                $exception->getMessage()
            );
        } finally {
            // Hapus trigger pengujian sebelum Laravel rollback migration.
            DB::unprepared(
                'DROP TRIGGER IF EXISTS fail_second_csv_insert'
            );
        }


        // Insert pertama juga harus dibatalkan.
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('activity_logs', 0);

        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }


    public function test_failed_nlp_dispatch_preserves_imported_issue(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        // Simulasikan kegagalan saat Laravel melakukan dispatch job.
        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException(
                'Simulated NLP queue dispatch failure'
            ));

        $parsed = $this->parse(
            $this->validCsv('BUG-NLP-FAIL')
        );

        $result = $this->importer()->import(
            $project,
            $owner,
            $parsed,
            'dataset.csv'
        );

        // Impor tetap dianggap berhasil.
        $this->assertSame(1, $result['imported_count']);
        $this->assertCount(1, $result['issue_ids']);

        // Issue tidak boleh hilang akibat kegagalan dispatch.
        $this->assertDatabaseCount('issues', 1);

        $this->assertDatabaseHas('issues', [
            'id' => $result['issue_ids'][0],
            'project_id' => $project->id,
            'source_type' => 'CSV',
            'source_id' => 'BUG-NLP-FAIL',
            'status' => 'OPEN',
            'detection_status' => 'FAILED',
        ]);

        // Batch yang sudah berhasil tetap disimpan.
        $this->assertDatabaseHas('import_batches', [
            'id' => $result['batch_id'],
            'project_id' => $project->id,
            'valid_rows' => 1,
            'status' => 'COMPLETED',
        ]);

        // Log aktivitas impor tetap tersedia.
        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'IMPORT_CSV',
        ]);
    }

}
