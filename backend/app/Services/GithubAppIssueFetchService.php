<?php

namespace App\Services;

use App\Models\GithubAppConnection;
use RuntimeException;

class GithubAppIssueFetchService
{
    public function __construct(
        private readonly GithubAppInstallationTokenService $tokenService,
        private readonly GithubApiClient $githubClient
    ) {
    }

    /**
     * Fetch issues using a short-lived GitHub App installation token.
     *
     * The caller must authorize access to the connected project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchIssues(
        GithubAppConnection $connection
    ): array {
        if (
            ! $connection->exists
            || (int) $connection->project_id <= 0
            || (int) $connection->installation_id <= 0
            || (int) $connection->repository_id <= 0
            || $connection->verified_at === null
            || ! is_string($connection->repo_full_name)
            || trim($connection->repo_full_name) === ''
        ) {
            throw new RuntimeException(
                'GitHub App connection is incomplete.'
            );
        }

        // Installation token dibatasi ke repository ID tersimpan.
        // Tidak ada PAT atau token permanen dalam database.
        $token = $this->tokenService->createForRepository(
            (int) $connection->installation_id,
            (int) $connection->repository_id
        );

        // Gunakan kembali pagination, validasi respons,
        // dan filtering pull request milik Feature 11.
        return $this->githubClient->fetchIssues(
            $connection->repo_full_name,
            $token
        );
    }
}
