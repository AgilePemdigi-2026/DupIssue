<?php

namespace Tests\Feature\Nlp;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Services\NlpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * These integration tests intentionally depend on the Issue/Project migrations
 * supplied by PR #18. They are skipped on this branch until those migrations
 * are present; no substitute production models or schemas are created here.
 */
class DetectIssueCandidatesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! class_exists(Issue::class)) {
            $this->markTestSkipped('Blocked until the Issue model and PR #18 migrations are merged.');
        }

        parent::setUp();

        if (! Schema::hasTable('issues')) {
            $this->markTestSkipped('Blocked until the Issue model and PR #18 migrations are merged.');
        }
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

    public function test_different_project_and_self_candidates_are_rejected_by_database_validation(): void
    {
        $issue = $this->makeIssue(1, 'New issue');
        $otherProject = $this->makeIssue(2, 'Other project');

        Http::fake(['*/candidates' => Http::response(['candidates' => [
            ['issue_id' => $issue->id, 'similarity' => 0.99],
            ['issue_id' => $otherProject->id, 'similarity' => 0.99],
        ]])]);

        (new DetectIssueCandidatesJob($issue->id))->handle(app(NlpClient::class));

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
        $job->handle(app(NlpClient::class));

        $this->assertDatabaseCount('duplicate_candidates', 1);
    }

    public function test_job_configuration_is_database_nlp_and_three_attempts(): void
    {
        $job = new DetectIssueCandidatesJob(123);

        $this->assertSame('database', $job->connection);
        $this->assertSame('nlp', $job->queue);
        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff());
    }

    private function makeIssue(int $projectId, string $title): Issue
    {
        return Issue::withoutEvents(function () use ($projectId, $title): Issue {
            return Issue::forceCreate([
                'project_id' => $projectId,
                'author_id' => 1,
                'title' => $title,
                'description' => 'A sufficiently long issue description for NLP testing.',
                'source_type' => 'FORM',
                'status' => 'OPEN',
                'detection_status' => 'PENDING',
                'duplicate_of' => null,
                'vote_count' => 0,
            ]);
        });
    }
}
