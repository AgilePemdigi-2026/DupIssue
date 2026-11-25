<?php

namespace App\Http\Controllers;

use App\Models\GithubAppConnection;
use App\Services\GithubWebhookDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, GithubWebhookDispatchService $dispatch): JsonResponse
    {
        $raw = $request->getContent();
        if (strlen($raw) > 1048576) {
            return $this->reply('PAYLOAD_TOO_LARGE', 413);
        }
        $secret = config('services.github_app.webhook_secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            return $this->reply('NOT_CONFIGURED', 503);
        }
        $signature = (string) $request->header('X-Hub-Signature-256');
        if (! preg_match('/\Asha256=[a-f0-9]{64}\z/', $signature)
            || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            return $this->reply('INVALID_SIGNATURE', 401);
        }
        $uuid = strtolower((string) $request->header('X-GitHub-Delivery'));
        $event = (string) $request->header('X-GitHub-Event');
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $uuid)
            || ! preg_match('/\A[a-z_]{1,64}\z/', $event)) {
            return $this->reply('INVALID_HEADERS', 422);
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->reply('INVALID_PAYLOAD', 422);
        }
        if (! is_array($data) || array_is_list($data)) {
            return $this->reply('INVALID_PAYLOAD', 422);
        }
        if ($event !== 'issues' || ($data['action'] ?? null) !== 'opened') {
            return $this->reply('IGNORED', 202);
        }
        $installation = $data['installation']['id'] ?? null;
        $repository = $data['repository']['id'] ?? null;
        $number = $data['issue']['number'] ?? null;
        if (! is_int($installation) || $installation <= 0 || ! is_int($repository) || $repository <= 0
            || ! is_int($number) || $number <= 0 || $number > 4294967295
            || isset($data['issue']['pull_request'])) {
            return $this->reply('INVALID_IDENTITY', 422);
        }
        $connections = GithubAppConnection::query()->where('installation_id', $installation)
            ->where('repository_id', $repository)->whereNotNull('verified_at')->get();
        if ($connections->isEmpty()) {
            return $this->reply('UNCONNECTED_REPOSITORY', 403);
        }

        $hash = hash('sha256', $raw);
        $delivery = DB::transaction(function () use ($uuid, $hash, $installation, $repository, $number, $connections) {
            // The unique UUID is durable across workers, restarts and redelivery.
            $created = DB::table('github_webhook_deliveries')->insertOrIgnore([
                'delivery_uuid' => $uuid, 'body_hash' => $hash, 'installation_id' => $installation,
                'repository_id' => $repository, 'issue_number' => $number, 'received_at' => now(),
            ]);
            $row = DB::table('github_webhook_deliveries')->where('delivery_uuid', $uuid)->lockForUpdate()->first();
            if (! $row || ! hash_equals($row->body_hash, $hash)) {
                return null;
            }
            if ($created) {
                foreach ($connections as $connection) {
                    DB::table('github_webhook_tasks')->insert([
                        'delivery_id' => $row->id, 'connection_id' => $connection->id,
                        'project_id' => $connection->project_id, 'repository' => $connection->repo_full_name,
                        'status' => 'RECEIVED', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            return $row;
        }, 3);
        if (! $delivery) {
            return $this->reply('DELIVERY_CONFLICT', 409);
        }
        // A failed dispatch keeps the durable record available for redelivery/recovery.
        foreach (DB::table('github_webhook_tasks')->where('delivery_id', $delivery->id)->pluck('id') as $id) {
            $dispatch->dispatch((int) $id);
        }

        return $this->reply('ACCEPTED', 202);
    }

    private function reply(string $code, int $status): JsonResponse
    {
        return response()->json(['code' => $code], $status, ['Cache-Control' => 'no-store']);
    }
}
