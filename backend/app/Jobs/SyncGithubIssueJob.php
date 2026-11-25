<?php

namespace App\Jobs;

use App\Services\GithubWebhookSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncGithubIssueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public bool $failOnTimeout = false;

    public function __construct(public readonly int $taskId)
    {
        $this->onConnection('database')->onQueue('default');
    }

    public function backoff(): array
    {
        return [60, 120, 300, 600];
    }

    public function handle(GithubWebhookSyncService $service): void
    {
        if (! $service->process($this->taskId)) {
            $this->release(30);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('github_webhook_tasks')->where('id', $this->taskId)
            ->whereNotIn('status', ['COMPLETED', 'REJECTED'])
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update(['status' => 'FAILED', 'error_code' => 'ATTEMPTS_EXHAUSTED', 'lease_until' => null, 'updated_at' => now()]);
    }
}
