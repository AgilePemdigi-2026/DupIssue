<?php

namespace App\Jobs;

use App\Exceptions\NlpClientException;
use App\Models\DuplicateCandidate;
use App\Models\Issue;
use App\Services\NlpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectIssueCandidatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $issueId)
    {
        $this->onConnection('database')->onQueue('nlp')->afterCommit();
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(NlpClient $nlpClient): void
    {
        $issue = Issue::query()->find($this->issueId);

        if ($issue === null) {
            Log::warning('Skipping NLP detection because the issue no longer exists.', [
                'issue_id' => $this->issueId,
            ]);
            return;
        }

        if ($issue->detection_status === 'COMPLETED') {
            return;
        }

        // Another delivery may have completed since the initial read. Never
        // downgrade COMPLETED, including when restarting a previously failed job.
        Issue::query()->whereKey($this->issueId)
            ->whereIn('detection_status', ['PENDING', 'PROCESSING', 'FAILED'])
            ->update(['detection_status' => 'PROCESSING']);
        $issue = Issue::query()->whereKey($this->issueId)
            ->where('detection_status', 'PROCESSING')->first();
        // Do not use the update row count here: an already PROCESSING row can
        // be unchanged on databases that report changed rather than matched rows.
        if ($issue === null) {
            return;
        }

        try {
            $comparisonIssues = Issue::query()
                ->where('project_id', $issue->project_id)
                ->whereKeyNot($issue->getKey())
                ->get();

            if ($comparisonIssues->isEmpty()) {
                $this->persistResults($issue, []);
                return;
            }

            $response = $nlpClient->candidates(
                $issue->project_id,
                $this->issuePayload($issue),
                $comparisonIssues->map(fn (Issue $candidate): array => $this->issuePayload($candidate, true))->all(),
            );

            $results = $this->validatedResults($issue, $response['candidates'] ?? []);
            $this->persistResults($issue, $results);
        } catch (Throwable $exception) {
            if ($exception instanceof NlpClientException && ! $this->isRetryable($exception)) {
                $this->markFailed($exception);
                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception);
    }

    /** @return array<string, int|string> */
    private function issuePayload(Issue $issue, bool $includeProjectId = false): array
    {
        $payload = [
            'issue_id' => (int) $issue->getKey(),
            'title' => (string) $issue->title,
            'description' => (string) $issue->description,
        ];

        if ($includeProjectId) {
            $payload['project_id'] = (int) $issue->project_id;
        }

        return $payload;
    }

    /** @param array<int, mixed> $candidates
     *  @return array<int, array{candidate_issue_id: int, similarity_score: float}>
     */
    private function validatedResults(Issue $issue, array $candidates): array
    {
        $valid = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)
                || ! array_key_exists('issue_id', $candidate)
                || ! array_key_exists('similarity', $candidate)) {
                throw new NlpClientException('NLP service returned an invalid candidate item.', 422);
            }

            $candidateId = $this->positiveInteger($candidate['issue_id']);
            $score = $candidate['similarity'];
            if ($candidateId === null || (! is_int($score) && ! is_float($score)) || ! is_finite((float) $score)) {
                throw new NlpClientException('NLP service returned an invalid candidate item.', 422);
            }

            $score = max(0.0, min(1.0, (float) $score));
            if ($candidateId === (int) $issue->getKey() || $score < 0.75) {
                continue;
            }

            $candidateIssue = Issue::query()
                ->whereKey($candidateId)
                ->where('project_id', $issue->project_id)
                ->first();
            if ($candidateIssue === null) {
                continue;
            }

            $valid[$candidateId] = [
                'candidate_issue_id' => $candidateId,
                'similarity_score' => $score,
            ];
        }

        usort($valid, fn (array $left, array $right): int => $right['similarity_score'] <=> $left['similarity_score']);

        return array_slice($valid, 0, 10);
    }

    /** @param array<int, array{candidate_issue_id: int, similarity_score: float}> $results */
    private function persistResults(Issue $issue, array $results): void
    {
        DB::transaction(function () use ($issue, $results): void {
            // Claim completion atomically before touching candidates. The status
            // and results commit together; a later delivery preserves both.
            $completed = Issue::query()->whereKey($issue->getKey())
                ->whereIn('detection_status', ['PENDING', 'PROCESSING', 'FAILED'])
                ->update(['detection_status' => 'COMPLETED']);
            if ($completed === 0) {
                return;
            }

            DuplicateCandidate::query()->where('issue_id', $issue->getKey())->delete();

            foreach ($results as $result) {
                DuplicateCandidate::query()->updateOrCreate(
                    [
                        'issue_id' => $issue->getKey(),
                        'candidate_issue_id' => $result['candidate_issue_id'],
                    ],
                    ['similarity_score' => $result['similarity_score']],
                );
            }
        });
    }

    private function isRetryable(NlpClientException $exception): bool
    {
        if ($exception->statusCode() === 503) {
            return true;
        }

        // A connection/timeout exception has no status code. A missing URL is
        // a permanent configuration error and should fail without retrying.
        return $exception->statusCode() === null
            && $exception->getMessage() !== 'NLP_SERVICE_URL is not configured.';
    }

    private function markFailed(?Throwable $exception): void
    {
        $failed = Issue::query()->whereKey($this->issueId)
            ->whereIn('detection_status', ['PENDING', 'PROCESSING'])
            ->update(['detection_status' => 'FAILED']);
        if ($failed === 0) {
            return;
        }

        Log::error('NLP issue detection failed.', [
            'issue_id' => $this->issueId,
            'status_code' => $exception instanceof NlpClientException ? $exception->statusCode() : null,
            'exception_class' => $exception ? $exception::class : null,
            'error' => $exception instanceof NlpClientException
                ? $exception->getMessage()
                : 'Unexpected queue processing failure.',
        ]);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
