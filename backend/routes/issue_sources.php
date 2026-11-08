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

    });
