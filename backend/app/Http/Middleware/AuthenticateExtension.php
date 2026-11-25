<?php

namespace App\Http\Middleware;

use App\Services\ExtensionAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateExtension
{
    public function __construct(
        private readonly ExtensionAuthService $auth
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $token = $request->bearerToken();

        $user = is_string($token)
            ? $this->auth->authenticate($token)
            : null;

        if ($user === null) {
            return response()->json([
                'code' => 'UNAUTHENTICATED',
                'message' => 'Sesi extension tidak valid atau telah berakhir.',
            ], 401, [
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        // Resolve this request's user only.
        // Never create a web login or persistent Laravel session.
        $request->setUserResolver(
            static fn ($guard = null) =>
                $guard === null ? $user : null
        );

        $response = $next($request);

        $response->headers->set(
            'Cache-Control',
            'no-store'
        );

        return $response;
    }
}
