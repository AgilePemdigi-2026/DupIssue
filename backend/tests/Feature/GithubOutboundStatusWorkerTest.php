<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use DOMDocument;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GithubOutboundStatusWorkerTest extends TestCase
{
    private const OUTBOX = 'github_issue_status_outbox';

    private const INSTALLATION = 910001;

    private const REPOSITORY_ID = 920001;

    private const REPOSITORY = 'example/outbound-status-fixture';

    private Project $project;

    private GithubAppConnection $connection;

    public function createApplication()
    {
        $isolatedPath = getenv('DUPISSUE_TDD_ENV_PATH');

        if ($isolatedPath === false || $isolatedPath === '') {
            return parent::createApplication();
        }

        if (! is_dir($isolatedPath)) {
            throw new \RuntimeException(
                'Invalid isolated environment path.'
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

        // Reject unsafe database configuration before migrations.
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

        Http::preventStrayRequests();

        // Outbound jobs must not execute synchronously.
        config(['queue.default' => 'database']);

        $this->artisan('migrate', ['--force' => true])
            ->assertExitCode(0);

        $this->project = Project::factory()->create();

        $this->connection = new GithubAppConnection;

        $this->connection->project_id = $this->project->id;
        $this->connection->installation_id = self::INSTALLATION;
        $this->connection->repository_id = self::REPOSITORY_ID;
        $this->connection->repo_full_name = self::REPOSITORY;
        $this->connection->verified_github_user_id = 930001;
        $this->connection->verified_at = now();

        $this->connection->save();
    }

    private function issue(
        string $status,
        array $overrides = []
    ): Issue {
        $data = array_replace([
            'project_id' => $this->project->id,
            'author_id' => null,
            'source_type' => 'GITHUB',
            'source_id' => null,
            'github_repo' => self::REPOSITORY,
            'github_repository_id' => self::REPOSITORY_ID,
            'github_number' => 42,
            'source_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/42',
            'title' => 'Synthetic outbound status test issue',
            'description' => 'Isolated fixture for outbound synchronization.',
            'status' => $status,
            'detection_status' => 'COMPLETED',
            'vote_count' => 0,
            'duplicate_of' => null,
        ], $overrides);

        if ($data['source_type'] !== 'GITHUB') {
            $data['github_repo'] = null;
            $data['github_repository_id'] = null;
            $data['github_number'] = null;
            $data['source_url'] = null;
        }

        return Issue::forceCreate($data);
    }

    private function url(
        string $action,
        Issue $issue
    ): string {
        return route('issues.status.'.$action, [
            'project' => $this->project,
            'issue' => $issue,
        ]);
    }

    private function preview(
        Issue $issue,
        string $target
    ): TestResponse {
        $issue->refresh();

        return $this->post(
            $this->url('preview', $issue),
            [
                'new_status' => $target,
                'expected_status' => $issue->status,
                'expected_updated_at' =>
                    $issue->getRawOriginal('updated_at') ?? '',
            ]
        );
    }

    /**
     * Use the real confirmation form contract rather than inventing
     * a confirmation token or bypassing the status controller.
     */
    private function confirmationFields(
        Issue $issue,
        TestResponse $response
    ): array {
        $response->assertOk();

        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                (string) $response->getContent(),
                LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertTrue($loaded);

        $expectedAction = $this->url('confirm', $issue);

        foreach ($document->getElementsByTagName('form') as $form) {
            if ($form->getAttribute('action') !== $expectedAction) {
                continue;
            }

            $fields = [];

            foreach ($form->getElementsByTagName('input') as $input) {
                if (strtolower($input->getAttribute('type')) !== 'hidden') {
                    continue;
                }

                $name = $input->getAttribute('name');

                if ($name !== '') {
                    $fields[$name] = $input->getAttribute('value');
                }
            }

            $this->assertNotEmpty($fields);

            $businessFields = array_diff_key(
                $fields,
                ['_token' => true, '_method' => true]
            );

            $this->assertNotEmpty(
                $businessFields,
                'Expected the existing status confirmation field.'
            );

            return $fields;
        }

        $this->fail('Existing confirmation form not found.');
    }

    private function confirmChange(
        Issue $issue,
        string $target
    ): void {
        $this->actingAs($this->project->owner);

        $preview = $this->preview($issue, $target);

        $fields = $this->confirmationFields(
            $issue,
            $preview
        );

        $this->post(
            $this->url('confirm', $issue),
            $fields
        )->assertRedirect(
            route('issues.show', [
                'project' => $this->project,
                'issue' => $issue,
            ])
        );

        $this->assertSame(
            $target,
            $issue->fresh()->status
        );

        $this->assertDatabaseHas('activity_logs', [
            'project_id' => $this->project->id,
            'issue_id' => $issue->id,
            'user_id' => $this->project->owner_id,
            'action' => 'ISSUE_STATUS_CHANGED',
        ]);

        // A normal status request must never contact GitHub directly.
        Http::assertNothingSent();
    }


    private function fakeJwt(): void
    {
        $jwt = \Mockery::mock(
            \App\Services\GithubAppJwtService::class
        );

        $jwt->shouldReceive('generate')
            ->zeroOrMoreTimes()
            ->andReturn('synthetic-jwt-fixture');

        $this->app->instance(
            \App\Services\GithubAppJwtService::class,
            $jwt
        );
    }

    private function installationService(): object
    {
        return app(
            \App\Services\GithubAppInstallationTokenService::class
        );
    }

    private function requireWriteAuthentication(): object
    {
        $service = $this->installationService();

        $this->assertTrue(
            method_exists(
                $service,
                'createForRepositoryWithWriteAccess'
            ),
            'Missing outbound contract: scoped write authentication.'
        );

        return $service;
    }

    private function requireApiClient(): object
    {
        $client = app(\App\Services\GithubApiClient::class);

        $this->assertTrue(
            method_exists($client, 'updateVerifiedIssueState'),
            'Missing outbound contract: verified API state update.'
        );

        return $client;
    }

    private function requireWorker(): object
    {
        $class = \App\Services\GithubIssueStatusOutboundService::class;

        $this->assertTrue(
            class_exists($class),
            'Missing outbound contract: durable outbound worker.'
        );

        $service = app($class);

        $this->assertTrue(
            method_exists($service, 'process'),
            'Missing outbound contract: outbound task processing.'
        );

        return $service;
    }

    private function tokenResponse(string $permission): array
    {
        return [
            'token' => 'synthetic-installation-token',
            'expires_at' => now()->addHour()->toIso8601String(),
            'permissions' => [
                'issues' => $permission,
                'metadata' => 'read',
            ],
            'repository_selection' => 'selected',
        ];
    }

    private function remoteIssue(
        string $state,
        array $overrides = []
    ): array {
        return array_replace([
            'id' => 940001,
            'number' => 42,
            'state' => $state,
            'title' => 'Synthetic remote issue',
            'body' => 'Synthetic remote description.',
            'repository_url' => 'https://api.github.com/repos/'
                .self::REPOSITORY,
            'html_url' => 'https://github.com/'
                .self::REPOSITORY.'/issues/42',
            'updated_at' => now()->toIso8601String(),
        ], $overrides);
    }

    private function fakeAuthentication(
        string $requested,
        string $returned
    ): void {
        $this->fakeJwt();

        Http::fake(function (
            \Illuminate\Http\Client\Request $request
        ) use ($requested, $returned) {
            $this->assertSame('POST', $request->method());

            $this->assertSame(
                'https://api.github.com/app/installations/'
                    .self::INSTALLATION.'/access_tokens',
                $request->url()
            );

            $this->assertSame(
                [self::REPOSITORY_ID],
                $request->data()['repository_ids']
            );

            $this->assertSame(
                ['issues' => $requested],
                $request->data()['permissions']
            );

            return Http::response(
                $this->tokenResponse($returned),
                201
            );
        });
    }

    private function fakeGithub(
        string $initialState,
        string $desiredState,
        array $options = []
    ): void {
        $this->fakeJwt();

        $remoteState = $initialState;

        Http::fake(function (
            \Illuminate\Http\Client\Request $request
        ) use (&$remoteState, $desiredState, $options) {
            $url = $request->url();
            $method = $request->method();

            $repositoryUrl = 'https://api.github.com/repos/'
                .self::REPOSITORY;

            $issueUrl = $repositoryUrl.'/issues/42';

            $authenticationUrl =
                'https://api.github.com/app/installations/'
                .self::INSTALLATION.'/access_tokens';

            if ($method === 'POST' && $url === $authenticationUrl) {
                $this->assertSame(
                    [self::REPOSITORY_ID],
                    $request->data()['repository_ids']
                );

                $this->assertSame(
                    ['issues' => 'write'],
                    $request->data()['permissions']
                );

                return Http::response(
                    $this->tokenResponse('write'),
                    201
                );
            }

            $this->assertTrue(
                $request->hasHeader(
                    'Authorization',
                    'Bearer synthetic-installation-token'
                )
            );

            if ($method === 'GET' && $url === $repositoryUrl) {
                return Http::response([
                    'id' => $options['repository_id']
                        ?? self::REPOSITORY_ID,
                    'full_name' => self::REPOSITORY,
                ]);
            }

            if ($method === 'GET' && $url === $issueUrl) {
                return Http::response(
                    $this->remoteIssue(
                        $remoteState,
                        $options['issue_overrides'] ?? []
                    )
                );
            }

            if ($method === 'PATCH' && $url === $issueUrl) {
                $this->assertFalse(
                    $options['forbid_patch'] ?? false,
                    'PATCH must not occur for this scenario.'
                );

                $this->assertSame(
                    ['state' => $desiredState],
                    $request->data()
                );

                $failure = $options['patch_error'] ?? null;

                if ($failure !== null) {
                    return Http::response(
                        ['message' => 'Synthetic API failure'],
                        $failure,
                        $options['patch_headers'] ?? []
                    );
                }

                $remoteState = $desiredState;

                return Http::response(
                    $this->remoteIssue(
                        $remoteState,
                        $options['ack_overrides'] ?? []
                    )
                );
            }

            $this->fail('Unexpected outbound HTTP request.');
        });
    }

    private function updateRemote(string $state): array
    {
        return $this->requireApiClient()
            ->updateVerifiedIssueState(
                self::REPOSITORY,
                self::REPOSITORY_ID,
                42,
                $state,
                'synthetic-installation-token'
            );
    }

    private function patchCount(): int
    {
        return Http::recorded(
            fn ($request, $response) =>
                $request->method() === 'PATCH'
        )->count();
    }

    private function pendingClose(): array
    {
        $issue = $this->issue('IN_PROGRESS');

        $this->confirmChange($issue, 'RESOLVED');

        $task = DB::table(self::OUTBOX)
            ->where('issue_id', $issue->id)
            ->first();

        $this->assertNotNull($task);
        $this->assertSame('PENDING', $task->status);

        return [$issue, $task];
    }

    private function taskStatus(int $taskId): string
    {
        return (string) DB::table(self::OUTBOX)
            ->where('id', $taskId)
            ->value('status');
    }

    private function assertNoExternalRequest(): void
    {
        Http::fake(function () {
            $this->fail(
                'An ineligible outbound task made an HTTP request.'
            );
        });
    }

    public function test_W01_write_authentication_is_repository_scoped(): void
    {
        $this->fakeAuthentication('write', 'write');

        $service = $this->requireWriteAuthentication();

        $result = $service->createForRepositoryWithWriteAccess(
            self::INSTALLATION,
            self::REPOSITORY_ID
        );

        $this->assertSame(
            'synthetic-installation-token',
            $result
        );

        Http::assertSentCount(1);
    }

    public function test_W02_read_only_response_cannot_authorize_write(): void
    {
        $this->fakeAuthentication('write', 'read');

        $service = $this->requireWriteAuthentication();

        $this->expectException(\RuntimeException::class);

        $service->createForRepositoryWithWriteAccess(
            self::INSTALLATION,
            self::REPOSITORY_ID
        );
    }

    public function test_W03_existing_read_authentication_remains_read_only(): void
    {
        $this->fakeAuthentication('read', 'read');

        $result = $this->installationService()
            ->createForRepository(
                self::INSTALLATION,
                self::REPOSITORY_ID
            );

        $this->assertSame(
            'synthetic-installation-token',
            $result
        );

        Http::assertSentCount(1);
    }

    public static function remoteStateCases(): array
    {
        return [
            'W04_close' => ['open', 'closed'],
            'W05_reopen' => ['closed', 'open'],
        ];
    }

    #[DataProvider('remoteStateCases')]
    public function test_verified_state_update(
        string $initial,
        string $desired
    ): void {
        $this->fakeGithub($initial, $desired);

        $result = $this->updateRemote($desired);

        $this->assertSame($desired, $result['state']);
        $this->assertSame(42, $result['number']);
        $this->assertSame(1, $this->patchCount());

        $methods = Http::recorded()
            ->map(fn ($pair) => $pair[0]->method())
            ->all();

        $this->assertSame(
            ['GET', 'GET', 'PATCH'],
            $methods
        );
    }

    public function test_W06_matching_remote_state_skips_patch(): void
    {
        $this->fakeGithub('closed', 'closed', [
            'forbid_patch' => true,
        ]);

        $result = $this->updateRemote('closed');

        $this->assertSame('closed', $result['state']);

        $this->assertSame(0, $this->patchCount());

        Http::assertSentCount(2);
    }

    public function test_W07_repository_identity_mismatch_is_rejected(): void
    {
        $this->fakeGithub('open', 'closed', [
            'repository_id' => 920099,
            'forbid_patch' => true,
        ]);

        // Check the contract before expecting a domain exception.
        $this->requireApiClient();

        try {
            $this->updateRemote('closed');

            $this->fail(
                'Repository identity mismatch must be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(0, $this->patchCount());
        }
    }

    public function test_W08_pull_request_cannot_be_updated_as_issue(): void
    {
        $this->fakeGithub('open', 'closed', [
            'issue_overrides' => [
                'pull_request' => [
                    'url' => 'https://api.github.com/example',
                ],
            ],
            'forbid_patch' => true,
        ]);

        $this->requireApiClient();

        try {
            $this->updateRemote('closed');

            $this->fail('Pull request must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(0, $this->patchCount());
        }
    }

    public function test_W09_invalid_patch_acknowledgement_is_rejected(): void
    {
        $this->fakeGithub('open', 'closed', [
            'ack_overrides' => [
                'number' => 99,
            ],
        ]);

        $this->requireApiClient();

        try {
            $this->updateRemote('closed');

            $this->fail(
                'Invalid remote acknowledgement must be rejected.'
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame(1, $this->patchCount());
        }
    }

    public function test_W10_worker_completes_once_without_feedback_loop(): void
    {
        [$issue, $task] = $this->pendingClose();

        $this->fakeGithub('open', 'closed');

        $worker = $this->requireWorker();

        $this->assertTrue(
            $worker->process((int) $task->id)
        );

        $this->assertSame(
            'COMPLETED',
            $this->taskStatus((int) $task->id)
        );

        $this->assertSame(1, $this->patchCount());

        $requests = Http::recorded()->count();

        // Replay the already-completed durable task.
        $this->assertTrue(
            $worker->process((int) $task->id)
        );

        Http::assertSentCount($requests);

        $this->assertSame('RESOLVED', $issue->fresh()->status);

        $this->assertDatabaseCount(self::OUTBOX, 1);

        $this->assertSame(
            1,
            DB::table('activity_logs')
                ->where('issue_id', $issue->id)
                ->where('action', 'ISSUE_STATUS_CHANGED')
                ->count()
        );
    }

    public function test_W11_superseded_intent_never_writes_remote_state(): void
    {
        [$issue, $oldTask] = $this->pendingClose();

        $this->confirmChange($issue, 'IN_PROGRESS');

        $this->assertNoExternalRequest();

        $worker = $this->requireWorker();

        $worker->process((int) $oldTask->id);

        $this->assertSame(
            'SUPERSEDED',
            $this->taskStatus((int) $oldTask->id)
        );

        $latest = DB::table(self::OUTBOX)
            ->where('issue_id', $issue->id)
            ->orderByDesc('revision')
            ->first();

        $this->assertSame('open', $latest->desired_state);

        $this->assertContains(
            $latest->status,
            ['PENDING', 'QUEUED']
        );

        Http::assertNothingSent();
    }

    public function test_W12_repository_replacement_invalidates_task(): void
    {
        [$issue, $task] = $this->pendingClose();

        $this->connection->repository_id = 920002;
        $this->connection->repo_full_name =
            'example/replacement-fixture';

        $this->connection->save();

        $this->assertNoExternalRequest();

        $worker = $this->requireWorker();

        $worker->process((int) $task->id);

        $this->assertSame(
            'REJECTED',
            $this->taskStatus((int) $task->id)
        );

        $this->assertSame('RESOLVED', $issue->fresh()->status);

        Http::assertNothingSent();
    }

    public function test_W13_shared_repository_write_is_rejected(): void
    {
        [$issue, $task] = $this->pendingClose();

        $other = Project::factory()->create();

        $otherConnection = new GithubAppConnection;

        $otherConnection->project_id = $other->id;
        $otherConnection->installation_id = 910002;
        $otherConnection->repository_id = self::REPOSITORY_ID;
        $otherConnection->repo_full_name = self::REPOSITORY;
        $otherConnection->verified_github_user_id = 930002;
        $otherConnection->verified_at = now();

        $otherConnection->save();

        $this->assertNoExternalRequest();

        $worker = $this->requireWorker();

        $worker->process((int) $task->id);

        $this->assertSame(
            'REJECTED',
            $this->taskStatus((int) $task->id)
        );

        Http::assertNothingSent();
    }

    public function test_W14_transient_api_failure_retains_retryable_task(): void
    {
        [$issue, $task] = $this->pendingClose();

        $this->fakeGithub('open', 'closed', [
            'patch_error' => 503,
        ]);

        $worker = $this->requireWorker();

        $this->assertFalse(
            $worker->process((int) $task->id)
        );

        $this->assertSame(
            'RETRY',
            $this->taskStatus((int) $task->id)
        );

        $this->assertSame('RESOLVED', $issue->fresh()->status);

        $this->assertDatabaseCount(self::OUTBOX, 1);
        $this->assertSame(1, $this->patchCount());
    }

    public function test_W15_permission_failure_is_rejected_safely(): void
    {
        [$issue, $task] = $this->pendingClose();

        $this->fakeGithub('open', 'closed', [
            'patch_error' => 403,
        ]);

        $worker = $this->requireWorker();

        $worker->process((int) $task->id);

        $this->assertSame(
            'REJECTED',
            $this->taskStatus((int) $task->id)
        );

        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->assertSame(1, $this->patchCount());
        $this->assertDatabaseCount(self::OUTBOX, 1);
    }

    public function test_W16_expired_claim_recovers_without_repeating_patch(): void
    {
        [$issue, $task] = $this->pendingClose();

        DB::table(self::OUTBOX)->where('id', $task->id)->update([
            'status' => 'PROCESSING',
            'attempts' => 1,
            'claim_token' => 'synthetic-expired-claim',
            'lease_until' => now()->subMinute(),
        ]);

        $this->fakeGithub('closed', 'closed', [
            'forbid_patch' => true,
        ]);

        $this->assertTrue(
            $this->requireWorker()->process((int) $task->id)
        );

        $this->assertSame('COMPLETED', $this->taskStatus((int) $task->id));
        $this->assertSame(0, $this->patchCount());
        $this->assertSame('RESOLVED', $issue->fresh()->status);
    }

    public function test_W17_attempt_limit_prevents_unbounded_retry(): void
    {
        [, $task] = $this->pendingClose();

        DB::table(self::OUTBOX)->where('id', $task->id)->update([
            'attempts' => 5,
            'status' => 'RETRY',
            'available_at' => now()->subMinute(),
        ]);

        $this->assertNoExternalRequest();

        $this->assertTrue(
            $this->requireWorker()->process((int) $task->id)
        );

        $this->assertSame('FAILED', $this->taskStatus((int) $task->id));
        Http::assertNothingSent();
    }

    public function test_W18_retry_after_is_respected(): void
    {
        [, $task] = $this->pendingClose();

        $this->fakeGithub('open', 'closed', [
            'patch_error' => 403,
            'patch_headers' => ['Retry-After' => '300'],
        ]);

        $this->assertFalse(
            $this->requireWorker()->process((int) $task->id)
        );

        $row = DB::table(self::OUTBOX)->find($task->id);

        $this->assertSame('RETRY', $row->status);
        $this->assertSame('GITHUB_429', $row->last_error_code);

        $this->assertTrue(
            \Illuminate\Support\Carbon::parse($row->available_at)
                ->gte(now()->addSeconds(299))
        );
    }

    public function test_W19_repository_aba_invalidates_old_intent(): void
    {
        [, $task] = $this->pendingClose();

        $this->connection->repository_id = 920002;
        $this->connection->repo_full_name = 'example/replacement-fixture';
        $this->connection->save();

        $this->connection->repository_id = self::REPOSITORY_ID;
        $this->connection->repo_full_name = self::REPOSITORY;
        $this->connection->save();

        $this->assertGreaterThan(
            (int) $task->connection_generation,
            (int) $this->connection->fresh()->status_sync_generation
        );

        $this->assertNoExternalRequest();

        $this->requireWorker()->process((int) $task->id);

        $this->assertSame('REJECTED', $this->taskStatus((int) $task->id));
        Http::assertNothingSent();
    }

    public function test_W20_active_mutex_prevents_parallel_processing(): void
    {
        [, $task] = $this->pendingClose();

        $this->assertNoExternalRequest();

        $mutex = \App\Services\GithubStatusSyncMutex::acquire();

        $this->assertNotNull($mutex);

        try {
            $this->assertFalse(
                $this->requireWorker()->process((int) $task->id)
            );
        } finally {
            $mutex->release();
        }

        $this->assertSame('PENDING', $this->taskStatus((int) $task->id));
        Http::assertNothingSent();
    }

    public function test_W21_ui_only_reports_synced_after_remote_confirmation(): void
    {
        [$issue, $task] = $this->pendingClose();

        $url = route('issues.show', [
            'project' => $this->project,
            'issue' => $issue,
        ]);

        $this->actingAs($this->project->owner)->get($url)
            ->assertOk()->assertSee('Pending Synchronization');

        $this->fakeGithub('open', 'closed');

        $this->assertTrue(
            $this->requireWorker()->process((int) $task->id)
        );

        $this->get($url)->assertOk()
            ->assertSee('github-sync-indicator')
            ->assertSee('Synced')
            ->assertDontSee('Pending Synchronization');

        $this->assertDatabaseHas('github_issue_sync_states', [
            'issue_id' => $issue->id,
            'remote_state' => 'closed',
        ]);
    }

    public function test_W22_queue_dispatch_and_scheduler_recovery(): void
    {
        \Illuminate\Support\Facades\Bus::fake([
            \App\Jobs\SyncGithubIssueStatusJob::class,
        ]);

        [, $task] = $this->pendingClose();

        \Illuminate\Support\Facades\Bus::assertDispatched(
            \App\Jobs\SyncGithubIssueStatusJob::class
        );

        DB::table(self::OUTBOX)->where('id', $task->id)->update([
            'queued_at' => now()->subMinutes(3),
        ]);

        $this->artisan('github:status-recover', ['--limit' => 10])
            ->assertExitCode(0);

        \Illuminate\Support\Facades\Bus::assertDispatchedTimes(
            \App\Jobs\SyncGithubIssueStatusJob::class, 2
        );

        Http::assertNothingSent();
    }

    public function test_W23_form_issue_has_no_sync_indicator(): void
    {
        $issue = $this->issue('OPEN', ['source_type' => 'FORM']);

        $this->actingAs($this->project->owner)->get(route('issues.show', [
            'project' => $this->project,
            'issue' => $issue,
        ]))->assertOk()->assertDontSee('github-sync-indicator');
    }
}
