<?php

namespace Tests\QA;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Explicit opt-in file outside PHPUnit's default Unit/Feature discovery.
class LiveRecommendationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('NLP_LIVE_TEST_URL');
        if ($url !== 'http://127.0.0.1:18765') {
            throw new \RuntimeException('Dedicated loopback NLP QA endpoint required.');
        }
        config(['services.nlp.base_url' => $url, 'services.nlp.timeout' => 120]);
    }

    private function expectedExtensionResponse(array $web): array
    {
        $web['recommendations'] = array_values(array_filter(
            $web['recommendations'],
            static fn (array $row): bool => $row['cosine_similarity'] >= 0.75
        ));

        return $web;
    }

    public function test_real_model_web_and_extension_match(): void
    {
        $user = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $project = Project::factory()->create();
        Membership::factory()->create(['project_id' => $project->id, 'user_id' => $user->id, 'is_active' => true]);
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => $project->id, 'installation_id' => 12, 'repository_id' => 34,
            'repo_full_name' => 'example/repo', 'verified_github_user_id' => 56, 'verified_at' => now()])->save();
        $input = ['title' => 'Checkout button does not respond', 'description' => 'Clicking the checkout button does not complete the payment.'];
        $issue = Issue::forceCreate($input + ['project_id' => $project->id, 'detection_status' => 'COMPLETED']);
        $auth = app(ExtensionAuthService::class);
        $token = $auth->exchange($auth->createPairingCode($user))['access_token'];
        $extension = $this->postJson('/api/extension/v1/recommendations', $input + ['repository' => 'example/repo', 'project_id' => $project->id], ['Authorization' => 'Bearer '.$token])->assertOk()->json();
        $web = $this->actingAs($user)->postJson(route('issues.recommendations', $project), $input)->assertOk()->json();
        $this->assertSame($web, $extension);
        $this->assertSame($issue->id, $extension['recommendations'][0]['issue_id']);
        $this->assertGreaterThanOrEqual(.99, $extension['recommendations'][0]['cosine_similarity']);


        // The web endpoint retains its existing ranking contract.
        // The extension must exclude recommendations below 0.75.
        $unrelated = Issue::forceCreate([
            'project_id' => $project->id,
            'title' => 'Observing distant galaxies through a telescope',
            'description' => 'Astronomers study distant stars, galaxies and nebulae.',
            'detection_status' => 'COMPLETED',
        ]);

        $mixedWeb = $this->actingAs($user)->postJson(
            route('issues.recommendations', $project),
            $input
        )->assertOk()->json();

        $mixedExtension = $this->postJson(
            '/api/extension/v1/recommendations',
            $input + [
                'repository' => 'example/repo',
                'project_id' => $project->id,
            ],
            ['Authorization' => 'Bearer '.$token]
        )->assertOk()->json();

        $webCandidates = array_column(
            $mixedWeb['recommendations'],
            null,
            'issue_id'
        );

        $this->assertArrayHasKey($unrelated->id, $webCandidates);
        $this->assertLessThan(
            0.75,
            $webCandidates[$unrelated->id]['cosine_similarity']
        );

        $this->assertSame(
            $this->expectedExtensionResponse($mixedWeb),
            $mixedExtension
        );

        $this->assertCount(1, $mixedExtension['recommendations']);
        $this->assertSame(
            $issue->id,
            $mixedExtension['recommendations'][0]['issue_id']
        );
    }

    public function test_bounded_real_queue_benchmark_at_representative_project_sizes(): void
    {
        $results = [];
        foreach ([10, 100, 1000, 5000] as $size) {
            $project = Project::factory()->create();
            $rows = [];
            for ($i = 1; $i < $size; $i++) {
                $rows[] = ['project_id' => $project->id, 'title' => 'Checkout payment failure '.$i,
                    'description' => 'The checkout payment button sometimes fails for account '.$i.'.', 'status' => 'OPEN', 'source_type' => 'CSV',
                    'detection_status' => 'COMPLETED', 'created_at' => now(), 'updated_at' => now()];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('issues')->insert($chunk);
            }
            $issue = Issue::forceCreate(['project_id' => $project->id, 'title' => 'Checkout payment failure',
                'description' => 'The checkout payment button sometimes fails for an account.', 'source_type' => 'CSV', 'detection_status' => 'PENDING']);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('failed_jobs', 0);

            Bus::dispatch(new DetectIssueCandidatesJob($issue->id));

            // Prove that dispatch enqueues a real database job,
            // rather than completing detection synchronously.
            $this->assertDatabaseCount('jobs', 1);
            $this->assertDatabaseHas('jobs', [
                'queue' => 'nlp',
                'attempts' => 0,
            ]);
            $this->assertSame(
                'PENDING',
                $issue->fresh()->detection_status
            );

            $start = microtime(true);
            $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'nlp', '--once' => true, '--sleep' => 0, '--force' => true, '--timeout' => 150])->assertExitCode(0);
            $elapsed = microtime(true) - $start;
            $this->assertSame('COMPLETED', $issue->fresh()->detection_status);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('failed_jobs', 0);
            $count = DB::table('duplicate_candidates')->where('issue_id', $issue->id)->count();
            $this->assertLessThanOrEqual(3, $count);
            $results[] = ['project_issues' => $size, 'completed_jobs' => 1, 'seconds' => round($elapsed, 4), 'observed_jobs_per_minute' => round(60 / $elapsed, 3),
                'php_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2), 'candidates_persisted' => $count];
            fwrite(STDOUT, "\nLIVE NLP QUEUE ".json_encode(end($results))."\n");
        }
        file_put_contents(dirname(__DIR__, 3).'/.qa/nlp-queue-benchmark.json', json_encode($results, JSON_PRETTY_PRINT));
    }

    public function test_cached_large_project_web_extension_and_content_changes(): void
    {
        $user = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $project = Project::factory()->create();
        Membership::factory()->create(['project_id' => $project->id, 'user_id' => $user->id, 'is_active' => true]);
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => $project->id, 'installation_id' => 112, 'repository_id' => 134,
            'repo_full_name' => 'example/cache-qa', 'verified_github_user_id' => 156, 'verified_at' => now()])->save();
        $rows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = ['project_id' => $project->id, 'title' => 'Checkout payment failure '.$i,
                'description' => 'The checkout payment button sometimes fails for account '.$i.'.',
                'status' => 'OPEN', 'detection_status' => 'COMPLETED', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('issues')->insert($chunk);
        }
        $input = ['title' => 'Checkout payment failure', 'description' => 'The checkout payment button sometimes fails for an account.'];
        $auth = app(ExtensionAuthService::class);
        $token = $auth->exchange($auth->createPairingCode($user))['access_token'];
        // Cold preparation is observed separately; do not call it a <=3s success.
        $start = microtime(true);
        $cold = $this->actingAs($user)->postJson(route('issues.recommendations', $project), $input)->assertOk()->json();
        $results = ['preparation_seconds' => microtime(true) - $start, 'pairs' => []];
        config(['services.nlp.timeout' => 10]); // Warm traffic must use the unchanged default.
        for ($i = 0; $i < 5; $i++) {
            $start = microtime(true);
            $web = $this->actingAs($user)->postJson(route('issues.recommendations', $project), $input)->assertOk()->json();
            $webSeconds = microtime(true) - $start;
            $start = microtime(true);
            $extension = $this->postJson('/api/extension/v1/recommendations', $input + ['repository' => 'example/cache-qa', 'project_id' => $project->id], ['Authorization' => 'Bearer '.$token])->assertOk()->json();
            $extensionSeconds = microtime(true) - $start;

            $this->assertSame(
                $this->expectedExtensionResponse($web),
                $extension
            );

            $this->assertSame($cold, $web);
            $this->assertCount(3, $web['recommendations']);
            $this->assertCount(3, $extension['recommendations']);

            foreach ($extension['recommendations'] as $candidate) {
                $this->assertGreaterThanOrEqual(
                    0.75,
                    $candidate['cosine_similarity']
                );
            }

            $results['pairs'][] = [
                'web_seconds' => $webSeconds,
                'extension_seconds' => $extensionSeconds,
            ];
        }
        $topId = $cold['recommendations'][0]['issue_id'];
        Issue::whereKey($topId)->update(['title' => 'Unrelated astronomy notes', 'description' => 'Observations of distant stars and galaxies.']);
        $changed = $this->postJson('/api/extension/v1/recommendations', $input + ['repository' => 'example/cache-qa', 'project_id' => $project->id], ['Authorization' => 'Bearer '.$token])->assertOk()->json();
        $this->assertNotContains($topId, array_column($changed['recommendations'], 'issue_id'));

        $changedWeb = $this->actingAs($user)->postJson(
            route('issues.recommendations', $project),
            $input
        )->assertOk()->json();

        $this->assertNotContains(
            $topId,
            array_column($changedWeb['recommendations'], 'issue_id')
        );

        $this->assertSame(
            $this->expectedExtensionResponse($changedWeb),
            $changed
        );

        $this->assertNotEmpty($changed['recommendations']);
        $deletedId = $changed['recommendations'][0]['issue_id'];
        Issue::whereKey($deletedId)->delete(); // Synthetic in-memory test row only.
        $deleted = $this->actingAs($user)->postJson(route('issues.recommendations', $project), $input)->assertOk()->json();
        $this->assertNotContains($deletedId, array_column($deleted['recommendations'], 'issue_id'));

        $deletedExtension = $this->postJson(
            '/api/extension/v1/recommendations',
            $input + [
                'repository' => 'example/cache-qa',
                'project_id' => $project->id,
            ],
            ['Authorization' => 'Bearer '.$token]
        )->assertOk()->json();

        $this->assertNotContains(
            $deletedId,
            array_column($deletedExtension['recommendations'], 'issue_id')
        );

        $this->assertSame(
            $this->expectedExtensionResponse($deleted),
            $deletedExtension
        );
        file_put_contents(dirname(__DIR__, 3).'/.qa/f2-web-extension.json', json_encode($results, JSON_PRETTY_PRINT));
    }
}
