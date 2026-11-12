<?php

namespace Tests\Feature;

use App\Services\GithubApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubApiClientTest extends TestCase
{
    public function test_repository_connection_succeeds(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/issues')) {
                return Http::response([], 200);
            }

            return Http::response([
                'full_name' => 'demo/bugs',
                'private' => true,
                'has_issues' => true,
            ], 200);
        });

        $result = (new GithubApiClient())
            ->testConnection('demo/bugs', 'dummy-token');

        $this->assertSame('demo/bugs', $result['repo_full_name']);
        $this->assertTrue($result['private']);

        Http::assertSentCount(2);

        Http::assertSent(fn (Request $request) =>
            str_contains($request->url(), '/issues')
            && $request->hasHeader(
                'Authorization',
                'Bearer dummy-token'
            )
            && $request->hasHeader(
                'X-GitHub-Api-Version',
                '2026-03-10'
            )
        );
    }

    public function test_fetches_paginated_issues_and_excludes_pull_requests(): void
    {
        Http::fake(function (Request $request) {
            parse_str(
                (string) parse_url($request->url(), PHP_URL_QUERY),
                $query
            );

            if ((int) ($query['page'] ?? 1) === 1) {
                return Http::response([
                    [
                        'number' => 11,
                        'title' => 'Login error',
                        'body' => 'Cannot log in.',
                    ],
                    [
                        'number' => 12,
                        'title' => 'Fix login',
                        'pull_request' => [
                            'url' => 'https://api.github.com/pulls/12',
                        ],
                    ],
                ], 200, [
                    'Link' => '<https://api.github.com/repos/demo/bugs/issues?page=2>; rel="next"',
                ]);
            }

            return Http::response([
                [
                    'number' => 13,
                    'title' => 'Checkout error',
                    'body' => 'Checkout failed.',
                ],
            ], 200);
        });

        $issues = (new GithubApiClient())
            ->fetchIssues('demo/bugs', 'dummy-token');

        $this->assertCount(2, $issues);
        $this->assertSame(
            [11, 13],
            array_column($issues, 'number')
        );

        Http::assertSentCount(2);
    }

    public function test_invalid_repository_format_is_rejected(): void
    {
        Http::fake();

        try {
            (new GithubApiClient())->fetchIssues(
                'https://github.com/demo/bugs',
                'dummy-token'
            );

            $this->fail('Invalid repository was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'owner/repository',
                $exception->getMessage()
            );
        }

        Http::assertNothingSent();
    }

    public function test_invalid_token_returns_safe_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Bad credentials',
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub PAT is invalid, expired, or revoked.'
        );

        (new GithubApiClient())
            ->testConnection('demo/bugs', 'invalid-token');
    }

    public function test_rate_limit_error_is_handled(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'API rate limit exceeded',
            ], 403, [
                'X-RateLimit-Remaining' => '0',
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub API rate limit exceeded.'
        );

        (new GithubApiClient())
            ->fetchIssues('demo/bugs', 'dummy-token');
    }


    public function test_insufficient_permission_returns_safe_error(): void
    {
        $token = 'secret-test-token';

        Http::fake([
            '*' => Http::response([
                'message' => 'Resource not accessible by personal access token',
            ], 403),
        ]);

        try {
            (new GithubApiClient())
                ->testConnection('demo/bugs', $token);

            $this->fail('Insufficient permission was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'GitHub access denied',
                $exception->getMessage()
            );

            $this->assertStringNotContainsString(
                $token,
                $exception->getMessage()
            );
        }
    }

    public function test_unavailable_repository_returns_safe_error(): void
    {
        $token = 'secret-test-token';

        Http::fake([
            '*' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        try {
            (new GithubApiClient())
                ->testConnection('demo/missing', $token);

            $this->fail('Unavailable repository was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'GitHub repository was not found',
                $exception->getMessage()
            );

            $this->assertStringNotContainsString(
                $token,
                $exception->getMessage()
            );
        }
    }
}
