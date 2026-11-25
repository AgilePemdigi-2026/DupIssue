<?php

namespace App\Console\Commands;

use App\Services\GithubWebhookDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverGithubWebhooks extends Command
{
    protected $signature = 'github:webhooks-recover {--task= : Explicitly reprocess one failed/rejected task after fixing its cause} {--limit=100}';

    protected $description = 'Recover durable GitHub deliveries without storing or replaying raw payloads';

    public function handle(GithubWebhookDispatchService $dispatch): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 1000) {
            $this->error('Limit must be 1–1000.');

            return self::INVALID;
        }
        $taskId = $this->option('task');
        if ($taskId !== null) {
            $id = filter_var($taskId, FILTER_VALIDATE_INT);
            if (! $id || $id < 1) {
                return self::INVALID;
            }
            DB::table('github_webhook_tasks')->where('id', $id)->whereIn('status', ['FAILED', 'REJECTED'])->update([
                'status' => 'RECEIVED', 'attempts' => 0, 'queued_at' => null, 'available_at' => null,
                'lease_until' => null, 'claim_token' => null, 'error_code' => null, 'updated_at' => now(),
            ]);
            $ids = [$id];
        } else {
            $ids = DB::table('github_webhook_tasks')->whereIn('status', ['RECEIVED', 'RETRY', 'QUEUED', 'PROCESSING'])
                ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subMinutes(5)))
                ->orderBy('id')->limit($limit)->pluck('id')->all();
        }
        $count = 0;
        foreach ($ids as $id) {
            if ($dispatch->dispatch((int) $id)) {
                $count++;
            }
        }
        $this->info('Queued '.$count.' delivery tasks.');

        return self::SUCCESS;
    }
}
