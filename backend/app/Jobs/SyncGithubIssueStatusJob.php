<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\GithubIssueStatusOutboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class SyncGithubIssueStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Four bounded GitHub HTTP requests fit within this timeout.
    // Keep it below the existing database queue retry_after (90s).
    public int $timeout = 80;

    public function __construct(public readonly int $taskId)
    {
        $this->onConnection('database')->onQueue('default');
    }

    public function handle(GithubIssueStatusOutboundService $service): void
    {
        try {
            $service->process($this->taskId);
        } catch (Throwable) {
            // Do not copy provider responses or credentials into failed_jobs.
            throw new RuntimeException('GitHub status processing interrupted.');
        }
    }
}
