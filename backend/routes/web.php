<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;

// Halaman utama Laravel.
Route::get('/', function () {
    return view('welcome');
});

// Menampilkan halaman registrasi.
Route::view('/register', 'auth.register')
    ->name('register');

// Memproses data registrasi.
Route::post('/register', [RegisterController::class, 'store'])
    ->name('register.store');

/*
|--------------------------------------------------------------------------
| REQ-03: Mengelola Project & Anggota (Fakhri Reza)
|--------------------------------------------------------------------------
| Seluruh rute dilindungi verifikasi server-side (REQ-07.3, REQ-03.1 s.d. 03.12).
*/
use App\Http\Controllers\ProjectMemberController;

Route::middleware(['web'])->group(function () {
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
