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
use RuntimeException;
use Tests\TestCase;

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
            '2026_10_21_200003_create_invitations_table.php',
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
        Http::fake();
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
        // JSON proves auth enforcement; browser redirect still requires Joshua's login route.
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
        $this->requireDetectionJob();
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
    }

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

        $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => 'nlp', '--once' => true,
            '--tries' => 1, '--sleep' => 0, '--force' => true,
        ])->assertExitCode(0);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://nlp.test/'));
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id, 'status' => 'OPEN', 'detection_status' => 'FAILED',
        ]);
    }
}
