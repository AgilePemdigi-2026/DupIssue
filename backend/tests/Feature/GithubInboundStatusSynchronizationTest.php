<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Jobs\SyncGithubIssueJob;
use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use App\Services\GithubAppIssueFetchService;
use App\Services\GithubWebhookSyncService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GithubInboundStatusSynchronizationTest extends TestCase
{
    private const INSTALLATION_ID = 810001;

    private const REPOSITORY_ID = 820001;

    private const REPOSITORY = 'example/status-sync-fixture';

    private const ISSUE_NUMBER = 42;

    private Project $project;

    private GithubAppConnection $connection;

    private string $signingSecret;

    public function createApplication()
    {
        $isolatedPath = getenv('DUPISSUE_TDD_ENV_PATH');

        if ($isolatedPath === false || $isolatedPath === '') {
            return parent::createApplication();
        }

        if (! is_dir($isolatedPath)) {
            throw new \RuntimeException(
                'Invalid isolated test environment.'
            );
        }

        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';

        $app->useEnvironmentPath($isolatedPath);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Fail closed before any migration or database write.
        $this->assertTrue(app()->environment('testing'));

        $this->assertSame(
            'sqlite',
            config('database.default')
        );

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database')
        );

        $this->assertEmpty(
            config('database.connections.sqlite.url')
        );

        $this->assertSame(
            ':memory:',
            DB::connection()->getDatabaseName()
        );

        // No actual GitHub or NLP HTTP request is permitted.
        Http::preventStrayRequests();

        // This migration only targets the verified in-memory database.
        $this->artisan('migrate', ['--force' => true])
            ->assertExitCode(0);

        Bus::fake([
            SyncGithubIssueJob::class,
            DetectIssueCandidatesJob::class,
        ]);

        $this->signingSecret = bin2hex(random_bytes(32));

        config([
            'services.github_app.webhook_secret' => $this->signingSecret,
        ]);

        $this->project = Project::factory()->create();

        $this->connection = new GithubAppConnection;
        $this->connection->project_id = $this->project->id;
        $this->connection->installation_id = self::INSTALLATION_ID;
        $this->connection->repository_id = self::REPOSITORY_ID;
        $this->connection->repo_full_name = self::REPOSITORY;
        $this->connection->verified_github_user_id = 830001;
        $this->connection->verified_at = now();
        $this->connection->save();
    }

    private function linkedIssue(
        string $status,
        array $overrides = []
    ): Issue {
        return Issue::forceCreate(array_replace([
            'project_id' => $this->project->id,
            'author_id' => null,
            'source_type' => 'GITHUB',
            'source_id' => null,
            'github_repo' => self::REPOSITORY,
            'github_repository_id' => self::REPOSITORY_ID,
            'github_number' => self::ISSUE_NUMBER,
            'source_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/'.self::ISSUE_NUMBER,
            'title' => 'Synthetic GitHub status synchronization issue',
            'description' => 'Synthetic issue content for isolated tests.',
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => null,
        ], $overrides));
    }

    private function sendWebhook(string $action): int
    {
        $delivery = (string) Str::uuid();

        $body = json_encode([
            'action' => $action,
            'installation' => [
                'id' => self::INSTALLATION_ID,
            ],
            'repository' => [
                'id' => self::REPOSITORY_ID,
                'full_name' => self::REPOSITORY,
            ],
            'issue' => [
                'id' => 840001,
                'number' => self::ISSUE_NUMBER,
                'state' => $action === 'closed'
                    ? 'closed'
                    : 'open',
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = 'sha256='.hash_hmac(
            'sha256',
            $body,
            $this->signingSecret
        );

        $this->call(
            'POST',
            '/api/github/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_GITHUB_EVENT' => 'issues',
                'HTTP_X_GITHUB_DELIVERY' => $delivery,
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $body
        )->assertStatus(202)
            ->assertJson(['code' => 'ACCEPTED']);

        $storedDelivery = DB::table('github_webhook_deliveries')
            ->where('delivery_uuid', $delivery)
            ->first();

        $this->assertNotNull(
            $storedDelivery,
            'A valid status event must be stored durably.'
        );

        $tasks = DB::table('github_webhook_tasks')
            ->where('delivery_id', $storedDelivery->id)
            ->where('project_id', $this->project->id)
            ->get();

        $this->assertCount(1, $tasks);

        Bus::assertDispatched(SyncGithubIssueJob::class);

        return (int) $tasks->first()->id;
    }

    private function reconcile(
        string $action,
        string $remoteState,
        bool $requireFetch = true
    ): void {
        $taskId = $this->sendWebhook($action);

        $fetch = Mockery::mock(GithubAppIssueFetchService::class);

        $expectation = $fetch->shouldReceive('fetchIssue')
            ->withArgs(fn ($connection, $number) =>
                $connection instanceof GithubAppConnection
                && (int) $connection->id === (int) $this->connection->id
                && (int) $connection->repository_id === self::REPOSITORY_ID
                && (int) $number === self::ISSUE_NUMBER
            );

        if ($requireFetch) {
            $expectation->once();
        } else {
            $expectation->zeroOrMoreTimes();
        }

        $expectation->andReturn([
            'id' => 840001,
            'number' => self::ISSUE_NUMBER,
            'title' => 'Synthetic GitHub status synchronization issue',
            'body' => 'Synthetic issue content for isolated tests.',
            'repository_url' => 'https://api.github.com/repos/'
                .self::REPOSITORY,
            'html_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/'.self::ISSUE_NUMBER,
            'state' => $remoteState,
            'state_reason' => $remoteState === 'closed'
                ? 'completed'
                : null,
            'updated_at' => now()->toIso8601String(),
        ]);

        $this->app->instance(
            GithubAppIssueFetchService::class,
            $fetch
        );

        $this->assertTrue(
            app(GithubWebhookSyncService::class)
                ->process($taskId)
        );

        $this->assertDatabaseHas('github_webhook_tasks', [
            'id' => $taskId,
            'status' => 'COMPLETED',
        ]);

        Bus::assertNotDispatched(
            DetectIssueCandidatesJob::class
        );

        Http::assertNothingSent();
    }

    private function assertStatusHistory(
        Issue $issue,
        string $before,
        string $after
    ): void {
        $logs = DB::table('activity_logs')
            ->where('project_id', $issue->project_id)
            ->where('issue_id', $issue->id)
            ->where('action', 'ISSUE_STATUS_CHANGED')
            ->get();

        if ($before === $after) {
            $this->assertCount(0, $logs);
            return;
        }

        $this->assertCount(1, $logs);

        $log = $logs->first();

        $this->assertNull($log->user_id);

        $old = json_decode(
            $log->old_values,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $new = json_decode(
            $log->new_values,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame($before, $old['status']);
        $this->assertSame($after, $new['status']);
    }

    public static function statusCases(): array
    {
        return [
            'T01_close_open' => [
                'OPEN', 'closed', 'closed', 'CLOSED',
            ],
            'T02_close_in_progress' => [
                'IN_PROGRESS', 'closed', 'closed', 'CLOSED',
            ],
            'T03_preserve_resolved' => [
                'RESOLVED', 'closed', 'closed', 'RESOLVED',
            ],
            'T04_reopen_closed' => [
                'CLOSED', 'reopened', 'open', 'OPEN',
            ],
            'T05_reopen_resolved' => [
                'RESOLVED', 'reopened', 'open', 'IN_PROGRESS',
            ],
            'T06_preserve_in_progress' => [
                'IN_PROGRESS', 'reopened', 'open', 'IN_PROGRESS',
            ],
            'T08_stale_close' => [
                'OPEN', 'closed', 'open', 'OPEN',
            ],
            'T09_stale_reopen' => [
                'OPEN', 'reopened', 'closed', 'CLOSED',
            ],
        ];
    }

    #[DataProvider('statusCases')]
    public function test_verified_remote_status_mapping(
        string $before,
        string $action,
        string $remoteState,
        string $expected
    ): void {
        $issue = $this->linkedIssue($before);

        $this->reconcile($action, $remoteState);

        $issue->refresh();

        $this->assertSame($expected, $issue->status);
        $this->assertSame('COMPLETED', $issue->detection_status);
        $this->assertNull($issue->duplicate_of);

        $this->assertSame(
            'Synthetic GitHub status synchronization issue',
            $issue->title
        );

        $this->assertSame(
            'Synthetic issue content for isolated tests.',
            $issue->description
        );

        $this->assertDatabaseCount('issues', 1);

        $this->assertStatusHistory(
            $issue,
            $before,
            $expected
        );
    }

    public function test_T07_reopen_preserves_duplicate_relationship(): void
    {
        $parent = $this->linkedIssue('RESOLVED', [
            'source_type' => 'FORM',
            'github_repo' => null,
            'github_repository_id' => null,
            'github_number' => null,
            'source_url' => null,
        ]);

        $child = $this->linkedIssue('CLOSED', [
            'duplicate_of' => $parent->id,
        ]);

        $this->reconcile('reopened', 'open');

        $this->assertDatabaseHas('issues', [
            'id' => $child->id,
            'status' => 'CLOSED',
            'duplicate_of' => $parent->id,
            'detection_status' => 'COMPLETED',
        ]);

        $this->assertSame(
            'RESOLVED',
            $parent->fresh()->status
        );

        $this->assertStatusHistory(
            $child,
            'CLOSED',
            'CLOSED'
        );

        $this->assertDatabaseCount('issues', 2);
    }

    public function test_T10_status_event_does_not_create_missing_issue(): void
    {
        $this->reconcile('closed', 'closed', false);

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_T11_historical_repository_issue_is_unchanged(): void
    {
        $issue = $this->linkedIssue('OPEN', [
            'github_repo' => 'example/historical-fixture',
            'github_repository_id' => 820002,
            'source_url' => 'https://github.com/example/'
                .'historical-fixture/issues/42',
        ]);

        $this->reconcile('closed', 'closed', false);

        $this->assertSame(
            'OPEN',
            $issue->fresh()->status
        );

        $this->assertDatabaseCount('issues', 1);

        $this->assertStatusHistory(
            $issue,
            'OPEN',
            'OPEN'
        );
    }

    public function test_T12_other_project_issue_is_unchanged(): void
    {
        $otherProject = Project::factory()->create();

        $issue = $this->linkedIssue('OPEN', [
            'project_id' => $otherProject->id,
        ]);

        $this->reconcile('closed', 'closed', false);

        $this->assertSame(
            'OPEN',
            $issue->fresh()->status
        );

        $this->assertDatabaseCount('issues', 1);

        $this->assertStatusHistory(
            $issue,
            'OPEN',
            'OPEN'
        );
    }

    public function test_T13_existing_opened_webhook_remains_accepted(): void
    {
        $taskId = $this->sendWebhook('opened');

        $this->assertDatabaseHas('github_webhook_tasks', [
            'id' => $taskId,
            'project_id' => $this->project->id,
            'connection_id' => $this->connection->id,
        ]);

        Http::assertNothingSent();
    }

    public function test_T14_pending_local_intent_has_priority_over_webhook(): void
    {
        $issue = $this->linkedIssue('RESOLVED');

        DB::table('github_issue_status_outbox')->insert([
            'project_id' => $this->project->id,
            'issue_id' => $issue->id,
            'connection_id' => $this->connection->id,
            'connection_generation' => $this->connection->status_sync_generation,
            'installation_id' => self::INSTALLATION_ID,
            'repository_id' => self::REPOSITORY_ID,
            'repo_full_name' => self::REPOSITORY,
            'issue_number' => self::ISSUE_NUMBER,
            'requested_by' => $this->project->owner_id,
            'desired_state' => 'closed',
            'revision' => 1,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->reconcile('reopened', 'open');

        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->assertDatabaseCount('github_issue_status_outbox', 1);
        $this->assertStatusHistory($issue, 'RESOLVED', 'RESOLVED');
    }

    public function test_T15_inbound_echo_does_not_create_outbound_task(): void
    {
        $issue = $this->linkedIssue('RESOLVED');

        $this->reconcile('closed', 'closed');

        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->assertDatabaseCount('github_issue_status_outbox', 0);
        $this->assertStatusHistory($issue, 'RESOLVED', 'RESOLVED');

        $this->assertDatabaseHas('github_issue_sync_states', [
            'issue_id' => $issue->id,
            'remote_state' => 'closed',
        ]);
    }

    public function test_T16_status_event_before_opened_import_is_reconciled(): void
    {
        $this->reconcile('closed', 'closed', false);

        $this->assertDatabaseCount('issues', 0);

        $taskId = $this->sendWebhook('opened');

        $fetch = \Mockery::mock(
            \App\Services\GithubAppIssueFetchService::class
        );

        $fetch->shouldReceive('fetchIssue')
            ->once()
            ->withArgs(fn ($connection, $number) =>
                (int) $connection->id === (int) $this->connection->id
                && (int) $connection->repository_id === self::REPOSITORY_ID
                && (int) $number === self::ISSUE_NUMBER
            )
            ->andReturn([
                'id' => 840001,
                'number' => self::ISSUE_NUMBER,
                'title' => 'Synthetic delayed GitHub opened event',
                'body' => 'Synthetic issue content for ordering verification.',
                'state' => 'closed',
                'state_reason' => 'completed',
                'repository_url' => 'https://api.github.com/repos/'
                    .self::REPOSITORY,
                'html_url' => 'https://github.com/'
                    .self::REPOSITORY.'/issues/'.self::ISSUE_NUMBER,
                'created_at' => now()->subMinute()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ]);

        $this->app->instance(
            \App\Services\GithubAppIssueFetchService::class,
            $fetch
        );

        $this->assertTrue(
            app(\App\Services\GithubWebhookSyncService::class)
                ->process($taskId)
        );

        $this->assertDatabaseCount('issues', 1);

        $issue = Issue::query()
            ->where('project_id', $this->project->id)
            ->where('github_repository_id', self::REPOSITORY_ID)
            ->where('github_number', self::ISSUE_NUMBER)
            ->firstOrFail();

        $this->assertSame('CLOSED', $issue->status);

        $this->assertStatusHistory($issue, 'OPEN', 'CLOSED');

        $this->assertDatabaseHas('github_issue_sync_states', [
            'issue_id' => $issue->id,
            'remote_state' => 'closed',
        ]);

        $this->assertDatabaseHas('github_webhook_tasks', [
            'id' => $taskId,
            'status' => 'COMPLETED',
        ]);

        $this->assertDatabaseCount('github_issue_status_outbox', 0);

        Http::assertNothingSent();
    }
}
