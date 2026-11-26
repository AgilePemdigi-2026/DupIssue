<?php

use App\Http\Controllers\SourceIssueController;
use App\Http\Controllers\GithubAppConnectController;
use App\Http\Controllers\GithubAppIssueController;
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

        // Memulai instalasi GitHub App untuk Maintainer pemilik project.
        Route::post(
            '/projects/{project}/sources/github/app/connect',
            [GithubAppConnectController::class, 'startConnection']
        )
            ->middleware('throttle:5,1')
            ->name('sources.github.app.connect');

        // Setup URL setelah instalasi GitHub App.
        Route::get(
            '/integrations/github/setup',
            [GithubAppConnectController::class, 'handleSetup']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.app.setup');

        // Otorisasi pengguna GitHub dengan OAuth dan PKCE.
        Route::get(
            '/integrations/github/authorize',
            [GithubAppConnectController::class, 'beginAuthorization']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.app.authorize');

        // Callback OAuth GitHub App dengan verifikasi repository.
        Route::get(
            '/integrations/github/callback',
            [GithubAppConnectController::class, 'handleCallback']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.app.callback');

        // Batalkan pemilihan repository tanpa mengubah koneksi aktif.
        Route::post(
            '/projects/{project}/sources/github/app/cancel',
            [GithubAppConnectController::class, 'cancelConnection']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.app.cancel');

        // Simpan repository yang telah diverifikasi melalui OAuth.
        Route::post(
            '/projects/{project}/sources/github/app/save',
            [GithubAppConnectController::class, 'saveConnection']
        )
            ->middleware('throttle:5,1')
            ->name('sources.github.app.save');

        // Preview issue menggunakan GitHub App installation token.
        Route::post(
            '/projects/{project}/sources/github/app/preview',
            [GithubAppIssueController::class, 'preview']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.app.preview');

        // Konfirmasi impor GitHub App setelah preview valid.
        Route::post(
            '/projects/{project}/sources/github/app/import',
            [GithubAppIssueController::class, 'confirmImport']
        )
            ->middleware('throttle:10,1')
            ->name('sources.github.app.import');

    });
