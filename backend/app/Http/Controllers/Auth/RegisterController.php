<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // Validasi data registrasi.
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
            ],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        // Simpan pengguna baru ke database.
        User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
        ]);

        // Kembali ke halaman registrasi.
        return redirect()
            ->route('register')
            ->with(
                'success',
                'Registrasi berhasil! Akun Anda telah dibuat.'
            );
    }
}
