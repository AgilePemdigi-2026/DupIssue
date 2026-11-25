<?php

namespace Tests\Unit;

use App\Models\GithubAppConnection;
use App\Services\GithubApiClient;
use App\Services\GithubAppInstallationTokenService;
use App\Services\GithubAppIssueFetchService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubAppIssueFetchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function connection(): GithubAppConnection
    {
        $connection = new GithubAppConnection();

        // Simulasi model yang sudah tersimpan.
        // Tidak ada INSERT ke database.
        $connection->exists = true;
        $connection->project_id = 1;
        $connection->installation_id = 12345;
        $connection->repository_id = 5678;
        $connection->repo_full_name = 'example/test-repo';
        $connection->verified_at = now();

        return $connection;
    }

    private function service(
        GithubAppInstallationTokenService $tokenService
    ): GithubAppIssueFetchService {
        return new GithubAppIssueFetchService(
            $tokenService,
            new GithubApiClient()
        );
    }

    public function test_fetches_paginated_issues_and_excludes_pull_requests(): void
    {
        $tokenService = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        $tokenService->expects($this->once())
            ->method('createForRepository')
            ->with(12345, 5678)
            ->willReturn('fake-installation-token');

        Http::fake([
            'https://api.github.com/repos/example/test-repo/issues*' =>
                function (Request $request) {
                    parse_str(
                        (string) parse_url(
                            $request->url(),
                            PHP_URL_QUERY
                        ),
                        $query
                    );

                    if (($query['page'] ?? null) === '1') {
                        return Http::response([
                            [
                                'number' => 12,
                                'title' => 'First issue',
                                'body' => 'First issue description',
                            ],
                            [
                                'number' => 13,
                                'title' => 'Pull request',
                                'pull_request' => [
                                    'url' => 'https://api.github.com/example',
                                ],
                            ],
                        ], 200, [
                            'Link' =>
                                '<https://api.github.com/repos/example/test-repo/issues?page=2>; rel="next"',
                        ]);
                    }

                    return Http::response([
                        [
                            'number' => 14,
                            'title' => 'Second issue',
                            'body' => 'Second issue description',
                        ],
                    ], 200);
                },
        ]);

        $issues = $this->service($tokenService)
            ->fetchIssues($this->connection());

        $this->assertSame(
            [12, 14],
            array_column($issues, 'number')
        );

        Http::assertSentCount(2);

        Http::assertSent(function (Request $request) {
            parse_str(
                (string) parse_url(
                    $request->url(),
                    PHP_URL_QUERY
                ),
                $query
            );

            return $request->method() === 'GET'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer fake-installation-token'
                )
                && ($query['state'] ?? null) === 'all'
                && ($query['per_page'] ?? null) === '100'
                && ($query['page'] ?? null) === '1';
        });

        Http::assertSent(function (Request $request) {
            return str_contains(
                $request->url(),
                'page=2'
            );
        });
    }

    public function test_incomplete_connection_is_rejected(): void
    {
        Http::fake();

        $tokenService = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        $tokenService->expects($this->never())
            ->method('createForRepository');

        $connection = $this->connection();
        $connection->verified_at = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub App connection is incomplete.'
        );

        $this->service($tokenService)
            ->fetchIssues($connection);
    }

    public function test_installation_token_failure_is_propagated(): void
    {
        Http::fake();

        $tokenService = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        $tokenService->expects($this->once())
            ->method('createForRepository')
            ->with(12345, 5678)
            ->willThrowException(
                new RuntimeException(
                    'Installation token unavailable.'
                )
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Installation token unavailable.'
        );

        $this->service($tokenService)
            ->fetchIssues($this->connection());
    }

    public function test_github_access_denied_does_not_leak_token(): void
    {
        $tokenService = $this->createMock(
            GithubAppInstallationTokenService::class
        );

        $tokenService->expects($this->once())
            ->method('createForRepository')
            ->with(12345, 5678)
            ->willReturn('fake-installation-token');

        Http::fake([
            'https://api.github.com/repos/example/test-repo/issues*' =>
                Http::response([
                    'message' => 'Forbidden',
                ], 403),
        ]);

        try {
            $this->service($tokenService)
                ->fetchIssues($this->connection());

            $this->fail('Expected GitHub API error.');
        } catch (RuntimeException $exception) {
            $this->assertSame(403, $exception->getCode());

            $this->assertStringNotContainsString(
                'fake-installation-token',
                $exception->getMessage()
            );
        }

        Http::assertSentCount(1);
    }
}
