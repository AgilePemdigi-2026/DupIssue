<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\IssueController;

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

Route::middleware('auth')->group(function () {
    Route::get('/projects/{project}/submit', [IssueController::class, 'create'])
        ->name('issues.create');
    Route::post('/projects/{project}/submit', [IssueController::class, 'store'])
        ->name('issues.store');
    Route::get('/projects/{project}/issues/{issue}', [IssueController::class, 'show'])
        ->name('issues.show');
});
