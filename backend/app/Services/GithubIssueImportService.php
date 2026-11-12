<?php

namespace App\Services;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\GithubConfig;
use App\Models\ImportBatch;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

class GithubIssueImportService
{
    public function __construct(
        private readonly GithubIssuePreviewService $preview
    ) {
    }

    /**
     * Import GitHub issues only after preview confirmation.
     *
     * The controller must independently verify the preview
     * session and fetch GitHub issues again before calling
     * this service.
     *
     * @param array<int, mixed> $githubIssues
     * @return array<string, mixed>
     */
    public function import(
        Project $project,
        User $actor,
        int $expectedConfigId,
        string $expectedRepo,
        array $githubIssues,
        string $expectedFingerprint
    ): array {
        $this->authorizeOwner($project, $actor);

        if (! preg_match(
            '/\A[a-f0-9]{64}\z/',
            $expectedFingerprint
        )) {
            throw ValidationException::withMessages([
                'github' => 'Fingerprint pratinjau tidak valid.',
            ]);
        }

        return DB::transaction(function () use (
            $project,
            $actor,
            $expectedConfigId,
            $expectedRepo,
            $githubIssues,
            $expectedFingerprint
        ): array {
            // Serialize imports for the same project.
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->authorizeOwner($lockedProject, $actor);

            // Lock and verify the current GitHub configuration.
            $config = GithubConfig::query()
                ->where('project_id', $lockedProject->id)
                ->lockForUpdate()
                ->first();

            if (
                $config === null
                || (int) $config->id !== $expectedConfigId
                || $config->repo_full_name !== $expectedRepo
            ) {
                throw ValidationException::withMessages([
                    'github' =>
                        'Konfigurasi GitHub berubah. Ambil ulang pratinjau.',
                ]);
            }

            // Recheck database identities inside the transaction.
            $result = $this->preview->preview(
                $lockedProject,
                $config->repo_full_name,
                $githubIssues
            );

            // Compare the exact preview that was confirmed.
            $actualFingerprint = hash(
                'sha256',
                json_encode(
                    $result['rows'],
                    JSON_THROW_ON_ERROR
                )
            );

            if (! hash_equals(
                $expectedFingerprint,
                $actualFingerprint
            )) {
                throw ValidationException::withMessages([
                    'github' =>
                        'Data GitHub berubah sejak pratinjau. '
                        . 'Ambil ulang issue sebelum mengimpor.',
                ]);
            }

            if ($result['valid_count'] === 0) {
                throw ValidationException::withMessages([
                    'github' =>
                        'Tidak ada issue GitHub baru yang valid untuk diimpor.',
                ]);
            }

            // Create one import batch for this confirmation.
            $batch = new ImportBatch();

            $batch->project_id = $lockedProject->id;
            $batch->actor_id = $actor->id;
            $batch->source_type = 'GITHUB';
            $batch->file_name = null;
            $batch->total_rows = $result['total_rows'];
            $batch->valid_rows = $result['valid_count'];
            $batch->skipped_rows =
                $result['invalid_count']
                + $result['already_imported_count'];
            $batch->status = 'PROCESSING';
            $batch->created_at = now();

            $batch->save();

            $issueIds = [];

            foreach ($result['rows'] as $row) {
                if ($row['status'] !== 'VALID') {
                    continue;
                }

                $data = $row['data'];

                // Only title and description use mass assignment.
                // All source metadata is set explicitly.
                $issue = new Issue([
                    'title' => $data['title'],
                    'description' => $data['description'],
                ]);

                $issue->project()->associate($lockedProject);
                $issue->author_id = null;

                $issue->source_type = 'GITHUB';
                $issue->source_id = null;
                $issue->github_repo = $data['github_repo'];
                $issue->github_number = $data['github_number'];
                $issue->source_url = $data['source_url'];

                $issue->status = 'OPEN';
                $issue->detection_status = 'PENDING';
                $issue->vote_count = 0;
                $issue->duplicate_of = null;

                $issue->save();

                $issueIds[] = (int) $issue->id;
            }

            $batch->status = 'COMPLETED';
            $batch->save();

            // Update the synchronization timestamp only
            // after the import has been prepared successfully.
            $config->last_synced_at = now();
            $config->save();

            // Audit metadata only: never log the PAT or
            // complete GitHub issue descriptions.
            DB::table('activity_logs')->insert([
                'project_id' => $lockedProject->id,
                'user_id' => $actor->id,
                'issue_id' => null,
                'action' => 'IMPORT_GITHUB',
                'old_values' => null,
                'new_values' => json_encode([
                    'import_batch_id' => $batch->id,
                    'source_type' => 'GITHUB',
                    'repo_full_name' => $config->repo_full_name,
                    'total_rows' => $result['total_rows'],
                    'imported_count' => count($issueIds),
                    'invalid_count' => $result['invalid_count'],
                    'already_imported_count' =>
                        $result['already_imported_count'],
                ], JSON_THROW_ON_ERROR),
                'reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // NLP must run only after a successful commit.
            DB::afterCommit(function () use ($issueIds): void {
                foreach ($issueIds as $issueId) {
                    $this->enqueueDetection($issueId);
                }
            });

            return [
                'batch_id' => (int) $batch->id,
                'imported_count' => count($issueIds),
                'invalid_count' => $result['invalid_count'],
                'already_imported_count' =>
                    $result['already_imported_count'],
                'issue_ids' => $issueIds,
            ];
        }, 3);
    }

    private function authorizeOwner(
        Project $project,
        User $actor
    ): void {
        if (
            $actor->role !== 'MAINTAINER'
            || (int) $project->owner_id !== (int) $actor->id
        ) {
            throw new AuthorizationException(
                'Hanya Maintainer pemilik project yang dapat mengimpor.'
            );
        }
    }

    private function enqueueDetection(int $issueId): void
    {
        try {
            if (! is_subclass_of(
                DetectIssueCandidatesJob::class,
                \Illuminate\Contracts\Queue\ShouldQueue::class
            )) {
                throw new LogicException(
                    'NLP detection harus menggunakan queue.'
                );
            }

            if (config('queue.connections.database.driver') !== 'database') {
                throw new LogicException(
                    'NLP detection memerlukan database queue.'
                );
            }

            Bus::dispatch(
                (new DetectIssueCandidatesJob($issueId))
                    ->onConnection('database')
                    ->onQueue('nlp')
            );
        } catch (Throwable $exception) {
            // A queue failure must not delete imported issues.
            try {
                Issue::query()
                    ->whereKey($issueId)
                    ->where('detection_status', 'PENDING')
                    ->update(['detection_status' => 'FAILED']);
            } catch (Throwable $statusException) {
                Log::error('Failed to update GitHub issue status', [
                    'issue_id' => $issueId,
                    'exception_type' => $statusException::class,
                ]);
            }

            Log::error('Cannot enqueue GitHub import NLP detection', [
                'issue_id' => $issueId,
                'exception_type' => $exception::class,
            ]);
        }
    }
}
