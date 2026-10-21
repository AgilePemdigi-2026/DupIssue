<?php

namespace Tests\Unit;

use App\Exceptions\NlpClientException;
use App\Services\NlpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NlpClientTest extends TestCase
{
    private NlpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.nlp.base_url' => 'http://127.0.0.1:8000']);
        $this->client = app(NlpClient::class);
    }

    public function test_health_check_returns_contract_payload(): void
    {
        Http::fake(['http://127.0.0.1:8000/health' => Http::response([
            'status' => 'ok',
            'service' => 'DupIssue NLP Service',
        ])]);

        $this->assertSame([
            'status' => 'ok',
            'service' => 'DupIssue NLP Service',
        ], $this->client->health());
    }

    public function test_health_check_rejects_http_failure(): void
    {
        Http::fake(['http://127.0.0.1:8000/health' => Http::response(['detail' => 'unavailable'], 503)]);

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('NLP service failed during health.');

        $this->client->health();
    }

    public function test_health_check_rejects_a_response_that_does_not_match_the_contract(): void
    {
        Http::fake(['http://127.0.0.1:8000/health' => Http::response([
            'status' => 'degraded',
            'service' => 'Another Service',
        ])]);

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('invalid health response');

        $this->client->health();
    }

    public function test_candidates_sends_the_contract_payload_and_processes_response(): void
    {
        Http::fake(['http://127.0.0.1:8000/candidates' => Http::response([
            'candidates' => [['issue_id' => 'issue-101', 'similarity' => 0.882]],
        ])]);

        $newIssue = [
            'issue_id' => 'issue-100',
            'title' => 'Aplikasi gagal saat login',
            'description' => 'Terjadi error setelah pengguna memasukkan kredensial.',
        ];
        $candidates = [[
            'issue_id' => 101,
            'project_id' => 'project-a',
            'title' => 'Tidak bisa masuk ke aplikasi',
            'description' => 'Login gagal setelah kredensial dimasukkan.',
        ]];

        $result = $this->client->candidates('project-a', $newIssue, $candidates);

        Http::assertSent(function ($request) use ($newIssue, $candidates): bool {
            return $request->url() === 'http://127.0.0.1:8000/candidates'
                && $request->method() === 'POST'
                && $request->data() === [
                    'project_id' => 'project-a',
                    'new_issue' => $newIssue,
                    'candidates' => $candidates,
                ];
        });
        $this->assertSame(['candidates' => [['issue_id' => 'issue-101', 'similarity' => 0.882]]], $result);
    }

    public function test_empty_candidates_response_is_a_successful_result(): void
    {
        Http::fake(['http://127.0.0.1:8000/candidates' => Http::response(['candidates' => []])]);

        $result = $this->client->candidates(1, [
            'issue_id' => 10,
            'title' => 'Login gagal',
            'description' => 'Pengguna tidak dapat masuk.',
        ], []);

        $this->assertSame(['candidates' => []], $result);
    }

    public function test_candidates_rejects_a_response_with_an_invalid_candidate_item(): void
    {
        Http::fake(['http://127.0.0.1:8000/candidates' => Http::response([
            'candidates' => [['issue_id' => 'issue-101']],
        ])]);

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('invalid candidate item');

        $this->client->candidates(1, [
            'issue_id' => 10,
            'title' => 'Login gagal',
            'description' => 'Pengguna tidak dapat masuk.',
        ], []);
    }

    public function test_http_422_is_reported_as_a_failure(): void
    {
        Http::fake(['http://127.0.0.1:8000/candidates' => Http::response(['detail' => []], 422)]);

        try {
            $this->client->candidates(1, [], []);
            $this->fail('Expected an NLP client exception.');
        } catch (NlpClientException $exception) {
            $this->assertSame(422, $exception->statusCode());
        }
    }

    public function test_http_503_is_reported_as_a_failure(): void
    {
        Http::fake(['http://127.0.0.1:8000/candidates' => Http::response([
            'detail' => 'Similarity service is temporarily unavailable.',
        ], 503)]);

        try {
            $this->client->candidates(1, [], []);
            $this->fail('Expected an NLP client exception.');
        } catch (NlpClientException $exception) {
            $this->assertSame(503, $exception->statusCode());
        }
    }

    public function test_connection_failure_is_reported_as_a_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('NLP service connection failed.');

        $this->client->health();
    }

    public function test_timeout_is_reported_as_a_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('NLP service connection failed.');

        $this->client->health();
    }

    public function test_invalid_json_is_rejected(): void
    {
        Http::fake(['http://127.0.0.1:8000/health' => Http::response('not-json', 200)]);

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('invalid JSON');

        $this->client->health();
    }

    public function test_missing_nlp_service_url_fails_without_using_a_fallback(): void
    {
        Http::fake();
        config(['services.nlp.base_url' => null]);

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('NLP_SERVICE_URL is not configured.');

        try {
            $this->client->health();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_empty_nlp_service_url_fails_without_using_a_fallback(): void
    {
        Http::fake();
        config(['services.nlp.base_url' => '']);

        $this->expectException(NlpClientException::class);
        $this->expectExceptionMessage('NLP_SERVICE_URL is not configured.');

        try {
            $this->client->health();
        } finally {
            Http::assertNothingSent();
        }
    }
}
