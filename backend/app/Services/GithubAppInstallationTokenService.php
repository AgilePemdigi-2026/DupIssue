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
        return $this->createForRepositoryWithPermission(
            $installationId, $repositoryId, 'read'
        );
    }

    public function createForRepositoryWithWriteAccess(
        int $installationId,
        int $repositoryId
    ): string {
        return $this->createForRepositoryWithPermission(
            $installationId, $repositoryId, 'write'
        );
    }

    private function createForRepositoryWithPermission(
        int $installationId,
        int $repositoryId,
        string $permission
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
                            'issues' => $permission,
                        ],
                    ]
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to GitHub.'
            );
        }

        if ($response->status() === 429
            || ($response->status() === 403
                && ($response->header('X-RateLimit-Remaining') === '0'
                    || trim((string) $response->header('Retry-After')) !== ''))) {
            throw GithubStatusRateLimitException::fromResponse($response);
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

        if ($permission === 'write'
            && is_array($permissions)
            && (array_diff(array_keys($permissions), ['issues', 'metadata'])
                || (isset($permissions['metadata'])
                    && $permissions['metadata'] !== 'read'))) {
            throw new RuntimeException(
                'Unexpected GitHub installation permissions.', 403
            );
        }

        if (
            ! is_array($permissions)
            || ($permissions['issues'] ?? null) !== $permission
        ) {
            throw new RuntimeException(
                $permission === 'read'
                    ? 'GitHub installation lacks issue-read permission.'
                    : 'GitHub installation lacks issue-write permission.',
                $permission === 'read' ? 0 : 403
            );
        }

        return $token;
    }
}
