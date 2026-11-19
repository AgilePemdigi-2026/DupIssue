<?php

namespace App\Http\Controllers;

use App\Models\GithubAppConnection;
use App\Models\Project;
use App\Services\GithubAppIssueFetchService;
use App\Services\GithubIssuePreviewService;
use App\Services\GithubIssueImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GithubAppIssueController extends Controller
{
    /**
     * Preview GitHub issues through an installation token.
     * No issue is imported at this stage.
     */
    /**
     * Confirm the previously previewed GitHub App import.
     * Issues are fetched again before the database transaction.
     */
    public function confirmImport(
        Request $request,
        Project $project,
        GithubAppIssueFetchService $fetchService,
        GithubIssueImportService $importService
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang diizinkan.'
        );

        // Import hanya boleh dilakukan setelah konfirmasi eksplisit.
        $request->validate([
            'confirmed' => ['required', 'accepted'],
        ]);

        $pending = $request->session()->get(
            'source_issue.github_app_preview'
        );

        // Periksa bahwa preview berasal dari user dan project
        // yang sama, serta belum kedaluwarsa.
        if (
            ! is_array($pending)
            || ! is_int($pending['project_id'] ?? null)
            || ! is_int($pending['actor_id'] ?? null)
            || ! is_int($pending['connection_id'] ?? null)
            || ! is_int($pending['installation_id'] ?? null)
            || ! is_int($pending['repository_id'] ?? null)
            || ! is_string($pending['repo_full_name'] ?? null)
            || ! is_string($pending['fingerprint'] ?? null)
            || ! preg_match(
                '/\A[a-f0-9]{64}\z/',
                $pending['fingerprint']
            )
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['project_id'] !== (int) $project->id
            || $pending['actor_id'] !== (int) $user->id
            || $pending['connection_id'] <= 0
            || $pending['installation_id'] <= 0
            || $pending['repository_id'] <= 0
            || $pending['expires_at'] <= now()->timestamp
        ) {
            $request->session()->forget(
                'source_issue.github_app_preview'
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Pratinjau GitHub App tidak valid atau kedaluwarsa. '
                    . 'Ambil ulang issue sebelum mengimpor.',
            ], 409)->header('Cache-Control', 'no-store');
        }

        $connection = GithubAppConnection::query()
            ->where('project_id', $project->id)
            ->first();

        // Koneksi mungkin telah diganti setelah preview dibuat.
        if (
            $connection === null
            || (int) $connection->id !== $pending['connection_id']
            || (int) $connection->installation_id
                !== $pending['installation_id']
            || (int) $connection->repository_id
                !== $pending['repository_id']
            || $connection->repo_full_name
                !== $pending['repo_full_name']
        ) {
            $request->session()->forget(
                'source_issue.github_app_preview'
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Koneksi GitHub App berubah. '
                    . 'Ambil ulang pratinjau sebelum mengimpor.',
            ], 409)->header('Cache-Control', 'no-store');
        }

        try {
            // Ambil issue terbaru menggunakan installation token.
            // Tidak mempercayai data issue dari browser.
            $githubIssues = $fetchService->fetchIssues(
                $connection
            );
        } catch (RuntimeException $exception) {
            $apiStatus = $exception->getCode();

            $status = match (true) {
                $apiStatus === 429 => 429,
                $apiStatus >= 500 => 502,
                $apiStatus === 0 => 503,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $status)->header('Cache-Control', 'no-store');
        }

        // Service memeriksa ulang kepemilikan, konfigurasi,
        // fingerprint, dan duplikat dalam transaksi database.
        $result = $importService->importFromApp(
            $project,
            $user,
            $pending['connection_id'],
            $pending['installation_id'],
            $pending['repository_id'],
            $pending['repo_full_name'],
            $githubIssues,
            $pending['fingerprint']
        );

        // Preview hanya dikonsumsi setelah import berhasil.
        $request->session()->forget(
            'source_issue.github_app_preview'
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Issue GitHub App berhasil diimpor.',
            'result' => $result,
        ])->header('Cache-Control', 'no-store');
    }

    public function preview(
        Request $request,
        Project $project,
        GithubAppIssueFetchService $fetchService,
        GithubIssuePreviewService $previewService
    ): JsonResponse {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang diizinkan.'
        );

        // Preview sebelumnya tidak boleh digunakan kembali
        // jika proses pengambilan issue terbaru gagal.
        $request->session()->forget(
            'source_issue.github_app_preview'
        );

        $connection = GithubAppConnection::query()
            ->where('project_id', $project->id)
            ->first();

        if ($connection === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Koneksi GitHub App belum tersedia untuk project ini.',
            ], 409)->header('Cache-Control', 'no-store');
        }

        try {
            // Installation token dibuat saat diperlukan.
            // Token tidak dikirim kepada browser.
            $githubIssues = $fetchService->fetchIssues(
                $connection
            );

            // Menggunakan validasi dan pemeriksaan duplikat
            // dari Feature 11.
            $preview = $previewService->preview(
                $project,
                $connection->repo_full_name,
                $githubIssues
            );
        } catch (RuntimeException $exception) {
            $apiStatus = $exception->getCode();

            $status = match (true) {
                $apiStatus === 429 => 429,
                $apiStatus >= 500 => 502,
                $apiStatus === 0 => 503,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $status)->header('Cache-Control', 'no-store');
        }

        // Fingerprint digunakan untuk memastikan hasil preview
        // tidak berubah sebelum proses impor dikonfirmasi.
        $fingerprint = hash(
            'sha256',
            json_encode(
                $preview['rows'],
                JSON_THROW_ON_ERROR
            )
        );

        // Session hanya berisi metadata, tanpa access token.
        $request->session()->put(
            'source_issue.github_app_preview',
            [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $user->id,
                'connection_id' => (int) $connection->id,
                'installation_id' =>
                    (int) $connection->installation_id,
                'repository_id' =>
                    (int) $connection->repository_id,
                'repo_full_name' => $connection->repo_full_name,
                'fingerprint' => $fingerprint,
                'expires_at' => now()->addMinutes(30)->timestamp,
            ]
        );

        return response()->json([
            'success' => true,
            'message' =>
                'Pratinjau issue melalui GitHub App berhasil dimuat.',
            'preview' => $preview,
        ])->header('Cache-Control', 'no-store');
    }
}
