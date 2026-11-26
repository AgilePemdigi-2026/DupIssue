<?php

namespace Tests\Feature;

use App\Jobs\SyncGithubIssueJob;
use App\Models\GithubAppConnection;
use App\Models\Project;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubWebhookTest extends TestCase
{
    use DatabaseMigrations;

    private const SECRET = 'test-only-webhook-secret-never-used-outside-tests';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        config(['services.github_app.webhook_secret' => self::SECRET]);
        $connection = new GithubAppConnection;
        $connection->forceFill(['project_id' => Project::factory()->create()->id, 'installation_id' => 12,
            'repository_id' => 34, 'repo_full_name' => 'example/repo', 'verified_github_user_id' => 56, 'verified_at' => now()])->save();
    }

    private function payload(): array
    {
        return ['action' => 'opened', 'installation' => ['id' => 12], 'repository' => ['id' => 34], 'issue' => ['number' => 7, 'body' => 'PRIVATE BODY']];
    }

    private function sendPayload(?string $raw = null, array $headers = [])
    {
        $raw ??= json_encode($this->payload());

        return $this->call('POST', '/api/github/webhook', [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'issues', 'HTTP_X_GITHUB_DELIVERY' => '11111111-2222-3333-4444-555555555555',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET),
        ], $headers), $raw);
    }

    public function test_signed_delivery_is_durable_idempotent_and_contains_no_raw_payload(): void
    {
        $this->sendPayload()->assertStatus(202)->assertHeader('Cache-Control', 'no-store, private');
        $this->sendPayload()->assertStatus(202);
        $this->assertDatabaseCount('github_webhook_deliveries', 1);
        $this->assertDatabaseCount('github_webhook_tasks', 1);
        Bus::assertDispatched(SyncGithubIssueJob::class, 1);
        $this->assertStringNotContainsString('PRIVATE BODY', json_encode(DB::table('github_webhook_deliveries')->first()));
    }

    public function test_signatures_headers_and_raw_bytes_are_verified(): void
    {
        foreach (['', 'sha1=bad', 'sha256='.str_repeat('0', 64)] as $signature) {
            $this->sendPayload(headers: ['HTTP_X_HUB_SIGNATURE_256' => $signature])->assertUnauthorized();
        }
        $raw = json_encode($this->payload());
        $this->sendPayload($raw.' ', ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, self::SECRET)])->assertUnauthorized();
        $this->sendPayload(headers: ['HTTP_X_GITHUB_DELIVERY' => '../bad'])->assertUnprocessable();
        $this->sendPayload(headers: ['HTTP_X_GITHUB_EVENT' => ''])->assertUnprocessable();
        Bus::assertNothingDispatched();
    }

    public function test_invalid_payload_unconnected_identity_and_unsupported_events(): void
    {
        $this->sendPayload('{')->assertUnprocessable();
        $this->sendPayload('[]')->assertUnprocessable();
        $data = $this->payload();
        $data['repository']['id'] = 999;
        $this->sendPayload(json_encode($data))->assertForbidden();
        $data['repository']['id'] = '34';
        $this->sendPayload(json_encode($data))->assertUnprocessable();
        $this->sendPayload(headers: ['HTTP_X_GITHUB_EVENT' => 'push'])->assertStatus(202)->assertJsonPath('code', 'IGNORED');
        $this->assertDatabaseCount('github_webhook_deliveries', 0);
    }

    public function test_failed_dispatch_is_recovered_by_redelivery(): void
    {
        $original = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('test dispatch failure'));
        $this->sendPayload()->assertStatus(202);
        $this->assertDatabaseHas('github_webhook_tasks', ['status' => 'RECEIVED', 'error_code' => 'DISPATCH_FAILED', 'queued_at' => null]);
        Bus::swap($original);
        $this->sendPayload()->assertStatus(202);
        Bus::assertDispatched(SyncGithubIssueJob::class, 1);
    }

    public function test_same_delivery_uuid_with_changed_body_is_rejected(): void
    {
        $this->sendPayload()->assertStatus(202);
        $data = $this->payload();
        $data['issue']['number'] = 8;
        $this->sendPayload(json_encode($data))->assertConflict();
        Bus::assertDispatched(SyncGithubIssueJob::class, 1);
    }

    public function test_payload_size_and_unconfigured_secret_fail_closed(): void
    {
        $this->sendPayload(str_repeat('x', 1048577))->assertStatus(413);
        config(['services.github_app.webhook_secret' => null]);
        $this->sendPayload()->assertStatus(503);
    }

    public function test_task_insertion_failure_rolls_back_delivery_and_never_dispatches(): void
    {
        $this->withoutExceptionHandling();
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "github_webhook_tasks"')) {
                throw new \RuntimeException('test transaction failure');
            }
        });
        try {
            $this->sendPayload();
            $this->fail('Expected transaction failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('test transaction failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('github_webhook_deliveries', 0);
        $this->assertDatabaseCount('github_webhook_tasks', 0);
        Bus::assertNothingDispatched();
    }
}
