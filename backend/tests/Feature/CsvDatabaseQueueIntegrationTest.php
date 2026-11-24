<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\CsvIssueImportService;
use App\Services\CsvIssuePreviewService;
use App\Services\CsvIssueReader;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CsvDatabaseQueueIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // Never use a persistent or production database.
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());

        $this->assertSame(
            'database',
            config('queue.connections.database.driver')
        );

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'queue.connections.database.table' => 'jobs',
            'services.nlp.base_url' => 'http://nlp.test',
        ]);

        // Use the real Laravel Bus and database queue.
        // No Bus::fake() or Queue::fake() is used.

        Http::preventStrayRequests();
        Http::fake([]);
    }

    private function fixtures(): array
    {
        $owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $project = Project::factory()->create([
            'owner_id' => $owner->id,
        ]);

        return [$owner, $project];
    }

    private function parseCsv(int $count): array
    {
        $csv = "source_id,title,description\n";

        for ($i = 1; $i <= $count; $i++) {
            $csv .= sprintf(
                "CSV-%05d,Login application issue %d,User cannot access the application during test case %d\n",
                $i,
                $i,
                $i
            );
        }

        $file = UploadedFile::fake()->createWithContent(
            'queue-capacity.csv',
            $csv
        );

        return app(CsvIssueReader::class)->read($file);
    }

    private function importWorkerFixture(): array
    {
        [$owner, $project] = $this->fixtures();

        $comparisonIds = [];

        // Four existing issues, without scheduling additional jobs.
        for ($i = 1; $i <= 4; $i++) {
            $candidate = Issue::forceCreate([
                'project_id' => $project->id,
                'author_id' => null,
                'source_type' => 'CSV',
                'source_id' => "EXISTING-{$i}",
                'title' => "Existing login problem {$i}",
                'description' =>
                    "An existing application login problem number {$i}.",
                'status' => 'OPEN',
                'detection_status' => 'COMPLETED',
                'vote_count' => 0,
                'duplicate_of' => null,
            ]);

            $comparisonIds[] = (int) $candidate->id;
        }

        $result = app(CsvIssueImportService::class)->import(
            $project,
            $owner,
            $this->parseCsv(1),
            'worker-test.csv'
        );

        $this->assertSame(1, $result['imported_count']);

        $issue = Issue::query()
            ->findOrFail($result['issue_ids'][0]);

        $this->assertSame(
            'PENDING',
            $issue->detection_status
        );

        $this->assertDatabaseCount('issues', 5);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('duplicate_candidates', 0);

        Http::assertNothingSent();

        return [$issue, $comparisonIds, $result['batch_id']];
    }

    private function runNlpWorkerOnce(): void
    {
        $this->assertSame('testing', app()->environment());

        $this->assertSame(
            'sqlite',
            DB::connection()->getDriverName()
        );

        $this->assertSame(
            ':memory:',
            DB::connection()->getDatabaseName()
        );

        // Execute the actual Laravel queue worker in this process.
        // The worker shares only this test's in-memory database.
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'nlp',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 3,
            '--timeout' => 60,
            '--force' => true,
        ])->assertExitCode(0);
    }

    private function assertImportedIssuePreserved(
        Issue $issue,
        int $batchId,
        string $detectionStatus
    ): void {
        $issue->refresh();

        $this->assertSame('CSV', $issue->source_type);
        $this->assertSame('CSV-00001', $issue->source_id);
        $this->assertSame('OPEN', $issue->status);
        $this->assertNull($issue->duplicate_of);

        $this->assertSame(
            $detectionStatus,
            $issue->detection_status
        );

        $this->assertDatabaseCount('issues', 5);
        $this->assertDatabaseCount('import_batches', 1);

        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'status' => 'COMPLETED',
        ]);

        $this->assertSame(
            [],
            DB::select('PRAGMA foreign_key_check')
        );
    }

    public function test_csv_worker_retries_transient_failure_and_persists_top_three(): void
    {
        $this->freezeTime();

        [$issue, $ids, $batchId] = $this->importWorkerFixture();

        Http::fakeSequence('http://nlp.test/candidates')
            ->push(['detail' => 'Temporarily unavailable'], 503)
            ->push([
                'candidates' => [
                    [
                        'issue_id' => $ids[3],
                        'similarity' => 0.82,
                    ],
                    [
                        'issue_id' => $ids[1],
                        'similarity' => 0.91,
                    ],
                    [
                        'issue_id' => $ids[0],
                        'similarity' => 0.96,
                    ],
                    [
                        'issue_id' => $ids[2],
                        'similarity' => 0.87,
                    ],
                ],
            ], 200);

        // First attempt receives HTTP 503.
        $this->runNlpWorkerOnce();

        Http::assertSentCount(1);

        $this->assertImportedIssuePreserved(
            $issue, $batchId, 'PROCESSING'
        );

        $this->assertDatabaseCount('duplicate_candidates', 0);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('failed_jobs', 0);

        $retry = DB::table('jobs')->sole();

        $this->assertSame(1, (int) $retry->attempts);
        $this->assertNull($retry->reserved_at);

        $this->assertSame(
            now()->timestamp + 10,
            (int) $retry->available_at
        );

        // Worker must not retry before backoff expires.
        $this->runNlpWorkerOnce();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 1);

        // Advance the test clock, not the real clock.
        $this->travel(11)->seconds();

        $this->runNlpWorkerOnce();

        Http::assertSentCount(2);

        $this->assertImportedIssuePreserved(
            $issue, $batchId, 'COMPLETED'
        );

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);

        $saved = \App\Models\DuplicateCandidate::query()
            ->where('issue_id', $issue->id)
            ->orderByDesc('similarity_score')
            ->get();

        $this->assertCount(3, $saved);

        $this->assertSame(
            array_slice($ids, 0, 3),
            $saved->pluck('candidate_issue_id')->all()
        );

        $this->assertSame(
            [0.96, 0.91, 0.87],
            $saved->pluck('similarity_score')->all()
        );

        $this->assertDatabaseMissing('duplicate_candidates', [
            'issue_id' => $issue->id,
            'candidate_issue_id' => $ids[3],
        ]);

        $this->travelBack();
    }

    public function test_csv_worker_marks_failed_after_three_transient_attempts(): void
    {
        $this->freezeTime();

        [$issue, , $batchId] = $this->importWorkerFixture();

        Http::fake([
            'http://nlp.test/candidates' =>
                Http::response([
                    'detail' => 'Service unavailable',
                ], 503),
        ]);

        // Attempt 1.
        $this->runNlpWorkerOnce();

        Http::assertSentCount(1);

        $this->assertImportedIssuePreserved(
            $issue, $batchId, 'PROCESSING'
        );

        $firstRetry = DB::table('jobs')->sole();

        $this->assertSame(
            1,
            (int) $firstRetry->attempts
        );

        $this->assertSame(
            now()->timestamp + 10,
            (int) $firstRetry->available_at
        );

        $this->assertDatabaseCount('failed_jobs', 0);

        // Attempt 2.
        $this->travel(11)->seconds();

        $this->runNlpWorkerOnce();

        Http::assertSentCount(2);

        $this->assertImportedIssuePreserved(
            $issue, $batchId, 'PROCESSING'
        );

        $secondRetry = DB::table('jobs')->sole();

        $this->assertSame(
            2,
            (int) $secondRetry->attempts
        );

        $this->assertSame(
            now()->timestamp + 30,
            (int) $secondRetry->available_at
        );

        $this->assertDatabaseCount('failed_jobs', 0);

        // Attempt 3 exhausts the configured retry limit.
        $this->travel(31)->seconds();

        $this->runNlpWorkerOnce();

        Http::assertSentCount(3);

        $this->assertImportedIssuePreserved(
            $issue, $batchId, 'FAILED'
        );

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('duplicate_candidates', 0);

        $this->assertDatabaseHas('failed_jobs', [
            'connection' => 'database',
            'queue' => 'nlp',
        ]);

        // No unexpected fourth attempt.
        $this->travel(61)->seconds();

        $this->runNlpWorkerOnce();

        Http::assertSentCount(3);

        $this->travelBack();
    }

    public function test_csv_worker_does_not_retry_permanent_nlp_error(): void
    {
        [$issue, , $batchId] = $this->importWorkerFixture();

        Http::fake([
            'http://nlp.test/candidates' =>
                Http::response([
                    'detail' => 'Invalid NLP request',
                ], 422),
        ]);

        $this->runNlpWorkerOnce();

        $this->assertImportedIssuePreserved(
            $issue, $batchId, 'FAILED'
        );

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('duplicate_candidates', 0);

        // Permanent errors are handled by the job itself,
        // rather than exhausting Laravel queue retries.
        $this->assertDatabaseCount('failed_jobs', 0);

        $this->runNlpWorkerOnce();

        Http::assertSentCount(1);
    }

    public function test_imports_5000_csv_rows_into_real_database_queue(): void
    {
        [$owner, $project] = $this->fixtures();

        $parsed = $this->parseCsv(5000);

        $this->assertSame(5000, $parsed['total_rows']);

        // Preview must not create issues or queue jobs.
        $preview = app(CsvIssuePreviewService::class)
            ->preview($project, $parsed);

        $this->assertSame(5000, $preview['valid_count']);
        $this->assertSame(0, $preview['invalid_count']);
        $this->assertSame(0, $preview['already_imported_count']);

        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('duplicate_candidates', 0);

        Http::assertNothingSent();

        unset($preview);

        $startedAt = microtime(true);

        // Import using the original service.
        $result = app(CsvIssueImportService::class)->import(
            $project,
            $owner,
            $parsed,
            'queue-capacity.csv'
        );

        $elapsed = microtime(true) - $startedAt;

        $this->assertSame(5000, $result['imported_count']);
        $this->assertSame(0, $result['invalid_count']);
        $this->assertSame(0, $result['already_imported_count']);
        $this->assertCount(5000, $result['issue_ids']);
        $this->assertCount(5000, array_unique($result['issue_ids']));

        $this->assertDatabaseCount('issues', 5000);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('jobs', 5000);
        $this->assertDatabaseCount('duplicate_candidates', 0);

        $this->assertSame(
            5000,
            Issue::query()
                ->where('project_id', $project->id)
                ->where('source_type', 'CSV')
                ->where('status', 'OPEN')
                ->where('detection_status', 'PENDING')
                ->whereNull('duplicate_of')
                ->count()
        );

        $this->assertSame(
            5000,
            DB::table('jobs')
                ->where('queue', 'nlp')
                ->where('attempts', 0)
                ->whereNull('reserved_at')
                ->count()
        );

        $this->assertDatabaseHas('import_batches', [
            'id' => $result['batch_id'],
            'project_id' => $project->id,
            'actor_id' => $owner->id,
            'source_type' => 'CSV',
            'total_rows' => 5000,
            'valid_rows' => 5000,
            'skipped_rows' => 0,
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'action' => 'IMPORT_CSV',
        ]);

        // Inspect the actual persisted Laravel job payloads.
        // Only unserialize the known job class generated by this test.

        $queuedIssueIds = [];

        foreach (
            DB::table('jobs')
                ->where('queue', 'nlp')
                ->orderBy('id')
                ->cursor() as $row
        ) {
            $payload = json_decode(
                $row->payload,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                ($payload['data']['commandName'] ?? null)
                !== DetectIssueCandidatesJob::class
            ) {
                $this->fail('Unexpected database queue job class.');
            }

            $job = unserialize(
                $payload['data']['command'],
                [
                    'allowed_classes' => [
                        DetectIssueCandidatesJob::class,
                    ],
                ]
            );

            if (! $job instanceof DetectIssueCandidatesJob) {
                $this->fail('Invalid serialized NLP job.');
            }

            if (
                $job->connection !== 'database'
                || $job->queue !== 'nlp'
                || $job->tries !== 3
            ) {
                $this->fail('Invalid NLP queue configuration.');
            }

            $queuedIssueIds[] = $job->issueId;
        }

        $expectedIds = array_map(
            'intval',
            $result['issue_ids']
        );

        sort($expectedIds, SORT_NUMERIC);
        sort($queuedIssueIds, SORT_NUMERIC);

        $this->assertSame(
            $expectedIds,
            $queuedIssueIds,
            'Each imported issue must have exactly one NLP job.'
        );

        // Import must not execute inference synchronously.
        Http::assertNothingSent();

        fwrite(
            STDOUT,
            sprintf(
                "\nCSV CAPACITY RESULT: ".
                "5000 issues, 5000 database jobs, ".
                "0 NLP HTTP requests, import %.3f seconds, ".
                "peak memory %.2f MB\n",
                $elapsed,
                memory_get_peak_usage(true) / 1048576
            )
        );
    }

    public function test_rolled_back_csv_import_never_releases_database_jobs(): void
    {
        [$owner, $project] = $this->fixtures();

        $parsed = $this->parseCsv(2);

        try {
            DB::transaction(function () use (
                $project,
                $owner,
                $parsed
            ): void {
                $result = app(CsvIssueImportService::class)->import(
                    $project,
                    $owner,
                    $parsed,
                    'rollback-test.csv'
                );

                $this->assertSame(
                    2,
                    $result['imported_count']
                );

                // Nested import has finished but the outer
                // transaction has not yet committed.
                $this->assertDatabaseCount('issues', 2);
                $this->assertDatabaseCount('jobs', 0);

                Http::assertNothingSent();

                throw new RuntimeException(
                    'Simulated outer transaction rollback'
                );
            });

            $this->fail('Expected transaction rollback.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated outer transaction rollback',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('import_batches', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('duplicate_candidates', 0);

        // A later successful transaction must not release
        // callbacks belonging to the rolled-back import.
        $result = app(CsvIssueImportService::class)->import(
            $project,
            $owner,
            $parsed,
            'successful-import.csv'
        );

        $this->assertSame(2, $result['imported_count']);

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertDatabaseCount('import_batches', 1);

        Http::assertNothingSent();
    }
}
