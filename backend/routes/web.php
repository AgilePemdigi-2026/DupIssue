<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\DuplicateReviewController;
use App\Http\Controllers\IssueStatusController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HALAMAN UTAMA — LANDING DUPISSUE
|--------------------------------------------------------------------------
*/

Route::view('/', 'landing')->name('home');


/*
|--------------------------------------------------------------------------
| REGISTER
|--------------------------------------------------------------------------
*/

Route::view('/register', 'auth.register')
    ->name('register');

Route::post('/register', [RegisterController::class, 'store'])
    ->name('register.store');


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

Route::middleware('guest')->group(function () {

    Route::get('/login', [LoginController::class, 'show'])
        ->name('login');

    Route::post('/login', [LoginController::class, 'authenticate'])
        ->middleware('throttle:5,1')
        ->name('login.authenticate');

});


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

Route::post('/logout', [LogoutController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');


/*
|--------------------------------------------------------------------------
| HALAMAN REPORTER
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:REPORTER'])->group(function () {

    Route::get('/reporter/projects', [ProjectMemberController::class, 'index'])
        ->name('reporter.projects.index');

});


/*
|--------------------------------------------------------------------------
| DASHBOARD MAINTAINER
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'role:MAINTAINER'])->group(function () {

    Route::get('/maintainer/dashboard', [ProjectMemberController::class, 'index'])
        ->name('maintainer.dashboard');

});


/*
|--------------------------------------------------------------------------
| ISSUE MANAGEMENT
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    // Submit Issue
    Route::get('/projects/{project}/submit', [IssueController::class, 'create'])
        ->middleware('role:REPORTER')
        ->name('issues.create');

    Route::post('/projects/{project}/submit', [IssueController::class, 'store'])
        ->middleware('role:REPORTER')
        ->name('issues.store');

    // Issue List
    Route::get('/projects/{project}/issues', [IssueController::class, 'index'])
        ->name('issues.index');

    // Issue Detail
    Route::get('/projects/{project}/issues/{issue}', [IssueController::class, 'show'])
        ->name('issues.show');


    /*
    |--------------------------------------------------------------------------
    | FEATURE 9 — VOTE ISSUE
    |--------------------------------------------------------------------------
    */

    // Memberikan vote
    Route::post(
        '/projects/{project}/issues/{issue}/vote',
        [VoteController::class, 'store']
    )->name('issues.vote');

    // Membatalkan vote
    Route::delete(
        '/projects/{project}/issues/{issue}/vote',
        [VoteController::class, 'destroy']
    )->name('issues.unvote');


    /*
    |--------------------------------------------------------------------------
    | DUPLICATE REVIEW
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/projects/{project}/issues/{issue}/candidates/{candidate}/decision',
        [DuplicateReviewController::class, 'decide']
    )
        ->middleware('role:MAINTAINER')
        ->name('issues.duplicates.decide');

    Route::post(
        '/projects/{project}/issues/{issue}/duplicate/cancel',
        [DuplicateReviewController::class, 'cancel']
    )
        ->middleware('role:MAINTAINER')
        ->name('issues.duplicates.cancel');


    /*
    |--------------------------------------------------------------------------
    | ISSUE STATUS
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/projects/{project}/issues/{issue}/status/preview',
        [IssueStatusController::class, 'preview']
    )
        ->middleware('role:MAINTAINER')
        ->name('issues.status.preview');

    Route::post(
        '/projects/{project}/issues/{issue}/status/confirm',
        [IssueStatusController::class, 'confirm']
    )
        ->middleware('role:MAINTAINER')
        ->name('issues.status.confirm');

});


/*
|--------------------------------------------------------------------------
| REQ-03: MENGELOLA PROJECT & ANGGOTA (FAKHRI REZA)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {

    // Project Management
    Route::get('/projects', [ProjectMemberController::class, 'index'])
        ->name('projects.index');

    Route::get('/projects/new', [ProjectMemberController::class, 'create'])
        ->name('projects.create');

    Route::post('/projects', [ProjectMemberController::class, 'store'])
        ->name('projects.store');

    Route::get('/projects/{id}', [ProjectMemberController::class, 'show'])
        ->name('projects.show');

    Route::get('/projects/{id}/settings', [ProjectMemberController::class, 'settings'])
        ->name('projects.settings');

    Route::post('/projects/{id}/settings', [ProjectMemberController::class, 'update'])
        ->name('projects.update');

    Route::put('/projects/{id}/settings', [ProjectMemberController::class, 'update']);


    // Membership & Invitation Management
    Route::get('/projects/{id}/members', [ProjectMemberController::class, 'members'])
        ->name('projects.members');

    Route::post('/projects/{id}/members/invite', [ProjectMemberController::class, 'inviteMember'])
        ->name('projects.members.invite');

    Route::post('/projects/{id}/invitations/{invitationId}/cancel', [ProjectMemberController::class, 'cancelInvitation'])
        ->name('projects.invitations.cancel');

    Route::post('/projects/{id}/members/{userId}/remove', [ProjectMemberController::class, 'removeMember'])
        ->name('projects.members.remove');


    // Reporter Invitations
    Route::get('/invitations', [ProjectMemberController::class, 'reporterInvitations'])
        ->name('invitations.index');

    Route::post('/invitations/{invitationId}/respond', [ProjectMemberController::class, 'respondInvitation'])
        ->name('invitations.respond');

});
