
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;

/*
|--------------------------------------------------------------------------
| HALAMAN UTAMA
|--------------------------------------------------------------------------
| Sementara menggunakan halaman bawaan Laravel.
*/

Route::get('/', function () {
    return view('welcome');
})->name('home');


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


/*
|--------------------------------------------------------------------------
| HALAMAN REPORTER
|--------------------------------------------------------------------------
| Sementara menggunakan placeholder.
| Nantinya diintegrasikan dengan Project & Membership Reza.
*/

Route::middleware(['auth', 'role:REPORTER'])
    ->group(function () {

        Route::get('/reporter/projects', function () {
            return view('auth.role-landing', [
                'role' => 'REPORTER',
            ]);
        })->name('reporter.projects.index');

    });


/*
|--------------------------------------------------------------------------
| DASHBOARD MAINTAINER
|--------------------------------------------------------------------------
| Hanya pengguna dengan role MAINTAINER yang diizinkan.
*/

Route::middleware(['auth', 'role:MAINTAINER'])
    ->group(function () {

        Route::get('/maintainer/dashboard', function () {
            return view('auth.role-landing', [
                'role' => 'MAINTAINER',
            ]);
        })->name('maintainer.dashboard');

    });
