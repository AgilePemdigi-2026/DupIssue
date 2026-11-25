<?php

namespace App\Http\Controllers;

use App\Services\ExtensionAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ExtensionAuthController extends Controller
{
    public function __construct(
        private readonly ExtensionAuthService $auth
    ) {
    }

    public function show(): Response
    {
        return $this->pairingPage();
    }

    public function createPairing(
        Request $request
    ): JsonResponse|Response {
        $actor = $request->user('web');

        abort_unless($actor !== null, 401);

        $code = $this->auth
            ->createPairingCode($actor);

        // Browser form submissions receive a protected HTML
        // page. Existing JSON callers keep the same response.
        if (! $request->expectsJson()) {
            return $this->pairingPage($code, 201);
        }

        return $this->json([
            'pairing_code' => $code,
            'expires_in' =>
                ExtensionAuthService::PAIRING_TTL_MINUTES * 60,
        ], 201);
    }

    public function exchange(
        Request $request
    ): JsonResponse {
        // Credentials must come from a JSON body,
        // never from query string parameters.
        $code = $request->isJson()
            ? $request->json('pairing_code')
            : null;

        $result = is_string($code)
            ? $this->auth->exchange($code)
            : null;

        if ($result === null) {
            return $this->json([
                'code' => 'INVALID_PAIRING_CODE',
                'message' => 'Kode pairing tidak valid atau telah berakhir.',
            ], 401);
        }

        return $this->json([
            'access_token' => $result['access_token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at'],
        ]);
    }

    public function me(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        return $this->json([
            'user' => [
                'id' => (int) $user->getKey(),
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }

    public function disconnect(
        Request $request
    ): JsonResponse {
        $this->auth->revoke(
            (string) $request->bearerToken()
        );

        return $this->json([
            'disconnected' => true,
        ]);
    }

    public function revokeOwned(Request $request, int $session): \Illuminate\Http\RedirectResponse
    {
        $owned = DB::table('extension_sessions')->where('id', $session)
            ->where('user_id', $request->user('web')->id)->first(['id']);
        abort_unless($owned !== null, 404);
        DB::table('extension_sessions')->where('id', $owned->id)
            ->where('user_id', $request->user('web')->id)->update([
                'revoked_at' => now(), 'pairing_hash' => null, 'access_hash' => null,
                'updated_at' => now(),
            ]);

        return redirect()->route('extension.connect')->withHeaders(['Cache-Control' => 'no-store']);
    }

    private function pairingPage(
        ?string $code = null,
        int $status = 200
    ): Response {
        return response()->view(
            'extension.connect',
            [
                'pairingCode' => $code,
                'extensionSessions' => DB::table('extension_sessions')
                    ->where('user_id', request()->user('web')->id)
                    ->whereNull('revoked_at')->orderByDesc('id')->limit(100)
                    ->get(['id', 'created_at', 'paired_at', 'access_expires_at']),
                'expiresInMinutes' =>
                    ExtensionAuthService::PAIRING_TTL_MINUTES,
            ],
            $status,
            [
                'Cache-Control' => 'no-store, private',
                'Pragma' => 'no-cache',
                'Referrer-Policy' => 'no-referrer',
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' =>
                    "default-src 'none'; ".
                    "style-src 'self' 'unsafe-inline'; ".
                    "img-src 'self'; ".
                    "form-action 'self'; ".
                    "base-uri 'none'; ".
                    "frame-ancestors 'none'",
            ]
        );
    }

    private function json(
        array $data,
        int $status = 200
    ): JsonResponse {
        return response()->json($data, $status, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
