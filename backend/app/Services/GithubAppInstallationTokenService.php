<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GithubAppInstallationTokenService
{
    private const BASE_URL = 'https://api.github.com';

    private const API_VERSION = '2026-03-10';

    public function __construct(
        private readonly GithubAppJwtService $jwtService
    ) {
    }

    /**
     * Create a short-lived installation token scoped
     * to exactly one authorized repository.
     *
     * Never return this token to the browser or logs.
     */
    public function createForRepository(
        int $installationId,
        int $repositoryId
    ): string {
        if ($installationId <= 0 || $repositoryId <= 0) {
            throw new RuntimeException(
                'Invalid GitHub installation or repository.'
            );
        }

        $jwt = $this->jwtService->generate();

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->accept('application/vnd.github+json')
                ->asJson()
                ->withToken($jwt)
                ->withHeaders([
                    'X-GitHub-Api-Version' => self::API_VERSION,
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->post(
                    "/app/installations/{$installationId}/access_tokens",
                    [
                        'repository_ids' => [$repositoryId],
                        'permissions' => [
                            'issues' => 'read',
                        ],
                    ]
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to GitHub.'
            );
        }

        if ($response->status() !== 201) {
            throw new RuntimeException(
                'GitHub installation token request failed.',
                $response->status()
            );
        }

        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');
        $permissions = $response->json('permissions');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(
                'GitHub returned an invalid installation token.'
            );
        }

        $expiry = is_string($expiresAt)
            ? strtotime($expiresAt)
            : false;

        if ($expiry === false || $expiry <= time()) {
            throw new RuntimeException(
                'GitHub installation token expiration is invalid.'
            );
        }

        if (
            ! is_array($permissions)
            || ($permissions['issues'] ?? null) !== 'read'
        ) {
            throw new RuntimeException(
                'GitHub installation lacks issue-read permission.'
            );
        }

        return $token;
    }
}
