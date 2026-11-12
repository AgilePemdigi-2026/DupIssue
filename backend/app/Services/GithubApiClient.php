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
     * Verify repository access and issue-read permission.
     *
     * @return array<string, mixed>
     */
    public function testConnection(string $repoFullName, string $pat): array
    {
        $path = $this->repositoryPath($repoFullName);

        $response = $this->request($path, $pat);
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
            $path . '/issues',
            $pat,
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
    public function fetchIssues(string $repoFullName, string $pat): array
    {
        $path = $this->repositoryPath($repoFullName) . '/issues';

        $issues = [];
        $page = 1;

        do {
            $response = $this->request($path, $pat, [
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

        return '/repos/' . $repoFullName;
    }

    /**
     * Perform an authenticated GitHub REST API request.
     *
     * @param array<string, int|string> $query
     */
    private function request(
        string $path,
        string $pat,
        array $query = []
    ): Response {
        if (trim($pat) === '') {
            throw new RuntimeException(
                'GitHub Personal Access Token is required.'
            );
        }

        try {
            $response = Http::baseUrl(self::BASE_URL)
                ->accept('application/vnd.github+json')
                ->withToken($pat)
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
            $message = 'GitHub PAT is invalid, expired, or revoked.';
        } elseif (
            $status === 429
            || (
                $status === 403
                && $response->header('X-RateLimit-Remaining') === '0'
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

        throw new RuntimeException($message, $status);
    }
}
