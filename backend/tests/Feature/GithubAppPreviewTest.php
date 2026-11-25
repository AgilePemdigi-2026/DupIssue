<?php

namespace Tests\Feature;

use App\Models\GithubAppConnection;
use App\Models\Project;
use App\Models\User;
use App\Services\GithubAppIssueFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubAppPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->owner = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->project = Project::factory()->create([
            'owner_id' => $this->owner->id,
        ]);
    }

    private function createConnection(): GithubAppConnection
    {
        $connection = new GithubAppConnection();

        $connection->project_id = $this->project->id;
        $connection->installation_id = 12345;
        $connection->repository_id = 5678;
        $connection->repo_full_name = 'example/test-repo';
        $connection->verified_github_user_id = 999;
        $connection->verified_at = now();

        $connection->save();

        return $connection;
    }

    private function previewUrl(): string
    {
        return route('sources.github.app.preview', [
            'project' => $this->project->id,
        ]);
    }

    private function mockFetch(
        array $issues = [],
        ?RuntimeException $exception = null
    ): void {
        $mock = $this->createMock(
            GithubAppIssueFetchService::class
        );

        $expectation = $mock->expects($this->once())
            ->method('fetchIssues');

        if ($exception !== null) {
            $expectation->willThrowException($exception);
        } else {
            $expectation->willReturn($issues);
        }

        $this->app->instance(
            GithubAppIssueFetchService::class,
            $mock
        );
    }

    public function test_verified_connection_can_preview_issues(): void
    {
        $connection = $this->createConnection();

        $this->mockFetch([
            [
                'number' => 12,
                'title' => 'Sample GitHub issue',
                'body' => 'Description of the sample GitHub issue.',
            ],
        ]);

        $response = $this->actingAs($this->owner)
            ->post($this->previewUrl());

        $response->assertOk();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath(
            'preview.valid_count',
            1
        );
        $response->assertJsonPath(
            'preview.rows.0.data.github_number',
            12
        );

        $rows = $response->json('preview.rows');

        $fingerprint = hash(
            'sha256',
            json_encode($rows, JSON_THROW_ON_ERROR)
        );

        $response->assertSessionHas(
            'source_issue.github_app_preview',
            function ($data) use ($connection, $fingerprint) {
                return $data['project_id'] ===
                        (int) $this->project->id
                    && $data['actor_id'] ===
                        (int) $this->owner->id
                    && $data['connection_id'] ===
                        (int) $connection->id
                    && $data['installation_id'] === 12345
                    && $data['repository_id'] === 5678
                    && $data['repo_full_name'] ===
                        'example/test-repo'
                    && $data['fingerprint'] === $fingerprint
                    && $data['expires_at'] > now()->timestamp
                    && ! array_key_exists('access_token', $data);
            }
        );

        $this->assertDatabaseCount('issues', 0);

        Http::assertNothingSent();
    }

    public function test_missing_connection_is_rejected(): void
    {
        $response = $this->actingAs($this->owner)
            ->post($this->previewUrl());

        $response->assertStatus(409);

        $response->assertJsonPath('success', false);

        $response->assertSessionMissing(
            'source_issue.github_app_preview'
        );

        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_preview(): void
    {
        $this->createConnection();

        $other = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        $this->actingAs($other)
            ->post($this->previewUrl())
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_fetch_failure_invalidates_previous_preview(): void
    {
        $this->createConnection();

        $this->mockFetch(
            [],
            new RuntimeException(
                'GitHub access denied.',
                403
            )
        );

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_app_preview' => [
                    'fingerprint' => str_repeat('a', 64),
                ],
            ])
            ->post($this->previewUrl());

        $response->assertStatus(422);

        $response->assertJsonPath('success', false);

        $response->assertSessionMissing(
            'source_issue.github_app_preview'
        );

        $this->assertDatabaseCount('issues', 0);

        Http::assertNothingSent();
    }

    public function test_app_preview_does_not_modify_pat_preview(): void
    {
        $this->createConnection();

        $this->mockFetch([]);

        $patPreview = [
            'project_id' => (int) $this->project->id,
            'fingerprint' => str_repeat('b', 64),
        ];

        $response = $this->actingAs($this->owner)
            ->withSession([
                'source_issue.github_preview' => $patPreview,
            ])
            ->post($this->previewUrl());

        $response->assertOk();

        $response->assertSessionHas(
            'source_issue.github_preview',
            $patPreview
        );

        $response->assertSessionHas(
            'source_issue.github_app_preview'
        );

        Http::assertNothingSent();
    }
}
