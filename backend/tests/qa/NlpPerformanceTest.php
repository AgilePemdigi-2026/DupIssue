<?php

namespace Tests\QA;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bounded real worker experiment; never part of the default/mock CI suite. */
class NlpPerformanceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_bounded_cold_warm_incremental_database_workers(): void
    {
        $mode = getenv('QA_NLP_MODE');
        if (! in_array($mode, ['baseline', 'optimized'], true)) {
            throw new \RuntimeException('Explicit QA_NLP_MODE required.');
        }
        // TestCase asserts SQLite :memory: before any migration/fixture creation.
        $url = 'http://127.0.0.1:18766';
        config(['services.nlp.base_url' => $url, 'services.nlp.timeout' => 120]);
        Http::preventStrayRequests();
        Http::allowStrayRequests([$url.'/*']);
        $queries = 0;
        $queryMs = 0;
        DB::listen(function ($event) use (&$queries, &$queryMs): void {
            $queries++;
            $queryMs += $event->time;
        });
        $results = [];
        foreach ([10, 100, 1000, 5000] as $size) {
            $project = Project::factory()->create();
            $rows = [];
            for ($i = 2; $i <= $size; $i++) {
                $rows[] = ['project_id' => $project->id, 'title' => 'Checkout payment failure '.$i,
                    'description' => 'The checkout payment button sometimes fails for account '.$i.'.',
                    'status' => 'OPEN', 'source_type' => 'CSV', 'detection_status' => 'COMPLETED',
                    'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('issues')->insert($chunk);
            }
            $candidateId = Issue::where('project_id', $project->id)->min('id');
            $issue = Issue::forceCreate(['project_id' => $project->id, 'title' => 'Checkout payment failure',
                'description' => 'The checkout payment button sometimes fails for an account.',
                'source_type' => 'CSV', 'detection_status' => 'PENDING']);
            $phases = $mode === 'baseline' ? ['uncached'] : ['cold', 'warm', 'incremental'];
            foreach ($phases as $phase) {
                $runs = [];
                for ($i = 0; $i < 5; $i++) {
                    if (in_array($phase, ['cold', 'uncached'], true)) {
                        Http::post($url.'/qa/reset')->throw();
                    }
                    if ($phase === 'incremental') {
                        Issue::whereKey($candidateId)->update(['description' => 'Updated payment error number '.$i.'.']);
                        $issue->description = 'Typing a revised checkout failure report '.$i.'.';
                    }
                    // The worker changes DB state behind this model instance. Force
                    // a database update; assigning its original PENDING value is not dirty.
                    Issue::whereKey($issue->id)->update(['detection_status' => 'PENDING', 'description' => $issue->description]);
                    $this->assertSame('PENDING', $issue->fresh()->detection_status);
                    Bus::dispatch(new DetectIssueCandidatesJob($issue->id));
                    $queries = $queryMs = 0;
                    $before = Http::get($url.'/qa/stats')->throw()->json();
                    $start = microtime(true);
                    $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'nlp', '--once' => true,
                        '--sleep' => 0, '--force' => true, '--timeout' => 150])->assertExitCode(0);
                    $seconds = microtime(true) - $start;
                    $queryCount = $queries;
                    $queryTime = $queryMs;
                    $after = Http::get($url.'/qa/stats')->throw()->json();
                    $this->assertGreaterThan(10, $queryCount); // Reject idempotent no-op deliveries.
                    $expectedEncodes = $mode === 'baseline' || $phase === 'cold' ? $size : ($phase === 'warm' ? 0 : 2);
                    $this->assertSame($expectedEncodes, $after['encoded_texts'] - $before['encoded_texts']);
                    $this->assertSame('COMPLETED', $issue->fresh()->detection_status);
                    $this->assertDatabaseCount('jobs', 0);
                    $this->assertDatabaseCount('failed_jobs', 0);
                    $candidates = DB::table('duplicate_candidates')->where('issue_id', $issue->id)->get();
                    $this->assertCount(3, $candidates);
                    $this->assertTrue($candidates->every(fn ($c) => $c->similarity_score >= .75));
                    $runs[] = ['seconds' => $seconds, 'queries' => $queryCount, 'query_ms' => $queryTime,
                        'php_peak_mib' => memory_get_peak_usage(true) / 1048576,
                        'encode_seconds' => $after['encode_seconds'] - $before['encode_seconds'],
                        'encoded_texts' => $after['encoded_texts'] - $before['encoded_texts'], 'cache' => $after['cache']];
                }
                $results[] = ['size' => $size, 'phase' => $phase, 'runs' => $runs];
                file_put_contents(dirname(__DIR__, 3).'/.qa/f2-queue-'.$mode.'.json', json_encode($results, JSON_PRETTY_PRINT));
                fwrite(STDOUT, "\nQUEUE ".json_encode(['size' => $size, 'phase' => $phase, 'seconds' => array_column($runs, 'seconds'),
                    'queries' => array_column($runs, 'queries')])."\n");
            }
        }
    }
}
