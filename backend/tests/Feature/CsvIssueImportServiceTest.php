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

    public function test_imported_csv_issue_persists_only_top_three_candidates(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);
        $otherProject = $this->makeProject($owner);

        // Existing comparison issues in the authorized project.
        $candidateA = $this->existingCsvIssue(
            $project, 'EXISTING-A'
        );

        $candidateB = $this->existingCsvIssue(
            $project, 'EXISTING-B'
        );

        $candidateC = $this->existingCsvIssue(
            $project, 'EXISTING-C'
        );

        $candidateD = $this->existingCsvIssue(
            $project, 'EXISTING-D'
        );

        // A foreign-project issue must never become a candidate.
        $foreign = $this->existingCsvIssue(
            $otherProject, 'FOREIGN-A'
        );

        config([
            'services.nlp.base_url' => 'http://nlp.test',
        ]);

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        // Import two valid issues in the same batch.
        $parsed = $this->parse(
            "source_id,title,description\n".
            "CSV-NEW-001,Login gagal,Pengguna gagal masuk ke aplikasi\n".
            "CSV-NEW-002,Login bermasalah,Pengguna tidak dapat masuk ke aplikasi\n".
            "EXISTING-A,Issue lama,Issue ini sudah pernah diimpor\n".
            "CSV-INVALID,No,pendek\n"
        );

        $result = $this->importer()->import(
            $project,
            $owner,
            $parsed,
            'top-three-regression.csv'
        );

        $this->assertSame(2, $result['imported_count']);
        $this->assertSame(1, $result['invalid_count']);
        $this->assertSame(1, $result['already_imported_count']);
        $this->assertCount(2, $result['issue_ids']);

        $source = Issue::query()
            ->whereKey($result['issue_ids'][0])
            ->firstOrFail();

        $batchPeer = Issue::query()
            ->whereKey($result['issue_ids'][1])
            ->firstOrFail();

        $this->assertSame('CSV', $source->source_type);
        $this->assertSame('PENDING', $source->detection_status);
        $this->assertSame('PENDING', $batchPeer->detection_status);

        // Import itself must not run inference synchronously.
        $this->assertDatabaseCount('duplicate_candidates', 0);

        Bus::assertDispatched(
            DetectIssueCandidatesJob::class,
            2
        );

        foreach ($result['issue_ids'] as $id) {
            Bus::assertDispatched(
                DetectIssueCandidatesJob::class,
                fn ($job) =>
                    $job->issueId === $id
                    && $job->connection === 'database'
                    && $job->queue === 'nlp'
            );
        }

        // Deliberately return unsorted results, including
        // malicious self and cross-project candidates.
        \Illuminate\Support\Facades\Http::fake([
            '*/candidates' =>
                \Illuminate\Support\Facades\Http::response([
                    'candidates' => [
                        [
                            'issue_id' => $candidateC->id,
                            'similarity' => 0.82,
                        ],
                        [
                            'issue_id' => $foreign->id,
                            'similarity' => 0.99,
                        ],
                        [
                            'issue_id' => $candidateB->id,
                            'similarity' => 0.87,
                        ],
                        [
                            'issue_id' => $source->id,
                            'similarity' => 1.0,
                        ],
                        [
                            'issue_id' => $candidateD->id,
                            'similarity' => 0.70,
                        ],
                        [
                            'issue_id' => $batchPeer->id,
                            'similarity' => 0.96,
                        ],
                        [
                            'issue_id' => $candidateA->id,
                            'similarity' => 0.91,
                        ],
                    ],
                ], 200),
        ]);

        // Execute the real worker logic against fake NLP HTTP.
        $job = new DetectIssueCandidatesJob($source->id);

        $job->handle(
            app(\App\Services\NlpClient::class)
        );

        \Illuminate\Support\Facades\Http::assertSentCount(1);

        // The comparison payload includes issues from the same
        // imported batch, but excludes self and foreign projects.
        \Illuminate\Support\Facades\Http::assertSent(
            function ($request) use (
                $source,
                $batchPeer,
                $foreign,
                $project,
                $candidateA,
                $candidateB,
                $candidateC,
                $candidateD
            ): bool {
                $payload = $request->data();

                $ids = collect($payload['candidates'])
                    ->pluck('issue_id');

                return (int) $payload['project_id'] === (int) $project->id
                    && (int) $payload['new_issue']['issue_id'] === (int) $source->id
                    && $ids->contains($batchPeer->id)
                    && $ids->contains($candidateA->id)
                    && $ids->contains($candidateB->id)
                    && $ids->contains($candidateC->id)
                    && $ids->contains($candidateD->id)
                    && ! $ids->contains($source->id)
                    && ! $ids->contains($foreign->id)
                    && collect($payload['candidates'])->every(
                        fn (array $candidate): bool =>
                            (int) $candidate['project_id'] === (int) $project->id
                    );
            }
        );

        $source->refresh();

        $this->assertSame('COMPLETED', $source->detection_status);
        $this->assertSame('OPEN', $source->status);
        $this->assertNull($source->duplicate_of);

        $saved = \App\Models\DuplicateCandidate::query()
            ->where('issue_id', $source->id)
            ->orderByDesc('similarity_score')
            ->get();

        // These exclusions already belong to the existing pipeline.
        $this->assertFalse(
            $saved->contains('candidate_issue_id', $source->id)
        );

        $this->assertFalse(
            $saved->contains('candidate_issue_id', $foreign->id)
        );

        $this->assertFalse(
            $saved->contains('candidate_issue_id', $candidateD->id)
        );

        // Regression target: the current implementation stores
        // four qualifying candidates instead of the required three.
        $this->assertCount(3, $saved);

        $this->assertSame(
            [
                (int) $batchPeer->id,
                (int) $candidateA->id,
                (int) $candidateB->id,
            ],
            $saved->pluck('candidate_issue_id')->all()
        );

        $this->assertSame(
            [0.96, 0.91, 0.87],
            $saved->pluck('similarity_score')->all()
        );

        $this->assertDatabaseMissing('duplicate_candidates', [
            'issue_id' => $source->id,
            'candidate_issue_id' => $candidateC->id,
        ]);

        $this->assertDatabaseHas('import_batches', [
            'id' => $result['batch_id'],
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseCount('issues', 7);
    }

    public static function candidateLimitCases(): array
    {
        return [
            'CSV maximum three' => ['CSV', 12, 3],
            'FORM retains ten' => ['FORM', 12, 10],
            'GITHUB retains ten' => ['GITHUB', 12, 10],
            'CSV no qualifying candidates' => ['CSV', 0, 0],
            'CSV one qualifying candidate' => ['CSV', 1, 1],
            'CSV two qualifying candidates' => ['CSV', 2, 2],
            'CSV exactly three candidates' => ['CSV', 3, 3],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('candidateLimitCases')]
    public function test_candidate_limit_respects_source_type(
        string $sourceType,
        int $qualifyingCount,
        int $expectedCount
    ): void {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $source = $this->existingCsvIssue(
            $project, 'SOURCE-ISSUE'
        );

        // Construct the actual source metadata for each source type.
        $source->source_type = $sourceType;

        if ($sourceType !== 'CSV') {
            $source->source_id = null;
        }

        if ($sourceType === 'FORM') {
            $source->author_id = $owner->id;
        }

        if ($sourceType === 'GITHUB') {
            $source->github_repo = 'example/test-repo';
            $source->github_number = 1;
            $source->source_url =
                'https://github.com/example/test-repo/issues/1';
        }

        $source->save();

        $candidates = [];
        $expectedIds = [];

        for ($i = 0; $i < $qualifyingCount; $i++) {
            $candidate = $this->existingCsvIssue(
                $project, "CANDIDATE-{$i}"
            );

            $candidates[] = [
                'issue_id' => $candidate->id,
                'similarity' => round(0.99 - ($i / 100), 2),
            ];

            $expectedIds[] = (int) $candidate->id;
        }

        // A candidate below 0.75 must always be excluded.
        $belowThreshold = $this->existingCsvIssue(
            $project, 'BELOW-THRESHOLD'
        );

        $candidates[] = [
            'issue_id' => $belowThreshold->id,
            'similarity' => 0.74,
        ];

        // Return candidates in reverse rank order.
        $candidates = array_reverse($candidates);

        config([
            'services.nlp.base_url' => 'http://nlp.test',
        ]);

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        \Illuminate\Support\Facades\Http::fake([
            '*/candidates' =>
                \Illuminate\Support\Facades\Http::response([
                    'candidates' => $candidates,
                ], 200),
        ]);

        (new DetectIssueCandidatesJob($source->id))->handle(
            app(\App\Services\NlpClient::class)
        );

        \Illuminate\Support\Facades\Http::assertSentCount(1);

        $source->refresh();

        $this->assertSame(
            $sourceType,
            $source->source_type
        );

        $this->assertSame(
            'COMPLETED',
            $source->detection_status
        );

        $this->assertSame('OPEN', $source->status);
        $this->assertNull($source->duplicate_of);

        $saved = \App\Models\DuplicateCandidate::query()
            ->where('issue_id', $source->id)
            ->orderByDesc('similarity_score')
            ->get();

        $this->assertCount($expectedCount, $saved);

        $this->assertSame(
            array_slice($expectedIds, 0, $expectedCount),
            $saved->pluck('candidate_issue_id')->all()
        );

        $this->assertDatabaseMissing('duplicate_candidates', [
            'issue_id' => $source->id,
            'candidate_issue_id' => $belowThreshold->id,
        ]);

        foreach ($saved as $candidate) {
            $this->assertSame('PENDING', $candidate->decision);
            $this->assertGreaterThanOrEqual(
                0.75,
                $candidate->similarity_score
            );
        }
    }

    public function test_csv_equal_scores_have_stable_ranking_and_idempotent_delivery(): void
    {
        $owner = $this->makeUser('MAINTAINER');
        $project = $this->makeProject($owner);

        $source = $this->existingCsvIssue(
            $project, 'TIE-SOURCE'
        );

        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $candidate = $this->existingCsvIssue(
                $project, "TIE-CANDIDATE-{$i}"
            );

            $ids[] = (int) $candidate->id;
        }

        sort($ids);

        // Five equal scores returned in descending ID order.
        $response = array_map(
            fn (int $id): array => [
                'issue_id' => $id,
                'similarity' => 0.90,
            ],
            array_reverse($ids)
        );

        config([
            'services.nlp.base_url' => 'http://nlp.test',
        ]);

        \Illuminate\Support\Facades\Http::preventStrayRequests();

        \Illuminate\Support\Facades\Http::fake([
            '*/candidates' =>
                \Illuminate\Support\Facades\Http::response([
                    'candidates' => $response,
                ], 200),
        ]);

        $job = new DetectIssueCandidatesJob($source->id);

        $job->handle(
            app(\App\Services\NlpClient::class)
        );

        $query = fn () =>
            \App\Models\DuplicateCandidate::query()
                ->where('issue_id', $source->id)
                ->orderBy('candidate_issue_id')
                ->get();

        $before = $query();

        $this->assertCount(3, $before);

        $this->assertSame(
            array_slice($ids, 0, 3),
            $before->pluck('candidate_issue_id')->all()
        );

        $this->assertSame(
            [0.90, 0.90, 0.90],
            $before->pluck('similarity_score')->all()
        );

        $originalRows = $before->map(
            fn ($candidate) => $candidate->getRawOriginal()
        )->all();

        // Simulate a repeated delivery after successful completion.
        (new DetectIssueCandidatesJob($source->id))->handle(
            app(\App\Services\NlpClient::class)
        );

        // Simulate a late failure notification from another delivery.
        $job->failed(
            new \RuntimeException('Simulated late queue failure')
        );

        $after = $query();

        $this->assertCount(3, $after);

        $this->assertSame(
            $originalRows,
            $after->map(
                fn ($candidate) => $candidate->getRawOriginal()
            )->all()
        );

        $this->assertSame(
            'COMPLETED',
            $source->refresh()->detection_status
        );

        $this->assertSame('OPEN', $source->status);
        $this->assertNull($source->duplicate_of);

        \Illuminate\Support\Facades\Http::assertSentCount(1);
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
