<?php

namespace Tests\Feature;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('project-integration')]
class SubmitIssueTest extends TestCase
{
    // Real commits are needed; RefreshDatabase wraps tests in an outer transaction.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        // Stop BEFORE application setup/migrations when PR #18 is not integrated.
        foreach ([Project::class, Membership::class, ProjectService::class] as $dependency) {
            if (! class_exists($dependency)) {
                $this->markTestSkipped('PR #18 belum terintegrasi: '.$dependency);
            }
        }

        foreach ([
            '2026_10_21_200001_create_projects_table.php',
            '2026_10_21_200002_create_memberships_table.php',
        ] as $migration) {
            if (! is_file(dirname(__DIR__, 2).'/database/migrations/'.$migration)) {
                $this->markTestSkipped('Migration PR #18 belum tersedia: '.$migration);
            }
        }

        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        Http::preventStrayRequests();
        // Record HTTP calls without a catch-all response that would shadow
        // the explicit 503/success sequences used by worker tests below.
        Http::fake([]);
    }

    protected function beforeRefreshingDatabase(): void
    {
        // Never inherit DB_URL, read/write hosts, or development queue connections.
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'session.driver' => 'array',
            'cache.default' => 'array',
            // Deliberately sync: the controller must explicitly choose database.
            'queue.default' => 'sync',
            'queue.connections.database' => [
                'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
            ],
            'queue.failed' => [
                'driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs',
            ],
        ]);
        DB::purge('sqlite');
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getConfig('driver'));
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame('sqlite', $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('', $connection->selectOne('PRAGMA database_list')->file);
    }

    private function requireDetectionJob(): void
    {
        if (! class_exists(DetectIssueCandidatesJob::class)) {
            $this->markTestSkipped('Job NLP Valentino belum terintegrasi; tidak membuat Job pengganti.');
        }
        $this->assertTrue(is_subclass_of(DetectIssueCandidatesJob::class, ShouldQueue::class));
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function makeProject(): Project
    {
        $project = new Project([
            'name' => 'Project '.uniqid(),
            'description' => 'Project untuk pengujian Submit Issue',
        ]);
        $project->owner_id = $this->makeUser('MAINTAINER')->id;
        $project->save();

        return $project;
    }

    private function membership(Project $project, User $user, bool $active = true): void
    {
        Membership::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'is_active' => $active, 'joined_at' => now(), 'removed_at' => $active ? null : now(),
        ]);
    }

    private function payload(): array
    {
        return [
            'title' => 'Error saat proses checkout',
            'description' => 'Tombol Bayar tidak merespons setelah diklik.',
        ];
    }

    private function activeReporter(Project $project): User
    {
        $reporter = $this->makeUser('REPORTER');
        $this->membership($project, $reporter);

        return $reporter;
    }

    #[Group('nlp-integration')]
    public function test_active_reporter_can_submit_issue_with_server_controlled_fields(): void
    {
        $this->requireDetectionJob();
        Queue::fake();
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        $this->actingAs($reporter)->get(route('issues.create', $project))->assertOk();

        $response = $this->post(route('issues.store', $project), $this->payload() + [
            'author_id' => 99999, 'project_id' => 99999, 'status' => 'DUPLICATE',
            'source_type' => 'GITHUB', 'vote_count' => 999,
            'detection_status' => 'COMPLETED', 'duplicate_of' => 99999,
        ]);
        $issue = Issue::query()->sole();
        $response->assertRedirect(route('issues.show', ['project' => $project, 'issue' => $issue]));
        $response->assertSessionHas('success', 'Issue berhasil dibuat');
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id, 'project_id' => $project->id, 'author_id' => $reporter->id,
            'source_type' => 'FORM', 'status' => 'OPEN', 'detection_status' => 'PENDING',
            'vote_count' => 0, 'duplicate_of' => null,
        ]);
        $this->get($response->headers->get('Location'))->assertOk()
            ->assertSee('Issue berhasil dibuat')->assertSee('OPEN')->assertSee('PENDING');
        Queue::assertPushedOn('nlp', DetectIssueCandidatesJob::class,
            fn ($job) => $job->connection === 'database');
        Queue::assertPushed(DetectIssueCandidatesJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_non_member_reporter_is_rejected_on_get_and_post(): void
    {
        $this->assertCannotSubmit($this->makeProject(), $this->makeUser('REPORTER'));
    }

    public function test_inactive_membership_is_rejected_on_get_and_post(): void
    {
        $project = $this->makeProject();
        $reporter = $this->makeUser('REPORTER');
        $this->membership($project, $reporter, false);
        $this->assertCannotSubmit($project, $reporter);
    }

    public function test_maintainer_cannot_submit_even_with_active_membership(): void
    {
        $project = $this->makeProject();
        $this->membership($project, $project->owner);
        $this->assertCannotSubmit($project, $project->owner);
    }

    private function assertCannotSubmit(Project $project, User $user): void
    {
        Queue::fake();
        $this->actingAs($user)->get(route('issues.create', $project))->assertForbidden();
        $this->post(route('issues.store', $project), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('issues', 0);
        Queue::assertNothingPushed();
    }

    public function test_guest_is_rejected_without_requiring_a_fake_login_route(): void
    {
        $project = $this->makeProject();
        $this->get(route('issues.create', $project))->assertRedirect(route('login'));
        $this->post(route('issues.store', $project), $this->payload())->assertRedirect(route('login'));
        $this->getJson(route('issues.create', $project))->assertUnauthorized();
        $this->postJson(route('issues.store', $project), $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('issues', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_title_and_description_are_validated(array $payload, array $errors): void
    {
        Queue::fake();
        $project = $this->makeProject();
        $this->actingAs($this->activeReporter($project))
            ->post(route('issues.store', $project), $payload)->assertSessionHasErrors($errors);
        $this->assertDatabaseCount('issues', 0);
        Queue::assertNothingPushed();
    }

    public static function invalidPayloads(): array
    {
        return [
            'required' => [[], ['title', 'description']],
            'too short' => [['title' => 'abcd', 'description' => '123456789'], ['title', 'description']],
            'title too long' => [['title' => str_repeat('a', 201), 'description' => str_repeat('b', 10)], ['title']],
            'description too long' => [['title' => 'Valid', 'description' => str_repeat('b', 10001)], ['description']],
            'wrong types' => [['title' => ['invalid'], 'description' => ['invalid']], ['title', 'description']],
        ];
    }

    #[DataProvider('validLengths')]
    public function test_exact_length_limits_are_accepted(int $titleLength, int $descriptionLength): void
    {
        Queue::fake();
        $project = $this->makeProject();
        $this->actingAs($this->activeReporter($project))->post(route('issues.store', $project), [
            'title' => str_repeat('a', $titleLength), 'description' => str_repeat('b', $descriptionLength),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('issues', 1);
    }

    public static function validLengths(): array
    {
        return ['minimum' => [5, 10], 'maximum' => [200, 10000]];
    }

    public function test_issue_from_another_project_is_not_accessible(): void
    {
        $project = $this->makeProject();
        $other = $this->makeProject();
        $reporter = $this->activeReporter($project);
        $issue = new Issue($this->payload());
        $issue->project()->associate($other);
        $issue->author()->associate($other->owner);
        $issue->save();

        $this->actingAs($reporter)->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertNotFound();
        $this->get(route('issues.show', ['project' => $other, 'issue' => $issue]))->assertForbidden();
    }

    public function test_unknown_route_project_cannot_be_replaced_by_request_project(): void
    {
        $project = $this->makeProject();
        $this->actingAs($this->activeReporter($project))
            ->post('/projects/99999999/submit', $this->payload() + ['project_id' => $project->id])
            ->assertNotFound();
        $this->assertDatabaseCount('issues', 0);
    }

    #[Group('nlp-integration')]
    public function test_database_job_is_only_enqueued_after_outer_commit(): void
    {
        $this->requireDetectionJob();
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        DB::beginTransaction();
        try {
            $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload())->assertRedirect();
            $this->assertDatabaseCount('issues', 1);
            $this->assertDatabaseCount('jobs', 0);
            Http::assertNothingSent();
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('jobs', 1);
        $row = DB::table('jobs')->sole();
        $this->assertSame('nlp', $row->queue);
        $this->assertSame(DetectIssueCandidatesJob::class, json_decode($row->payload, true)['data']['commandName']);
        $this->assertSame('PENDING', Issue::query()->sole()->detection_status);
        Http::assertNothingSent();
    }

    #[Group('nlp-integration')]
    public function test_rollback_does_not_enqueue_detection(): void
    {
        $this->requireDetectionJob();
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        DB::beginTransaction();
        try {
            $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload())->assertRedirect();
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('issues', 0);
        $this->assertDatabaseCount('jobs', 0);
        // A later successful transaction must not revive the rolled-back callback.
        $this->post(route('issues.store', $project), $this->payload())->assertRedirect();
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    #[Group('nlp-integration')]
    public function test_enqueue_failure_keeps_the_committed_issue(): void
    {
        $this->requireDetectionJob();
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        Queue::shouldReceive('connection')->once()->with('database')
            ->andThrow(new RuntimeException('Queue unavailable'));

        $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload())->assertRedirect();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('issues', 1);
        $this->assertSame('OPEN', Issue::query()->sole()->status);
        $this->assertSame('FAILED', Issue::query()->sole()->detection_status);
        Http::assertNothingSent();
    }

    #[Group('nlp-integration')]
    public function test_nlp_worker_failure_does_not_delete_the_issue(): void
    {
        $this->requireDetectionJob();
        if (! class_exists(\App\Services\NlpClient::class)) {
            $this->markTestSkipped('NLP Client PR #16 belum terintegrasi.');
        }
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::fake(['*' => Http::response(['detail' => 'NLP unavailable'], 503)]);
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        // Supply a real same-project candidate so the worker cannot skip NLP for an empty pool.
        $candidate = new Issue([
            'title' => 'Checkout sebelumnya gagal',
            'description' => 'Laporan checkout sebelumnya untuk kandidat pembanding.',
        ]);
        $candidate->project()->associate($project);
        $candidate->author()->associate($reporter);
        $candidate->detection_status = 'COMPLETED';
        $candidate->save();
        $this->actingAs($reporter)
            ->post(route('issues.store', $project), $this->payload())->assertRedirect();
        $issue = Issue::query()->whereKeyNot($candidate->id)->sole();
        Http::assertNothingSent();

        // Job::$tries takes precedence over --tries. Exercise all real attempts,
        // advancing only the test clock through the job's configured backoff.
        $job = new DetectIssueCandidatesJob($issue->id);
        $this->assertSame(3, $job->tries);
        try {
            for ($attempt = 1; $attempt <= $job->tries; $attempt++) {
                $this->runNlpWorkerOnce();
                if ($attempt < $job->tries) {
                    $this->assertSame('PROCESSING', $issue->refresh()->detection_status);
                    $this->assertDatabaseCount('failed_jobs', 0);
                    $this->assertDatabaseCount('jobs', 1);
                    $this->travel($job->backoff()[$attempt - 1] + 1)->seconds();
                }
            }
        } finally {
            $this->travelBack();
        }

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://nlp.test/'));
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id, 'status' => 'OPEN', 'detection_status' => 'FAILED',
        ]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    private function runNlpWorkerOnce(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => 'nlp', '--once' => true,
            '--sleep' => 0, '--force' => true,
        ])->assertExitCode(0);
    }

    public function test_missing_job_keeps_issue_and_marks_detection_failed(): void
    {
        if (class_exists(DetectIssueCandidatesJob::class)) {
            $this->markTestSkipped('Only applicable before the real NLP Job is integrated.');
        }
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload() + [
            'project_id' => 999, 'author_id' => 999, 'status' => 'DUPLICATE',
            'source_type' => 'GITHUB', 'vote_count' => 999, 'duplicate_of' => 999,
            'detection_status' => 'COMPLETED',
        ])->assertRedirect();
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseHas('issues', [
            'project_id' => $project->id, 'author_id' => $reporter->id,
            'status' => 'OPEN', 'detection_status' => 'FAILED',
            'source_type' => 'FORM', 'vote_count' => 0, 'duplicate_of' => null,
        ]);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_inactive_reporter_cannot_read_even_their_own_issue(): void
    {
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        $issue = new Issue($this->payload());
        $issue->project()->associate($project);
        $issue->author()->associate($reporter);
        $issue->save();
        $project->memberships()->where('user_id', $reporter->id)->update(['is_active' => false]);

        $this->actingAs($reporter)->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertForbidden();
    }

    public function test_failed_issue_insert_rolls_back_without_dispatch(): void
    {
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        Queue::fake();
        Issue::creating(fn () => throw new RuntimeException('Issue insert failed'));
        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Issue insert failed');
        try {
            $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload());
        } finally {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('issues', 0);
            $this->assertDatabaseCount('jobs', 0);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        }
    }

    public function test_submit_views_render_and_escape_issue_content(): void
    {
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        $issue = new Issue([
            'title' => '<script>alert(1)</script>',
            'description' => '<script>alert(2)</script>',
        ]);
        $issue->project()->associate($project);
        $issue->author()->associate($reporter);
        $issue->save();

        $this->actingAs($reporter)->get(route('issues.create', $project))->assertOk();
        $this->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()->assertSee($issue->title)->assertSee($issue->description)
            ->assertDontSee('<script>alert(', false);
        $this->actingAs($project->owner)
            ->get(route('issues.show', ['project' => $project, 'issue' => $issue]))
            ->assertOk()->assertDontSee('Buat Issue Lagi');
    }

    #[Group('nlp-integration')]
    public function test_non_database_queue_configuration_cannot_run_nlp_inline(): void
    {
        $this->requireDetectionJob();
        $project = $this->makeProject();
        config(['queue.connections.database.driver' => 'sync']);
        $this->actingAs($this->activeReporter($project))
            ->post(route('issues.store', $project), $this->payload())->assertRedirect();
        $this->assertSame('FAILED', Issue::query()->sole()->detection_status);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    #[Group('nlp-integration')]
    public function test_secondary_failure_updating_detection_status_does_not_fail_submit(): void
    {
        $this->requireDetectionJob();
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        Queue::shouldReceive('connection')->once()->with('database')
            ->andThrow(new RuntimeException('Queue unavailable'));
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'update "issues"')) {
                throw new RuntimeException('Status storage unavailable');
            }
        });

        $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload())->assertRedirect();
        $this->assertDatabaseHas('issues', ['status' => 'OPEN', 'detection_status' => 'PENDING']);
        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    #[Group('nlp-integration')]
    public function test_retry_can_complete_and_redelivery_does_not_duplicate_candidates(): void
    {
        $this->requireDetectionJob();
        if (! class_exists(\App\Models\DuplicateCandidate::class)) {
            $this->markTestSkipped('DuplicateCandidate from PR #22 is not integrated.');
        }
        $project = $this->makeProject();
        $reporter = $this->activeReporter($project);
        $candidate = new Issue($this->payload());
        $candidate->project()->associate($project);
        $candidate->author()->associate($reporter);
        $candidate->detection_status = 'COMPLETED';
        $candidate->save();
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::fake(['http://nlp.test/candidates' => Http::sequence()
            ->push(['detail' => 'Unavailable'], 503)
            ->push(['candidates' => [['issue_id' => $candidate->id, 'similarity' => 0.9]]])]);

        $this->actingAs($reporter)->post(route('issues.store', $project), $this->payload())->assertRedirect();
        $issue = Issue::query()->whereKeyNot($candidate->id)->sole();
        Http::assertNothingSent();
        $this->runNlpWorkerOnce();
        $this->assertSame('PROCESSING', $issue->refresh()->detection_status);
        $this->assertDatabaseCount('failed_jobs', 0);
        $job = new DetectIssueCandidatesJob($issue->id);
        try {
            $this->travel($job->backoff()[0] + 1)->seconds();
            $this->runNlpWorkerOnce();
        } finally {
            $this->travelBack();
        }

        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        $this->assertDatabaseCount('duplicate_candidates', 1);
        $this->assertDatabaseHas('duplicate_candidates', [
            'issue_id' => $issue->id, 'candidate_issue_id' => $candidate->id,
        ]);
        Queue::connection('database')->push(new DetectIssueCandidatesJob($issue->id));
        $this->runNlpWorkerOnce();
        $this->assertDatabaseCount('duplicate_candidates', 1);
        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertSentCount(2);
    }
}
