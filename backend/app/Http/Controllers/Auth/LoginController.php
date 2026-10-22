<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    // Menampilkan halaman Login.
    public function show(): View
    {
        return view('auth.login');
    }

    // Memproses autentikasi pengguna.
    public function authenticate(Request $request): RedirectResponse
    {
        // Validasi input Login.
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Memeriksa kredensial pada database.
        if (! Auth::attempt(
            $credentials,
            $request->boolean('remember')
        )) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi salah.',
            ]);
        }

        // Mencegah session fixation.
        $request->session()->regenerate();

        // Mengarahkan pengguna sesuai role.
        return match (Auth::user()->role) {
            'REPORTER' => redirect()
                ->route('reporter.projects.index'),

            'MAINTAINER' => redirect()
                ->route('maintainer.dashboard'),

            default => abort(403, 'Akses tidak tersedia.'),
        };
    }
}
