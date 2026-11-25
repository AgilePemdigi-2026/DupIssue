<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use App\Services\GithubAppInstallationTokenService;
use App\Services\GithubIssueImportService;
use App\Services\GithubIssuePreviewService;
use App\Services\GithubWebhookSyncService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GithubWebhookSyncTest extends TestCase
{
    use DatabaseMigrations;

    protected GithubAppConnection $connection;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->project = Project::factory()->create();
        $this->connection = $this->connect($this->project);
        $this->mock(GithubAppInstallationTokenService::class)->shouldReceive('createForRepository')->with(12, 34)->andReturn('test-installation-token');
        $this->fakeRemote();
    }

    protected function connect(Project $project): GithubAppConnection
    {
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => $project->id, 'installation_id' => 12, 'repository_id' => 34,
            'repo_full_name' => 'example/repo', 'verified_github_user_id' => 56, 'verified_at' => now()])->save();

        return $connection;
    }

    protected function remote(): array
    {
        return ['number' => 7, 'title' => 'Actual fetched title', 'body' => 'Actual fetched issue description',
            'state' => 'open', 'repository_url' => 'https://api.github.com/repos/example/repo'];
    }

    protected function fakeRemote(?array $issue = null, int $status = 200, ?array $repository = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/example/repo' => Http::response($repository ?? ['id' => 34, 'full_name' => 'example/repo']),
            'https://api.github.com/repos/example/repo/issues/7' => Http::response($issue ?? $this->remote(), $status),
        ]);
    }

    protected function delivery(string $uuid = '11111111-2222-3333-4444-555555555555'): int
    {
        $secret = 'test-only-long-webhook-secret-not-for-deployment';
        config(['services.github_app.webhook_secret' => $secret]);
        $raw = json_encode(['action' => 'opened', 'installation' => ['id' => 12], 'repository' => ['id' => 34],
            'issue' => ['number' => 7, 'title' => 'Never trust this title']]);
        $this->call('POST', '/api/github/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'issues', 'HTTP_X_GITHUB_DELIVERY' => $uuid,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret)], $raw)->assertStatus(202);

        return (int) DB::table('github_webhook_tasks')->orderByDesc('id')->value('id');
    }

    protected function process(int $id): bool
    {
        return app(GithubWebhookSyncService::class)->process($id);
    }

    public function test_fetches_actual_issue_and_imports_with_system_attribution_once(): void
    {
        $task = $this->delivery();
        $this->assertTrue($this->process($task));
        $this->assertTrue($this->process($task));
        $this->assertDatabaseHas('issues', ['title' => 'Actual fetched title', 'author_id' => null,
            'github_repository_id' => 34, 'github_repo' => 'example/repo', 'github_number' => 7, 'source_type' => 'GITHUB', 'vote_count' => 0]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'SYNC_GITHUB', 'user_id' => null]);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseHas('github_webhook_tasks', ['id' => $task, 'status' => 'COMPLETED', 'attempts' => 1]);
        $this->assertNotNull($this->connection->fresh()->last_synced_at);
        Bus::assertDispatched(DetectIssueCandidatesJob::class, 1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-installation-token'));
    }

    public function test_new_delivery_and_existing_manual_issue_preserve_vote_and_review_state(): void
    {
        $preview = app(GithubIssuePreviewService::class)->preview($this->project, 'example/repo', [$this->remote()]);
        app(GithubIssueImportService::class)->importFromApp($this->project, $this->project->owner,
            $this->connection->id, 12, 34, 'example/repo', [$this->remote()], hash('sha256', json_encode($preview['rows'], JSON_THROW_ON_ERROR)));
        $issue = Issue::first();
        $issue->vote_count = 6;
        $issue->status = 'CLOSED';
        $issue->save();
        $this->process($this->delivery());
        $this->process($this->delivery('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'));
        $this->assertDatabaseCount('issues', 1);
        $this->assertSame(6, $issue->fresh()->vote_count);
        $this->assertSame('CLOSED', $issue->fresh()->status);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'SYNC_GITHUB']);
        Bus::assertDispatched(DetectIssueCandidatesJob::class, 1);
    }

    public function test_one_repository_connected_to_multiple_projects_is_isolated(): void
    {
        $second = Project::factory()->create();
        $this->connect($second);
        $this->delivery();
        foreach (DB::table('github_webhook_tasks')->pluck('id') as $id) {
            $this->process($id);
        }
        $this->assertDatabaseCount('issues', 2);
        $this->assertSame(1, Issue::where('project_id', $second->id)->count());
        $this->assertSame(1, Issue::where('project_id', $this->project->id)->count());
    }

    public function test_pending_job_rejects_replaced_connection_before_network_call(): void
    {
        $id = $this->delivery();
        $this->connection->repository_id = 999;
        $this->connection->save();
        $this->process($id);
        Http::assertNothingSent();
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'REJECTED']);
    }

    public function test_connection_replacement_during_fetch_prevents_persistence(): void
    {
        $id = $this->delivery();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/issues/7')) {
                $this->connection->repository_id = 999;
                $this->connection->save();

                return Http::response($this->remote());
            }

            return Http::response(['id' => 34, 'full_name' => 'example/repo']);
        });
        $this->process($id);
        $this->assertDatabaseCount('issues', 0);
        $this->assertNull($this->connection->fresh()->last_synced_at);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public static function invalidRemoteCases(): array
    {
        return [['number', 8], ['pull_request', []], ['repository_url', 'https://evil.invalid'], ['title', 'bad'], ['body', null], ['state', 'forged']];
    }

    #[DataProvider('invalidRemoteCases')]
    public function test_invalid_remote_issue_is_rejected(string $field, mixed $value): void
    {
        $remote = $this->remote();
        $remote[$field] = $value;
        $this->fakeRemote($remote);
        $this->process($this->delivery());
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'REJECTED']);
    }

    public function test_mismatched_stable_repository_identity_is_rejected(): void
    {
        $this->fakeRemote(repository: ['id' => 999, 'full_name' => 'example/repo']);
        $this->process($this->delivery());
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'REJECTED']);
    }

    public function test_new_import_preserves_existing_open_status_policy_even_if_remote_later_closed(): void
    {
        $remote = $this->remote();
        $remote['state'] = 'closed';
        $this->fakeRemote($remote);
        $this->process($this->delivery());
        $this->assertDatabaseHas('issues', ['source_type' => 'GITHUB', 'status' => 'OPEN', 'author_id' => null]);
        $this->assertDatabaseCount('issues', 1);
    }

    public function test_rate_limit_retries_and_renewed_attempt_can_succeed(): void
    {
        $this->fakeRemote(status: 429);
        $id = $this->delivery();
        try {
            $this->process($id);
            $this->fail('Expected retry');
        } catch (\RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
        }
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'RETRY', 'attempts' => 1]);
        $this->assertFalse($this->process($id));
        $this->travel(61)->seconds();
        $this->fakeRemote();
        $this->process($id);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'COMPLETED', 'attempts' => 2]);
    }

    public function test_audit_failure_rolls_back_issue_sync_timestamp_and_nlp_dispatch(): void
    {
        $id = $this->delivery();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "activity_logs"')) {
                throw new \RuntimeException('test audit failure');
            }
        });
        try {
            $this->process($id);
            $this->fail('Expected retry');
        } catch (\RuntimeException $e) {
            $this->assertSame('GitHub synchronization temporarily unavailable.', $e->getMessage());
        }
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $this->assertNull($this->connection->fresh()->last_synced_at);
        Bus::assertNotDispatched(DetectIssueCandidatesJob::class);
    }

    public function test_active_lease_blocks_duplicate_processing_and_expired_lease_can_recover(): void
    {
        $id = $this->delivery();
        DB::table('github_webhook_tasks')->where('id', $id)->update(['status' => 'PROCESSING', 'lease_until' => now()->addSeconds(120), 'claim_token' => 'old-claim']);
        $this->assertFalse($this->process($id));
        Http::assertNothingSent();
        $this->travel(121)->seconds();
        $this->assertTrue($this->process($id));
        $this->assertDatabaseCount('issues', 1);
    }
}
