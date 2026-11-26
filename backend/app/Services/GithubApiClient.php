<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GithubApiClient
{
    private const BASE_URL = 'https://api.github.com';

    private const API_VERSION = '2026-03-10';

    /**
     * Verify repository and issue identity before changing state.
     * No title, body, label or other fields may be written.
     */
    public function updateVerifiedIssueState(
        string $repository,
        int $repositoryId,
        int $number,
        string $desiredState,
        string $accessToken,
        ?callable $guard = null
    ): array {
        if (! in_array($desiredState, ['open', 'closed'], true)
            || $number <= 0 || $number > 4294967295
            || $repositoryId <= 0
            || trim($accessToken) === '') {
            throw new RuntimeException(
                'Invalid GitHub status update context.', 422
            );
        }

        $current = $this->fetchVerifiedIssue($repository, $repositoryId, $number, $accessToken);

        if (! is_int($current['id'] ?? null)
            || $current['id'] <= 0
            || array_key_exists('pull_request', $current)) {
            throw new RuntimeException(
                'Invalid GitHub issue identity.', 422
            );
        }

        if ($guard !== null) {
            $guard();
        }

        // Reconcile before retrying an uncertain previous write.
        if ($current['state'] === $desiredState) {
            return $current;
        }

        $path = $this->repositoryPath($repository);

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->accept('application/vnd.github+json')
                ->asJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'X-GitHub-Api-Version' => self::API_VERSION,
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->patch($path.'/issues/'.$number, [
                    'state' => $desiredState,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'GitHub status update could not be confirmed.', 503
            );
        }

        if (! $response->successful()) {
            $this->handleError($response);
        }

        $updated = $this->decodeObject($response);

        if ($response->status() !== 200
            || ($updated['id'] ?? null) !== $current['id']
            || ($updated['number'] ?? null) !== $number
            || ($updated['repository_url'] ?? null) !== self::BASE_URL.$path
            || ($updated['state'] ?? null) !== $desiredState
            || array_key_exists('pull_request', $updated)) {
            throw new RuntimeException(
                'GitHub status update acknowledgement is invalid.', 502
            );
        }

        return $updated;
    }


    public function fetchVerifiedIssue(string $repository, int $repositoryId, int $number, string $token): array
    {
        if ($repositoryId <= 0 || $number <= 0) {
            throw new RuntimeException('Invalid GitHub identity.', 422);
        }
        $path = $this->repositoryPath($repository);
        $metadata = $this->decodeObject($this->request($path, $token));
        if (($metadata['id'] ?? null) !== $repositoryId
            || ! is_string($metadata['full_name'] ?? null)
            || strcasecmp($metadata['full_name'], $repository) !== 0) {
            throw new RuntimeException('GitHub repository identity changed. Reconnect repository.', 409);
        }
        $issue = $this->decodeObject($this->request($path.'/issues/'.$number, $token));
        if (($issue['number'] ?? null) !== $number || isset($issue['pull_request'])
            || ($issue['repository_url'] ?? null) !== self::BASE_URL.$path
            || ! in_array($issue['state'] ?? null, ['open', 'closed'], true)) {
            throw new RuntimeException('Invalid GitHub issue identity.', 422);
        }

        return $issue;
    }

    /**
     * Verify repository access and issue-read permission.
     *
     * @return array<string, mixed>
     */
    public function testConnection(string $repoFullName, string $token): array
    {
        $path = $this->repositoryPath($repoFullName);

        $response = $this->request($path, $token);
        $repository = $this->decodeObject($response);

        if (! isset($repository['full_name'])
            || ! is_string($repository['full_name'])) {
            throw new RuntimeException(
                'GitHub returned invalid repository information.'
            );
        }

        if (($repository['has_issues'] ?? true) === false) {
            throw new RuntimeException(
                'GitHub Issues is disabled for this repository.'
            );
        }

        // Repository metadata access does not prove issue-read access.
        $issuesResponse = $this->request(
            $path.'/issues',
            $token,
            [
                'state' => 'all',
                'per_page' => 1,
                'page' => 1,
            ]
        );

        $this->decodeList($issuesResponse);

        return [
            'repo_full_name' => $repository['full_name'],
            'private' => (bool) ($repository['private'] ?? false),
            'has_issues' => true,
        ];
    }

    /**
     * Fetch all repository issues, excluding pull requests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchIssues(string $repoFullName, string $token): array
    {
        $path = $this->repositoryPath($repoFullName).'/issues';

        $issues = [];
        $page = 1;

        do {
            $response = $this->request($path, $token, [
                'state' => 'all',
                'per_page' => 100,
                'page' => $page,
            ]);

            $items = $this->decodeList($response);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException(
                        'GitHub returned an invalid issue item.'
                    );
                }

                // GitHub Issues API also returns pull requests.
                if (array_key_exists('pull_request', $item)) {
                    continue;
                }

                if (! isset($item['number'], $item['title'])
                    || ! is_int($item['number'])
                    || ! is_string($item['title'])) {
                    throw new RuntimeException(
                        'GitHub returned incomplete issue information.'
                    );
                }

                $issues[] = $item;
            }

            $linkHeader = (string) $response->header('Link', '');
            $hasNextPage = str_contains($linkHeader, 'rel="next"');

            if ($hasNextPage && $page >= 5) {
                throw new RuntimeException(
                    'GitHub repository exceeds the limit of '
                    .'500 API items (5 pages) per operation. '
                    .'The issue list was not imported. '
                    .'Please use a smaller repository.',
                    422
                );
            }

            $page++;
        } while ($hasNextPage);

        return $issues;
    }

    /**
     * Build a safe repository API path.
     */
    private function repositoryPath(string $repoFullName): string
    {
        $repoFullName = trim($repoFullName);

        if (! preg_match(
            '~\A[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9._-]+\z~',
            $repoFullName
        )) {
            throw new RuntimeException(
                'Repository must use the owner/repository format.'
            );
        }

        $parts = explode('/', $repoFullName);

        if (in_array($parts[1], ['.', '..'], true)) {
            throw new RuntimeException(
                'Invalid GitHub repository name.'
            );
        }

        return '/repos/'.$repoFullName;
    }

    /**
     * Perform an authenticated GitHub REST API request.
     *
     * @param  array<string, int|string>  $query
     */
    private function request(
        string $path,
        string $token,
        array $query = []
    ): Response {
        if (trim($token) === '') {
            throw new RuntimeException(
                'GitHub installation token is required.'
            );
        }

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->accept('application/vnd.github+json')
                ->withToken($token)
                ->withHeaders([
                    'X-GitHub-Api-Version' => self::API_VERSION,
                    'User-Agent' => 'DupIssue-Laravel',
                ])
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->get($path, $query);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to GitHub API. Please try again.'
            );
        }

        if (! $response->successful()) {
            $this->handleError($response);
        }

        return $response;
    }

    /**
     * Decode a JSON object response.
     *
     * @return array<string, mixed>
     */
    private function decodeObject(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException(
                'GitHub returned an invalid JSON object.'
            );
        }

        return $payload;
    }

    /**
     * Decode a JSON list response.
     *
     * @return array<int, mixed>
     */
    private function decodeList(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new RuntimeException(
                'GitHub returned an invalid JSON list.'
            );
        }

        return $payload;
    }

    /**
     * Convert GitHub HTTP errors into safe application messages.
     */
    private function handleError(Response $response): never
    {
        $status = $response->status();

        if ($status === 401) {
            $message = 'GitHub installation token is invalid, expired, or revoked.';
        } elseif (
            $status === 429
            || (
                $status === 403
                && ($response->header('X-RateLimit-Remaining') === '0'
                    || trim((string) $response->header('Retry-After')) !== '')
            )
        ) {
            $message = 'GitHub API rate limit exceeded. Please try again later.';
            $status = 429;
        } elseif ($status === 403) {
            $message = 'GitHub access denied. Check repository permissions or API restrictions.';
        } elseif ($status === 404) {
            $message = 'GitHub repository was not found or is not accessible with this token.';
        } elseif ($status >= 500) {
            $message = 'GitHub API is temporarily unavailable.';
        } else {
            $message = 'GitHub API request failed.';
        }

        if ($status === 429) {
            throw GithubStatusRateLimitException::fromResponse($response);
        }

        throw new RuntimeException($message, $status);
    }
}
