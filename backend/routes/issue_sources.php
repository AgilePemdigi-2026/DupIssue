<?php

use App\Http\Controllers\SourceIssueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FEATURE 11 — MENGELOLA SUMBER ISSUE
|--------------------------------------------------------------------------
| Sprint 3 — Kelompok 4
|
| Akses hanya untuk Maintainer.
| Kepemilikan project diperiksa kembali di SourceIssueController.
|
| REQ-04.1 sampai REQ-04.9: Upload, Preview, dan Import CSV.
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:MAINTAINER'])
    ->group(function (): void {

        // Halaman Sumber Issue.
        Route::get(
            '/projects/{project}/sources',
            [SourceIssueController::class, 'index']
        )->name('sources.index');

        // Upload dan validasi CSV tanpa menyimpan issue.
        Route::post(
            '/projects/{project}/sources/csv/preview',
            [SourceIssueController::class, 'preview']
        )
            ->middleware('throttle:20,1')
            ->name('sources.csv.preview');

        // Konfirmasi impor CSV.
        Route::post(
            '/projects/{project}/sources/csv/import',
            [SourceIssueController::class, 'importCsv']
        )
            ->middleware('throttle:10,1')
            ->name('sources.csv.import');

        // Uji koneksi GitHub tanpa menyimpan konfigurasi.
        // REQ-04.10 dan REQ-04.12.
        Route::post(
            '/projects/{project}/sources/github/test',
            [SourceIssueController::class, 'testGithubConnection']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.test');

        // Simpan konfigurasi repository dan PAT terenkripsi.
        // REQ-04.11 dan REQ-04.12.
        Route::post(
            '/projects/{project}/sources/github/save',
            [SourceIssueController::class, 'saveGithubConnection']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.save');

        // Ambil issue GitHub dan tampilkan pratinjau.
        // Tidak menyimpan issue sebelum konfirmasi impor.
        // REQ-04.13 sampai REQ-04.15.
        Route::post(
            '/projects/{project}/sources/github/preview',
            [SourceIssueController::class, 'previewGithubIssues']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.preview');

        // Konfirmasi impor issue GitHub setelah preview.
        // Memerlukan confirmed=true dan session preview valid.
        Route::post(
            '/projects/{project}/sources/github/import',
            [SourceIssueController::class, 'confirmGithubImport']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.import');

    });
