<?php

namespace App\Http\Controllers;

use App\Services\CsvIssueImportService;
use App\Services\GithubApiClient;
use App\Services\GithubIssuePreviewService;
use App\Services\GithubIssueImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use App\Models\Project;
use App\Models\GithubConfig;
use App\Services\CsvIssuePreviewService;
use App\Services\CsvIssueReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SourceIssueController extends Controller
{
    /**
     * Memastikan pengguna adalah Maintainer pemilik project.
     * REQ-04.1
     */
    private function authorizeOwner(
        Request $request,
        Project $project
    ): void {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === 'MAINTAINER'
                && (int) $project->owner_id === (int) $user->id,
            403,
            'Hanya Maintainer pemilik project yang dapat mengelola sumber issue.'
        );
    }

    /**
     * Menampilkan halaman Sumber Issue.
     */
    public function index(Request $request, Project $project): View
    {
        $this->authorizeOwner($request, $project);

        return view('sources.index', [
            'project' => $project,
            'githubRepoFullName' => GithubConfig::query()
                ->where('project_id', $project->id)
                ->value('repo_full_name'),
        ]);
    }

    /**
     * Menguji koneksi repository GitHub tanpa menyimpan PAT.
     * REQ-04.10 dan REQ-04.12.
     */
    public function testGithubConnection(
        Request $request,
        Project $project,
        GithubApiClient $githubClient
    ): JsonResponse {
        $this->authorizeOwner($request, $project);

        $validator = Validator::make(
            $request->only(['repo_full_name', 'pat']),
            [
                'repo_full_name' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'pat' => [
                    'required',
                    'string',
                    'max:512',
                ],
            ]
        );

        // Validasi manual agar PAT tidak tersimpan sebagai
        // old input pada session ketika validasi gagal.
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data koneksi GitHub tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        try {
            $repository = $githubClient->testConnection(
                $validated['repo_full_name'],
                $validated['pat']
            );
        } catch (RuntimeException $exception) {
            $apiStatus = $exception->getCode();

            $status = match (true) {
                $apiStatus === 429 => 429,
                $apiStatus >= 500 => 502,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Koneksi GitHub berhasil diuji.',
            'repository' => $repository,
        ]);
    }

    /**
     * Menyimpan konfigurasi repository dan PAT GitHub.
     * REQ-04.11 dan REQ-04.12.
     */
    public function saveGithubConnection(
        Request $request,
        Project $project,
        GithubApiClient $githubClient
    ): JsonResponse {
        $this->authorizeOwner($request, $project);

        $validator = Validator::make(
            $request->only(['repo_full_name', 'pat']),
            [
                'repo_full_name' => [
                    'required', 'string', 'max:255',
                ],
                'pat' => [
                    'required', 'string', 'max:512',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Data koneksi GitHub tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Uji ulang di server sebelum menyimpan.
        // Konfigurasi lama tidak berubah jika koneksi gagal.
        try {
            $repository = $githubClient->testConnection(
                $validated['repo_full_name'],
                $validated['pat']
            );
        } catch (RuntimeException $exception) {
            $apiStatus = $exception->getCode();

            $status = match (true) {
                $apiStatus === 429 => 429,
                $apiStatus >= 500 => 502,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $status);
        }

        $repoFullName = $repository['repo_full_name'];

        // Serialisasi penyimpanan berdasarkan project untuk
        // mencegah konfigurasi ganda akibat request bersamaan.
        DB::transaction(function () use (
            $project,
            $repoFullName,
            $validated
        ): void {
            Project::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $config = GithubConfig::query()
                ->where('project_id', $project->id)
                ->first();

            if ($config === null) {
                $config = new GithubConfig();
                $config->project_id = $project->id;
            }

            $repositoryChanged = $config->exists
                && $config->repo_full_name !== $repoFullName;

            $config->repo_full_name = $repoFullName;

            // Eloquent encrypted cast mengenkripsi PAT.
            // Jangan mengenkripsi lagi secara manual.
            $config->pat_encrypted = $validated['pat'];

            if ($repositoryChanged) {
                $config->last_synced_at = null;
            }

            $config->save();
        });

        // Batalkan preview lama setelah koneksi berhasil disimpan.
        // Preview harus dibuat ulang menggunakan konfigurasi terbaru.
        $request->session()->forget(
            'source_issue.github_preview'
        );

        // PAT tidak dikembalikan kepada browser.
        return response()->json([
            'success' => true,
            'message' => 'Koneksi GitHub berhasil disimpan.',
            'repository' => [
                'repo_full_name' => $repoFullName,
                'private' => $repository['private'],
            ],
        ]);
    }

    /**
     * Mengambil dan memvalidasi issue GitHub untuk pratinjau.
     * Tidak menyimpan issue ke database.
     *
     * REQ-04.13 sampai REQ-04.15.
     */
    public function previewGithubIssues(
        Request $request,
        Project $project,
        GithubApiClient $githubClient,
        GithubIssuePreviewService $previewService
    ): JsonResponse {
        $this->authorizeOwner($request, $project);

        // Batalkan metadata preview sebelumnya.
        $request->session()->forget(
            'source_issue.github_preview'
        );

        // Gunakan hanya konfigurasi milik project aktif.
        $config = GithubConfig::query()
            ->where('project_id', $project->id)
            ->first();

        if ($config === null) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Koneksi GitHub belum disimpan untuk project ini.',
            ], 409);
        }

        try {
            // PAT dibaca melalui Eloquent encrypted cast.
            // Token tidak dikembalikan kepada browser.
            $githubIssues = $githubClient->fetchIssues(
                $config->repo_full_name,
                $config->pat_encrypted
            );

            $preview = $previewService->preview(
                $project,
                $config->repo_full_name,
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
            ], $status);
        }

        // Fingerprint untuk verifikasi ulang saat konfirmasi.
        // Tidak menyimpan seluruh issue atau PAT di session.
        $fingerprint = hash(
            'sha256',
            json_encode(
                $preview['rows'],
                JSON_THROW_ON_ERROR
            )
        );

        $request->session()->put(
            'source_issue.github_preview',
            [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $request->user()->id,
                'config_id' => (int) $config->id,
                'repo_full_name' => $config->repo_full_name,
                'fingerprint' => $fingerprint,
                'expires_at' => now()->addMinutes(30)->timestamp,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Pratinjau issue GitHub berhasil dimuat.',
            'preview' => $preview,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Validasi dan pratinjau CSV tanpa menyimpan issue.
     * REQ-04.3 sampai REQ-04.6
     */
    public function preview(
        Request $request,
        Project $project,
        CsvIssueReader $reader,
        CsvIssuePreviewService $previewService
    ): View {
        $this->authorizeOwner($request, $project);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $file = $validated['file'];

        // Validasi format, isi, dan jumlah baris CSV.
        $parsed = $reader->read($file);

        // Periksa setiap baris dan identitas yang sudah diimpor.
        // Belum ada INSERT issue pada tahap ini.
        $result = $previewService->preview($project, $parsed);

        // Hash file untuk pemeriksaan integritas saat konfirmasi.
        $fileHash = hash_file('sha256', $file->getPathname());

        if ($fileHash === false) {
            throw ValidationException::withMessages([
                'file' => 'Gagal membaca file CSV.',
            ]);
        }

        // Nama file server tidak menggunakan nama dari browser.
        $path = $file->storeAs(
            'source-issue-previews',
            (string) Str::uuid() . '.csv',
            'local'
        );

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'Gagal menyimpan file CSV sementara.',
            ]);
        }

        // Satu pratinjau CSV aktif per session.
        $previous = $request->session()->get(
            'source_issue.csv_preview'
        );

        $request->session()->put(
            'source_issue.csv_preview',
            [
                'project_id' => (int) $project->id,
                'actor_id' => (int) $request->user()->id,
                'path' => $path,
                'original_name' => mb_substr(
                    basename($file->getClientOriginalName()),
                    0,
                    255
                ),
                'sha256' => $fileHash,
                'expires_at' => now()->addMinutes(30)->timestamp,
            ]
        );

        // Hapus file pratinjau sebelumnya pada session ini.
        if (
            is_array($previous)
            && is_string($previous['path'] ?? null)
            && str_starts_with(
                $previous['path'],
                'source-issue-previews/'
            )
        ) {
            Storage::disk('local')->delete($previous['path']);
        }

        return view('sources.index', [
            'project' => $project,
            'csvPreview' => $result,
            'csvOriginalFileName' => $file->getClientOriginalName(),
        ]);
    }


    /**
     * Endpoint konfirmasi impor CSV.
     * Jika validasi gagal, kembali ke halaman GET Sumber Issue.
     */
    public function importCsv(
        Request $request,
        Project $project,
        CsvIssueReader $reader,
        CsvIssueImportService $importService
    ): RedirectResponse {
        try {
            return $this->performCsvImport(
                $request,
                $project,
                $reader,
                $importService
            );
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(
                route('sources.index', [
                    'project' => $project->id,
                ])
            );
        }
    }



    /**
     * Konfirmasi impor CSV dari file sementara di server.
     * File dibaca ulang; data preview dari browser tidak dipercaya.
     * REQ-04.5 sampai REQ-04.9
     */
    private function performCsvImport(
        Request $request,
        Project $project,
        CsvIssueReader $reader,
        CsvIssueImportService $importService
    ): RedirectResponse {
        $this->authorizeOwner($request, $project);

        $pending = $request->session()->get(
            'source_issue.csv_preview'
        );

        if (!is_array($pending)) {
            throw ValidationException::withMessages([
                'file' => 'Belum ada file CSV yang divalidasi.',
            ]);
        }

        // Pastikan preview dibuat oleh pengguna yang sama
        // dan untuk project yang sama.
        if (
            (int) ($pending['project_id'] ?? 0) !== (int) $project->id
            || (int) ($pending['actor_id'] ?? 0)
                !== (int) $request->user()->id
        ) {
            throw ValidationException::withMessages([
                'file' => 'Pratinjau CSV tidak sesuai dengan pengguna atau project.',
            ]);
        }

        $path = $pending['path'] ?? null;
        $expectedHash = $pending['sha256'] ?? null;
        $originalName = $pending['original_name'] ?? null;

        // Hanya terima lokasi file sementara yang dibuat sistem.
        if (
            !is_string($path)
            || !preg_match(
                '/\Asource-issue-previews\/[0-9a-f-]{36}\.csv\z/',
                $path
            )
            || !is_string($expectedHash)
            || !preg_match('/\A[a-f0-9]{64}\z/', $expectedHash)
            || !is_string($originalName)
            || $originalName === ''
        ) {
            $request->session()->forget(
                'source_issue.csv_preview'
            );

            throw ValidationException::withMessages([
                'file' => 'Referensi pratinjau CSV tidak valid.',
            ]);
        }

        $disk = Storage::disk('local');

        // Pratinjau hanya berlaku selama 30 menit.
        if (
            (int) ($pending['expires_at'] ?? 0)
                <= now()->timestamp
        ) {
            $request->session()->forget(
                'source_issue.csv_preview'
            );

            $disk->delete($path);

            throw ValidationException::withMessages([
                'file' => 'Pratinjau CSV telah kedaluwarsa. Upload ulang file.',
            ]);
        }

        if (!$disk->exists($path)) {
            $request->session()->forget(
                'source_issue.csv_preview'
            );

            throw ValidationException::withMessages([
                'file' => 'File CSV sementara tidak ditemukan. Upload ulang file.',
            ]);
        }

        $absolutePath = $disk->path($path);
        $actualHash = hash_file('sha256', $absolutePath);

        // File yang berubah setelah preview tidak boleh diimpor.
        if (
            !is_string($actualHash)
            || !hash_equals($expectedHash, $actualHash)
        ) {
            $request->session()->forget(
                'source_issue.csv_preview'
            );

            $disk->delete($path);

            throw ValidationException::withMessages([
                'file' => 'Isi file CSV berubah setelah validasi. Upload ulang file.',
            ]);
        }

        // Bentuk UploadedFile dari salinan server.
        // Parameter terakhir true karena ini file lokal,
        // bukan upload HTTP baru.
        $file = new UploadedFile(
            $absolutePath,
            $originalName,
            'text/csv',
            null,
            true
        );

        // Jalankan kembali seluruh pemeriksaan CSV.
        $parsed = $reader->read($file);

        // Service memeriksa ulang data duplikat dalam transaksi.
        // Jika gagal, perubahan database di-rollback.
        $result = $importService->import(
            $project,
            $request->user(),
            $parsed,
            $originalName
        );

        // Cleanup hanya setelah impor berhasil.
        $request->session()->forget(
            'source_issue.csv_preview'
        );

        $disk->delete($path);


        return redirect()
            ->route('sources.index', [
                'project' => $project->id,
            ])
            ->with(
                'success',
                'Impor CSV berhasil: '
                . $result['imported_count']
                . ' issue baru disimpan. '
                . $result['invalid_count']
                . ' invalid dan '
                . $result['already_imported_count']
                . ' sudah pernah diimpor.'
            )
            ->with('csv_import_result', [
                'batch_id' => $result['batch_id'],
                'imported_count' => $result['imported_count'],
                'invalid_count' => $result['invalid_count'],
                'already_imported_count' =>
                    $result['already_imported_count'],
            ]);
    }


    /**
     * Confirm import of GitHub issues previously previewed.
     *
     * GitHub is fetched again before the database transaction.
     * The import service verifies the preview fingerprint
     * inside the transaction.
     */
    public function confirmGithubImport(
        Request $request,
        Project $project,
        GithubApiClient $githubClient,
        GithubIssueImportService $importService
    ): JsonResponse {
        $this->authorizeOwner($request, $project);

        // Require an explicit confirmation from the user.
        $request->validate([
            'confirmed' => ['required', 'accepted'],
        ]);

        $pending = $request->session()->get(
            'source_issue.github_preview'
        );

        // Verify the session belongs to this actor and project.
        // Expired or malformed previews cannot be confirmed.
        if (
            ! is_array($pending)
            || ! is_int($pending['project_id'] ?? null)
            || ! is_int($pending['actor_id'] ?? null)
            || ! is_int($pending['config_id'] ?? null)
            || ! is_string($pending['repo_full_name'] ?? null)
            || ! is_string($pending['fingerprint'] ?? null)
            || ! is_int($pending['expires_at'] ?? null)
            || $pending['project_id'] !== (int) $project->id
            || $pending['actor_id'] !== (int) $request->user()->id
            || $pending['expires_at'] <= now()->timestamp
        ) {
            $request->session()->forget(
                'source_issue.github_preview'
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Pratinjau GitHub tidak tersedia atau sudah kedaluwarsa. '
                    . 'Ambil ulang issue sebelum mengimpor.',
            ], 409);
        }

        // Check that the saved connection has not changed.
        $config = GithubConfig::query()
            ->where('project_id', $project->id)
            ->first();

        if (
            $config === null
            || (int) $config->id !== $pending['config_id']
            || $config->repo_full_name !== $pending['repo_full_name']
        ) {
            $request->session()->forget(
                'source_issue.github_preview'
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'Konfigurasi GitHub berubah. '
                    . 'Ambil ulang pratinjau sebelum mengimpor.',
            ], 409);
        }

        try {
            // Fetch all issues again, outside the DB transaction.
            // PAT is never sent back to the browser.
            $githubIssues = $githubClient->fetchIssues(
                $config->repo_full_name,
                $config->pat_encrypted
            );
        } catch (\RuntimeException $exception) {
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

        // The service rechecks authorization, configuration,
        // fingerprint, and existing issues under DB locking.
        // Laravel handles ValidationException as HTTP 422.
        $result = $importService->import(
            $project,
            $request->user(),
            $pending['config_id'],
            $pending['repo_full_name'],
            $githubIssues,
            $pending['fingerprint']
        );

        // Successful confirmation consumes the preview.
        // A new import requires a new preview.
        $request->session()->forget(
            'source_issue.github_preview'
        );

        return response()->json([
            'success' => true,
            'message' => 'Issue GitHub berhasil diimpor.',
            'result' => $result,
        ])->header('Cache-Control', 'no-store');
    }

}
