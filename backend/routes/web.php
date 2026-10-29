<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ProjectMemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HALAMAN UTAMA — LANDING DUPISSUE
|--------------------------------------------------------------------------
| Halaman publik; tidak mengubah redirect Login, Register, atau RBAC.
*/

Route::view('/', 'landing')->name('home');


/*
|--------------------------------------------------------------------------
| REGISTER
|--------------------------------------------------------------------------
| Pengguna baru dapat membuat akun DupIssue.
*/

// Menampilkan halaman Register.
Route::view('/register', 'auth.register')
    ->name('register');

// Memproses registrasi.
Route::post('/register', [RegisterController::class, 'store'])
    ->name('register.store');


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
| Login hanya untuk pengguna yang belum terautentikasi.
*/

Route::middleware('guest')->group(function () {

    // Menampilkan halaman Login.
    Route::get('/login', [LoginController::class, 'show'])
        ->name('login');

    // Memproses Login dengan pembatasan percobaan.
    Route::post('/login', [LoginController::class, 'authenticate'])
        ->middleware('throttle:5,1')
        ->name('login.authenticate');

});

Route::post('/logout', [LogoutController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| HALAMAN REPORTER
|--------------------------------------------------------------------------
| Sementara menggunakan placeholder.
| Nantinya diintegrasikan dengan Project & Membership Reza.
*/

Route::middleware(['auth', 'role:REPORTER'])
    ->group(function () {

        Route::get('/reporter/projects', [ProjectMemberController::class, 'index'])
            ->name('reporter.projects.index');

    });


/*
|--------------------------------------------------------------------------
| DASHBOARD MAINTAINER
|--------------------------------------------------------------------------
| Hanya pengguna dengan role MAINTAINER yang diizinkan.
*/

Route::middleware(['auth', 'role:MAINTAINER'])
    ->group(function () {

        Route::get('/maintainer/dashboard', [ProjectMemberController::class, 'index'])
            ->name('maintainer.dashboard');

    });


/*
|--------------------------------------------------------------------------
| REQ-03: Mengelola Project & Anggota (Fakhri Reza)
|--------------------------------------------------------------------------
| Seluruh rute dilindungi verifikasi server-side (REQ-07.3, REQ-03.1 s.d. 03.12).
*/

Route::middleware(['auth'])->group(function () {
    // Project Management
    Route::get('/projects', [ProjectMemberController::class, 'index'])->name('projects.index');
    Route::get('/projects/new', [ProjectMemberController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectMemberController::class, 'store'])->name('projects.store');
    Route::get('/projects/{id}', [ProjectMemberController::class, 'show'])->name('projects.show');
    Route::get('/projects/{id}/settings', [ProjectMemberController::class, 'settings'])->name('projects.settings');
    Route::post('/projects/{id}/settings', [ProjectMemberController::class, 'update'])->name('projects.update');
    Route::put('/projects/{id}/settings', [ProjectMemberController::class, 'update']);

    // Membership & Invitation Management
    Route::get('/projects/{id}/members', [ProjectMemberController::class, 'members'])->name('projects.members');
    Route::post('/projects/{id}/members/invite', [ProjectMemberController::class, 'inviteMember'])->name('projects.members.invite');
    Route::post('/projects/{id}/invitations/{invitationId}/cancel', [ProjectMemberController::class, 'cancelInvitation'])->name('projects.invitations.cancel');
    Route::post('/projects/{id}/members/{userId}/remove', [ProjectMemberController::class, 'removeMember'])->name('projects.members.remove');

    // Reporter Invitations
    Route::get('/invitations', [ProjectMemberController::class, 'reporterInvitations'])->name('invitations.index');
    Route::post('/invitations/{invitationId}/respond', [ProjectMemberController::class, 'respondInvitation'])->name('invitations.respond');
});
