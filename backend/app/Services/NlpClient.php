<?php

namespace App\Services;

use App\Exceptions\NlpClientException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

class NlpClient
{
    public function health(): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('/health'));

        $payload = $this->decodeResponse($response, 'health');

        if (($payload['status'] ?? null) !== 'ok'
            || ($payload['service'] ?? null) !== 'DupIssue NLP Service') {
            throw new NlpClientException('NLP service returned an invalid health response.', $response->status());
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $newIssue
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array{candidates: array<int, array{issue_id: int|string, similarity: int|float}>}
     */
    public function candidates(string|int $projectId, array $newIssue, array $candidates): array
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->post('/candidates', [
            'project_id' => $projectId,
            'new_issue' => $newIssue,
            'candidates' => $candidates,
        ]));

        $payload = $this->decodeResponse($response, 'candidate detection');

        if (! array_key_exists('candidates', $payload) || ! is_array($payload['candidates'])) {
            throw new NlpClientException('NLP service returned an invalid candidates response.', $response->status());
        }

        foreach ($payload['candidates'] as $candidate) {
            if (! is_array($candidate)
                || ! array_key_exists('issue_id', $candidate)
                || ! array_key_exists('similarity', $candidate)
                || (! is_int($candidate['issue_id']) && ! is_string($candidate['issue_id']))
                || (! is_int($candidate['similarity']) && ! is_float($candidate['similarity']))) {
                throw new NlpClientException('NLP service returned an invalid candidate item.', $response->status());
            }
        }

        return $payload;
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('services.nlp.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new NlpClientException('NLP_SERVICE_URL is not configured.');
        }

        return Http::baseUrl(rtrim($baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.nlp.connect_timeout', 5))
            ->timeout((int) config('services.nlp.timeout', 10));
    }

    /**
     * @param  callable(PendingRequest): Response  $operation
     */
    private function send(callable $operation): Response
    {
        try {
            return $operation($this->request());
        } catch (ConnectionException $exception) {
            throw new NlpClientException('NLP service connection failed.', null, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response, string $operation): array
    {
        if ($response->failed()) {
            throw new NlpClientException(
                "NLP service failed during {$operation}.",
                $response->status(),
            );
        }

        try {
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new NlpClientException(
                "NLP service returned invalid JSON during {$operation}.",
                $response->status(),
                $exception,
            );
        }

        if (! is_array($payload)) {
            throw new NlpClientException(
                "NLP service returned an invalid response during {$operation}.",
                $response->status(),
            );
        }

        return $payload;
    }
}
