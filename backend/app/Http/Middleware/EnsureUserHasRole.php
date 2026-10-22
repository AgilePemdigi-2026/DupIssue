<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(
        Request $request,
        Closure $next,
        string $role
    ): Response {

        // Pastikan pengguna sudah login.
        if (! $request->user()) {
            return redirect()->route('login');
        }

        // Pastikan role pengguna sesuai izin.
        if ($request->user()->role !== $role) {
            abort(403, 'Akses tidak tersedia.');
        }

        return $next($request);
    }
}
