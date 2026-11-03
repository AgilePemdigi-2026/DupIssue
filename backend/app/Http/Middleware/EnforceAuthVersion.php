<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invalidate authenticated browser sessions after password reset, regardless
 * of SESSION_DRIVER (database, file, Redis, etc.).
 */
class EnforceAuthVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user) {
            return $next($request);
        }

        $version = (int) $user->auth_version;
        $stored = $request->session()->get('auth_version');

        // A valid remember-me login creates a fresh session. The remember token
        // is rotated on reset, so a revoked cookie cannot reach this path.
        if ($stored === null && Auth::guard('web')->viaRemember()) {
            $request->session()->put('auth_version', $version);
            return $next($request);
        }

        // Laravel's actingAs() uses a transient guard, not a real login session.
        // Do not weaken live sessions: this exemption is test-only and applies
        // ONLY when there is no persisted web guard login identifier.
        if ($stored === null && app()->runningUnitTests()
            && ! $request->session()->has(Auth::guard('web')->getName())) {
            return $next($request);
        }

        if (! is_int($stored) || $stored !== $version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'Sesi berakhir setelah perubahan password. Silakan login kembali.']);
        }

        return $next($request);
    }
}
