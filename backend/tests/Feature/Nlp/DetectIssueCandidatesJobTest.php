<?php

namespace Tests\Feature\Nlp;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\NlpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * PR #18 supplies Project/Membership; PR #19 supplies Issue/Submit Issue;
 * PR #22 supplies the NLP Database Queue and DuplicateCandidate. Dependency
 * guards support pre-integration branches without substitute models or schemas.
 */
class DetectIssueCandidatesJobTest extends TestCase
{
    use RefreshDatabase;

    private array $projects = [];

    private User $author;

    protected function setUp(): void
    {
        if (! class_exists(Issue::class)) {
            $this->markTestSkipped('Blocked until Issue from PR #19 and Project/Membership from PR #18 are integrated.');
        }

        parent::setUp();

        if (! Schema::hasTable('issues')) {
            $this->markTestSkipped('Blocked until Issue from PR #19 and Project/Membership from PR #18 are integrated.');
        }

        $this->author = User::factory()->create(['role' => 'REPORTER']);
        config(['services.nlp.base_url' => 'http://nlp.test']);
        Http::preventStrayRequests();
    }

    public function test_same_project_candidates_are_saved_above_threshold_and_limited_to_ten(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidates = collect(range(1, 12))->map(fn (int $id): Issue => $this->makeIssue(1, "Candidate {$id}"));

        Http::fake(['*/candidates' => Http::response([
            'candidates' => $candidates->map(fn (Issue $candidate, int $index): array => [
                'issue_id' => $candidate->id,
                'similarity' => $index === 11 ? 0.74 : 0.99 - ($index / 100),
            ])->all(),
        ])]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        Http::assertSent(function ($request) use ($issue, $candidates): bool {
            $data = $request->data();

            return $data['project_id'] === $issue->project_id
                && $data['new_issue']['issue_id'] === $issue->id
                && collect($data['candidates'])->every(
                    fn (array $candidate): bool => $candidate['project_id'] === $issue->project_id
                        && $candidate['issue_id'] !== $issue->id
                        && array_key_exists('title', $candidate)
                        && array_key_exists('description', $candidate)
                );
        });
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertCount(10, DuplicateCandidate::where('issue_id', $issue->id)->get());
        $this->assertDatabaseMissing('duplicate_candidates', [
            'issue_id' => $issue->id,
            'candidate_issue_id' => $candidates->last()->id,
        ]);
    }

    public function test_different_project_and_self_candidates_are_filtered_by_job(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $this->makeIssue(1, 'Same project comparison');
        $otherProject = $this->makeIssue(2, 'Other project');

        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $issue->id, 'similarity' => 0.99],
            ['issue_id' => $otherProject->id, 'similarity' => 0.99],
        ]])]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        Http::assertSentCount(1);
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertDatabaseCount('duplicate_candidates', 0);
    }

    public function test_negative_similarity_is_clamped_to_zero_and_ignored_below_threshold(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidate = $this->makeIssue(1, 'Candidate');

        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => -0.2],
        ]])]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        $this->assertDatabaseCount('duplicate_candidates', 0);
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
    }

    public function test_empty_comparison_set_completes_without_calling_nlp(): void
    {
        $issue = $this->makeIssue(1, 'Only issue');
        Http::fake();

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        Http::assertNothingSent();
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
    }

    public function test_transient_nlp_failure_leaves_issue_processing_for_queue_retry(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $this->makeIssue(1, 'Comparison issue');
        Http::fake(['*/candidates' => Http::response(['detail' => 'unavailable'], 503)]);

        $this->expectException(\App\Exceptions\NlpClientException::class);
        try {
            (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));
        } finally {
            $issue->refresh();
            $this->assertSame('PROCESSING', $issue->detection_status);
            $this->assertSame('FORM', $issue->source_type);
            $this->assertSame('OPEN', $issue->status);
            $this->assertNull($issue->duplicate_of);
        }
    }

    public function test_final_failure_marks_failed_without_deleting_the_issue(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $issue->update(['detection_status' => 'PROCESSING']);
        $job = new DetectIssueCandidatesJob($issue->id);

        $job->failed(new RuntimeException('temporary NLP failure'));

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'detection_status' => 'FAILED',
            'source_type' => 'FORM',
            'status' => 'OPEN',
        ]);
    }

    public function test_invalid_nlp_response_fails_without_saving_candidates(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidate = $this->makeIssue(1, 'Comparison issue');
        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 'not-a-number'],
        ]])]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        $this->assertSame('FAILED', $issue->refresh()->detection_status);
        $this->assertDatabaseCount('duplicate_candidates', 0);
    }

    public function test_reprocessing_same_successful_job_does_not_duplicate_candidates(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidate = $this->makeIssue(1, 'Comparison issue');
        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 0.9],
        ]])]);

        $job = new DetectIssueCandidatesJob($issue->id);
        $job->handle(app(NlpClient::class));
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $saved = DuplicateCandidate::query()->sole()->getRawOriginal();
        $job->failed(new RuntimeException('Late failure from an earlier delivery'));
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        $job->handle(app(NlpClient::class));

        $this->assertDatabaseCount('duplicate_candidates', 1);
        $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        Http::assertSentCount(1);
    }

    public function test_failed_issue_can_be_processed_again(): void
    {
        $issue = $this->makeIssue(1, 'Previously failed issue');
        $candidate = $this->makeIssue(1, 'Comparison issue');
        $issue->update(['detection_status' => 'FAILED']);
        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 0.9],
        ]])]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        $this->assertDatabaseCount('duplicate_candidates', 1);
        Http::assertSentCount(1);
    }

    public function test_completion_between_initial_read_and_processing_update_is_preserved(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidate = $this->makeIssue(1, 'Comparison issue');
        $saved = DuplicateCandidate::create([
            'issue_id' => $issue->id, 'candidate_issue_id' => $candidate->id, 'similarity_score' => 0.9,
        ])->fresh()->getRawOriginal();
        $completedElsewhere = false;
        Issue::retrieved(function (Issue $loaded) use ($issue, &$completedElsewhere): void {
            if ($loaded->id === $issue->id && ! $completedElsewhere) {
                $completedElsewhere = true;
                // Leave the loaded object stale while another delivery commits.
                Issue::whereKey($issue->id)->update(['detection_status' => 'COMPLETED']);
            }
        });
        Http::fake();

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        $this->assertTrue($completedElsewhere);
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        Http::assertNothingSent();
    }

    #[DataProvider('lateResponses')]
    public function test_delivery_finishing_after_another_success_preserves_committed_results(int $status): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidate = $this->makeIssue(1, 'Comparison issue');
        $winner = \Mockery::mock(NlpClient::class);
        $winner->shouldReceive('candidates')->once()->andReturn(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 0.95],
        ]]);
        $saved = null;
        // Deterministic interleaving, not a multi-process concurrency test.
        Http::fake(['*/candidates' => function () use ($issue, $candidate, $winner, $status, &$saved) {
            (new DetectIssueCandidatesJob($issue->id))->handle($winner);
            $saved = DuplicateCandidate::query()->sole()->getRawOriginal();

            return Http::response(['candidates' => [
                ['issue_id' => $candidate->id, 'similarity' => 0.8],
            ]], $status);
        }]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

        $this->assertNotNull($saved);
        $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        $this->assertSame('COMPLETED', $issue->refresh()->detection_status);
        $this->assertSame('OPEN', $issue->status);
        Http::assertSentCount(1);
    }

    public static function lateResponses(): array
    {
        return ['late success' => [200], 'late permanent failure' => [422]];
    }

    public function test_persistence_failure_rolls_back_completion_and_candidate_replacement(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $candidate = $this->makeIssue(1, 'Comparison issue');
        $saved = DuplicateCandidate::create([
            'issue_id' => $issue->id, 'candidate_issue_id' => $candidate->id, 'similarity_score' => 0.8,
        ])->fresh()->getRawOriginal();
        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $candidate->id, 'similarity' => 0.9],
        ]])]);
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_starts_with($query, 'insert into "duplicate_candidates"')) {
                throw new RuntimeException('Simulated candidate write failure');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated candidate write failure');
        try {
            (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));
        } finally {
            $this->assertSame('PROCESSING', $issue->refresh()->detection_status);
            $this->assertSame($saved, DuplicateCandidate::query()->sole()->getRawOriginal());
        }
    }

    public function test_job_configuration_is_database_nlp_and_three_attempts(): void
    {
        $job = new DetectIssueCandidatesJob(123);

        $this->assertSame('database', $job->connection);
        $this->assertSame('nlp', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertTrue($job->afterCommit);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    private function makeIssue(int $projectId, string $title): Issue
    {
        $project = $this->projects[$projectId] ??= Project::factory()->create();

        return Issue::forceCreate([
            'project_id' => $project->id,
            'author_id' => $this->author->id,
            'title' => $title,
            'description' => 'A sufficiently long issue description for NLP testing.',
            'source_type' => 'FORM',
            'status' => 'OPEN',
            'detection_status' => 'PENDING',
            'duplicate_of' => null,
            'vote_count' => 0,
        ]);
    }
}
