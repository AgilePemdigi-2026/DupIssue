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
