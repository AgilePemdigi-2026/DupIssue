<?php

namespace Tests\Unit;

use App\Services\GithubAppInstallationTokenService;
use App\Services\GithubAppJwtService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubAppInstallationTokenServiceTest extends TestCase
{
    private function service(): GithubAppInstallationTokenService
    {
        $jwtService = $this->createMock(
            GithubAppJwtService::class
        );

        $jwtService->method('generate')
            ->willReturn('test.jwt.signature');

        return new GithubAppInstallationTokenService(
            $jwtService
        );
    }

    public function test_request_is_scoped_to_one_repository(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            '*' => Http::response([
                'token' => 'fake-installation-token',
                'expires_at' => now()
                    ->addMinutes(50)
                    ->toIso8601String(),
                'permissions' => [
                    'issues' => 'read',
                    'metadata' => 'read',
                ],
            ], 201),
        ]);

        $token = $this->service()
            ->createForRepository(1234, 5678);

        $this->assertSame(
            'fake-installation-token',
            $token
        );

        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->url() ===
                    'https://api.github.com/app/installations/1234/access_tokens'
                && $request->hasHeader(
                    'Authorization',
                    'Bearer test.jwt.signature'
                )
                && $request->data()['repository_ids'] === [5678]
                && $request->data()['permissions'] === [
                    'issues' => 'read',
                ];
        });
    }

    public function test_http_error_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([
                'message' => 'Forbidden',
            ], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub installation token request failed.'
        );

        $this->service()->createForRepository(1234, 5678);
    }

    public function test_expired_token_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([
                'token' => 'fake-expired-token',
                'expires_at' => now()
                    ->subMinute()
                    ->toIso8601String(),
                'permissions' => [
                    'issues' => 'read',
                ],
            ], 201),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub installation token expiration is invalid.'
        );

        $this->service()->createForRepository(1234, 5678);
    }

    public function test_missing_issue_permission_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([
                'token' => 'fake-installation-token',
                'expires_at' => now()
                    ->addMinutes(50)
                    ->toIso8601String(),
                'permissions' => [
                    'metadata' => 'read',
                ],
            ], 201),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GitHub installation lacks issue-read permission.'
        );

        $this->service()->createForRepository(1234, 5678);
    }

    public function test_invalid_identifiers_are_rejected(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Invalid GitHub installation or repository.'
        );

        $this->service()->createForRepository(0, 5678);
    }
}
