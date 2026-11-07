<?php

namespace App\Services;

use App\Jobs\DetectIssueCandidatesJob;
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

class CsvIssueImportService
{
    public function __construct(
        private readonly CsvIssuePreviewService $preview
    ) {
    }

    public function import(
        Project $project,
        User $actor,
        array $parsed,
        string $fileName
    ): array {
        $this->authorizeOwner($project, $actor);

        return DB::transaction(function () use (
            $project,
            $actor,
            $parsed,
            $fileName
        ): array {

            // Serialisasi impor yang menggunakan service ini
            // untuk project yang sama.
            $lockedProject = Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Periksa ulang hak akses saat menyimpan.
            $this->authorizeOwner($lockedProject, $actor);

            // Jangan mempercayai hasil preview dari browser.
            // Selalu periksa kembali database sebelum impor.
            $result = $this->preview->preview(
                $lockedProject,
                $parsed
            );

            if ($result['valid_count'] === 0) {
                throw ValidationException::withMessages([
                    'file' => 'Tidak ada baris valid baru untuk diimpor.',
                ]);
            }

            $batch = new ImportBatch();
            $batch->project_id = $lockedProject->id;
            $batch->actor_id = $actor->id;
            $batch->source_type = 'CSV';
            $batch->file_name = mb_substr(
                basename($fileName),
                0,
                255
            );
            $batch->total_rows = $result['total_rows'];
            $batch->valid_rows = $result['valid_count'];
            $batch->skipped_rows =
                $result['invalid_count']
                + $result['already_imported_count'];
            $batch->status = 'PROCESSING';
            $batch->save();

            $issueIds = [];

            foreach ($result['rows'] as $row) {
                if ($row['status'] !== 'VALID') {
                    continue;
                }

                $data = $row['data'];

                $issue = new Issue([
                    'title' => $data['title'],
                    'description' => $data['description'],
                ]);

                $issue->project()->associate($lockedProject);
                $issue->author_id = null;
                $issue->source_type = 'CSV';
                $issue->source_id = $data['source_id'];
                $issue->status = 'OPEN';
                $issue->detection_status = 'PENDING';
                $issue->vote_count = 0;
                $issue->duplicate_of = null;
                $issue->save();

                $issueIds[] = (int) $issue->id;
            }

            $batch->status = 'COMPLETED';
            $batch->save();

            // Audit hanya menyimpan metadata proses,
            // bukan seluruh isi CSV.
            DB::table('activity_logs')->insert([
                'project_id' => $lockedProject->id,
                'user_id' => $actor->id,
                'issue_id' => null,
                'action' => 'IMPORT_CSV',
                'old_values' => null,
                'new_values' => json_encode([
                    'import_batch_id' => $batch->id,
                    'source_type' => 'CSV',
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

            // NLP baru dijadwalkan setelah COMMIT berhasil.
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
            if (!is_subclass_of(
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
            try {
                Issue::query()
                    ->whereKey($issueId)
                    ->where('detection_status', 'PENDING')
                    ->update(['detection_status' => 'FAILED']);
            } catch (Throwable $statusException) {
                Log::error('Failed to update imported issue status', [
                    'issue_id' => $issueId,
                    'exception_type' => $statusException::class,
                ]);
            }

            Log::error('Cannot enqueue CSV import NLP detection', [
                'issue_id' => $issueId,
                'exception_type' => $exception::class,
            ]);
        }
    }
}
