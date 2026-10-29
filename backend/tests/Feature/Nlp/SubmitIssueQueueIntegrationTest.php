<?php

namespace Tests\Feature\Nlp;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Services\NlpClient;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SubmitIssueQueueIntegrationTest extends TestCase
{
    // queue:work runs in this PHP process to share the isolated SQLite memory DB.
    use DatabaseMigrations;

    private function fixtures(): array
    {
        $project = Project::factory()->create();
        $reporter = User::factory()->create(['role' => 'REPORTER']);
        Membership::factory()->create(['project_id' => $project->id, 'user_id' => $reporter->id]);
        $payload = [
            'title' => 'Checkout button does not respond',
            'description' => 'Clicking the checkout button does not complete the payment.',
        ];
        $candidate = Issue::forceCreate($payload + [
            'project_id' => $project->id, 'author_id' => $reporter->id, 'detection_status' => 'COMPLETED',
        ]);

        return [$project, $reporter, $payload, $candidate];
    }

    private function submit(Project $project, User $reporter, array $payload): Issue
    {
        $this->actingAs($reporter)->post(route('issues.store', $project), $payload)->assertRedirect();
        $issue = Issue::latest('id')->firstOrFail();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('PENDING', $issue->detection_status);
        $row = DB::table('jobs')->sole();
        $this->assertSame('nlp', $row->queue);
        $this->assertSame(DetectIssueCandidatesJob::class, json_decode($row->payload, true)['data']['commandName']);

        return $issue;
    }

    private function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => 'nlp', '--once' => true,
            '--sleep' => 0, '--force' => true,
        ])->assertExitCode(0);
    }

    private function assertCompleted(Issue $issue): void
    {
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        $this->assertSame('FORM', $issue->source_type);
        $this->assertNull($issue->duplicate_of);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    #[Group('nlp-integration')]
    public function test_submit_database_worker_real_client_and_threshold_with_http_fake(): void
    {
        [$project, $reporter, $payload, $candidate] = $this->fixtures();
        $below = Issue::forceCreate($payload + ['project_id' => $project->id]);
        $foreign = Issue::forceCreate($payload + ['project_id' => Project::factory()->create()->id]);
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::preventStrayRequests();
        Http::fake(['http://nlp.test/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 0.75],
            ['issue_id' => $below->id, 'similarity' => 0.7499],
            ['issue_id' => $foreign->id, 'similarity' => 0.99],
        ]])]);

        $issue = $this->submit($project, $reporter, $payload);
        Http::assertNothingSent();
        $this->work();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://nlp.test/candidates'
            && $request->method() === 'POST'
            && $request['project_id'] === $project->id
            && $request['new_issue']['issue_id'] === $issue->id
            && collect($request['candidates'])->pluck('issue_id')->sort()->values()->all() === [$candidate->id, $below->id]);
        $this->assertCompleted($issue);
        $saved = DuplicateCandidate::query()->sole();
        $this->assertSame($issue->id, $saved->issue_id);
        $this->assertSame($candidate->id, $saved->candidate_issue_id);
        $this->assertSame(0.75, $saved->similarity_score);
    }

    #[Group('nlp-integration')]
    public function test_completed_result_survives_redelivery_after_last_attempt_ack_loss(): void
    {
        [$project, $reporter, $payload, $candidate] = $this->fixtures();
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::preventStrayRequests();
        Http::fake(['http://nlp.test/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 0.9],
        ]])]);
        $issue = $this->submit($project, $reporter, $payload);
        Http::assertNothingSent();
        $id = DB::table('jobs')->sole()->id;
        // Simulate attempt 3 persisting successfully, then dying before deleting
        // its reservation. The next worker sees an expired, exhausted delivery.
        $this->assertSame(1, DB::table('jobs')->where('id', $id)->update([
            'attempts' => 3, 'reserved_at' => now()->timestamp - 100,
        ]));
        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $saved = DuplicateCandidate::query()->sole()->getRawOriginal();

        $this->work();

        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        $this->assertNull($issue->duplicate_of);
        $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        $this->assertDatabaseCount('jobs', 0);
        // Queue delivery failure remains observable; it must not erase the
        // already successful domain result or be hidden by changing max tries.
        $this->assertDatabaseCount('failed_jobs', 1);
        Http::assertSentCount(1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    #[Group('nlp-integration')]
    public function test_permanent_nlp_error_is_not_retried_by_database_worker(): void
    {
        [$project, $reporter, $payload] = $this->fixtures();
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::preventStrayRequests();
        Http::fake(['http://nlp.test/candidates' => Http::response(['detail' => 'Invalid request'], 422)]);

        $issue = $this->submit($project, $reporter, $payload);
        Http::assertNothingSent();
        $this->work();

        $this->assertSame('FAILED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        $this->assertNull($issue->duplicate_of);
        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseCount('duplicate_candidates', 0);
        $this->assertDatabaseCount('jobs', 0);
        // Permanent domain errors are handled by the Job, so the queue delivery
        // is acknowledged rather than recorded as an exhausted failed job.
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->work();
        Http::assertSentCount(1);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    #[Group('nlp-live')]
    public function test_submit_database_worker_and_live_fastapi(): void
    {
        $url = getenv('NLP_LIVE_TEST_URL');
        if (! $url) {
            $this->markTestSkipped('FastAPI live opt-in: set NLP_LIVE_TEST_URL to a running local service with the real model. No HTTP fake is used by this test.');
        }
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        $this->assertSame('http', $parts['scheme'] ?? null);
        $this->assertContains($parts['host'] ?? null, ['127.0.0.1', 'localhost', '[::1]']);
        config(['services.nlp.base_url' => $url]);
        $this->assertSame('ok', app(NlpClient::class)->health()['status']);
        [$project, $reporter, $payload, $candidate] = $this->fixtures();
        // Identical texts give a deterministic above-threshold smoke test without
        // changing production 0.75 or pretending to evaluate semantic accuracy.
        $issue = $this->submit($project, $reporter, $payload);
        $this->work();
        $this->assertCompleted($issue);
        $saved = DuplicateCandidate::query()->sole();
        $this->assertSame($candidate->id, $saved->candidate_issue_id);
        $this->assertSame($issue->id, $saved->issue_id);
        $this->assertGreaterThanOrEqual(0.75, $saved->similarity_score);
    }
}
