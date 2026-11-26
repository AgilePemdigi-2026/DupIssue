<?php

namespace Tests\Feature;

use App\Jobs\SyncGithubIssueJob;
use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use App\Services\GithubAppInstallationTokenService;
use App\Services\GithubWebhookDispatchService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubWebhookQueueTest extends TestCase
{
    use DatabaseMigrations;

    private int $remoteStatus = 200;

    private Project $project;

    private Issue $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->project = Project::factory()->create();
        $this->candidate = Issue::forceCreate(['project_id' => $this->project->id, 'title' => 'Existing comparison issue',
            'description' => 'Existing comparison issue description', 'detection_status' => 'COMPLETED']);
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => $this->project->id, 'installation_id' => 12, 'repository_id' => 34,
            'repo_full_name' => 'example/repo', 'verified_github_user_id' => 56, 'verified_at' => now()])->save();
        $this->mock(GithubAppInstallationTokenService::class)->shouldReceive('createForRepository')->with(12, 34)->andReturn('test-installation-token');
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::fake(function ($request) {
            if ($request->url() === 'https://api.github.com/repos/example/repo') {
                return Http::response(['id' => 34, 'full_name' => 'example/repo']);
            }
            if ($request->url() === 'https://api.github.com/repos/example/repo/issues/7') {
                return Http::response([
                    'number' => 7, 'title' => 'Actual remote issue', 'body' => 'Actual remote issue description', 'state' => 'open',
                    'repository_url' => 'https://api.github.com/repos/example/repo'], $this->remoteStatus);
            }
            if ($request->url() === 'http://nlp.test/candidates') {
                return Http::response(['candidates' => [['issue_id' => $this->candidate->id, 'similarity' => .9]]]);
            }
            throw new \RuntimeException('Unexpected test HTTP destination');
        });
    }

    private function deliver(): int
    {
        $secret = 'test-webhook-secret-for-isolated-queue-tests';
        config(['services.github_app.webhook_secret' => $secret]);
        $raw = json_encode(['action' => 'opened', 'installation' => ['id' => 12], 'repository' => ['id' => 34], 'issue' => ['number' => 7]]);
        $this->call('POST', '/api/github/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GITHUB_EVENT' => 'issues',
            'HTTP_X_GITHUB_DELIVERY' => '11111111-2222-3333-4444-555555555555', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret)], $raw)->assertStatus(202);

        return (int) DB::table('github_webhook_tasks')->value('id');
    }

    private function work(string $queue = 'default'): void
    {
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => $queue, '--once' => true, '--sleep' => 0, '--force' => true])->assertExitCode(0);
    }

    public function test_signed_delivery_database_worker_persistence_and_nlp_worker_complete(): void
    {
        $this->deliver();
        Http::assertNothingSent();
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        $this->work();
        $issue = Issue::where('source_type', 'GITHUB')->sole();
        $this->assertSame('PENDING', $issue->detection_status);
        $this->assertDatabaseHas('jobs', ['queue' => 'nlp']);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'COMPLETED']);
        $this->work('nlp');
        $this->assertSame('COMPLETED', $issue->fresh()->detection_status);
        $this->assertDatabaseHas('duplicate_candidates', ['issue_id' => $issue->id, 'candidate_issue_id' => $this->candidate->id]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->deliver();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_real_worker_backoff_retry_and_success(): void
    {
        $this->remoteStatus = 503;
        $this->deliver();
        $this->work();
        $row = DB::table('jobs')->sole();
        $this->assertSame(1, $row->attempts);
        $this->assertGreaterThan(now()->timestamp, $row->available_at);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'RETRY', 'attempts' => 1]);
        $this->remoteStatus = 200;
        $this->travel(61)->seconds();
        $this->work();
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'COMPLETED', 'attempts' => 2]);
        $this->work('nlp');
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_real_worker_exhaustion_records_safe_failed_job_and_can_reprocess(): void
    {
        $this->remoteStatus = 503;
        $id = $this->deliver();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->work();
            $this->travel(601)->seconds();
        }
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'FAILED', 'attempts' => 5]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
        $failure = DB::table('failed_jobs')->sole();
        $this->assertStringNotContainsString('test-installation-token', $failure->exception.$failure->payload);
        $this->remoteStatus = 200;
        $this->artisan('github:webhooks-recover', ['--task' => $id])->assertExitCode(0);
        $this->work();
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'COMPLETED']);
    }

    public function test_permanent_failure_and_replacement_are_acknowledged_without_import(): void
    {
        $this->remoteStatus = 404;
        $this->deliver();
        $this->work();
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'REJECTED']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, Issue::where('source_type', 'GITHUB')->count());
    }

    public function test_missing_dispatch_and_crashed_queue_reservation_are_recoverable(): void
    {
        config(['queue.connections.database.driver' => 'sync']);
        $id = $this->deliver();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('github_webhook_tasks', ['error_code' => 'DISPATCH_FAILED']);
        config(['queue.connections.database.driver' => 'database']);
        $this->artisan('github:webhooks-recover')->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('github:webhooks-recover')->assertExitCode(0);
        $this->assertDatabaseCount('jobs', 1);
        DB::table('jobs')->update(['reserved_at' => now()->subSeconds(121)->timestamp, 'attempts' => 1]);
        DB::table('github_webhook_tasks')->where('id', $id)->update(['status' => 'PROCESSING', 'lease_until' => now()->subSecond()]);
        $this->work();
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'COMPLETED']);
    }

    public function test_completed_result_survives_exhausted_delivery_ack_loss(): void
    {
        $id = $this->deliver();
        $this->work();
        $this->work('nlp');
        $job = new SyncGithubIssueJob($id);
        app('queue')->connection('database')->push($job);
        DB::table('jobs')->where('queue', 'default')->update(['attempts' => 5, 'reserved_at' => now()->subSeconds(100)->timestamp]);
        $this->work();
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'COMPLETED']);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertSame(1, Issue::where('source_type', 'GITHUB')->count());
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_queue_outbox_rejects_different_database_and_rolls_back_dispatch_transaction(): void
    {
        config(['queue.connections.database.connection' => 'different']);
        $id = $this->deliver();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'RECEIVED']);
        config(['queue.connections.database.connection' => 'sqlite']);
        DB::beginTransaction();
        app(GithubWebhookDispatchService::class)->dispatch($id);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'RECEIVED']);
    }

    public function test_stale_pending_nlp_after_commit_gap_can_recover(): void
    {
        $this->candidate->detection_status = 'PENDING';
        $this->candidate->save();
        $this->travel(301)->seconds();
        $this->artisan('nlp:recover-pending')->assertExitCode(0);
        $this->assertDatabaseHas('jobs', ['queue' => 'nlp']);
        $this->artisan('nlp:recover-pending')->assertExitCode(0);
        $this->assertDatabaseCount('jobs',1);
        $this->work('nlp');
        $this->assertSame('COMPLETED',$this->candidate->fresh()->detection_status);
    }
}
