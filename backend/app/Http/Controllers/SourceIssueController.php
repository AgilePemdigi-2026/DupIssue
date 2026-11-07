<?php

namespace App\Http\Controllers;

use App\Services\CsvIssueImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use App\Models\Project;
use App\Services\CsvIssuePreviewService;
use App\Services\CsvIssueReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        ]);
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
     * Konfirmasi impor CSV dari file sementara di server.
     * File dibaca ulang; data preview dari browser tidak dipercaya.
     * REQ-04.5 sampai REQ-04.9
     */
    public function importCsv(
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

        return back()
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

}
