<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GithubIssueStatusDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverGithubIssueStatus extends Command
{
    protected $signature = 'github:status-recover {--limit=100}';

    protected $description = 'Recover durable outbound GitHub issue status tasks';

    public function handle(GithubIssueStatusDispatchService $dispatch): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if (! $limit || $limit < 1 || $limit > 1000) {
            return self::INVALID;
        }

        $ids = DB::table('github_issue_status_outbox')
            ->whereIn('status', ['PENDING', 'QUEUED', 'PROCESSING', 'RETRY'])
            ->where(fn ($q) => $q->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('lease_until')
                ->orWhere('lease_until', '<=', now()))
            ->where(fn ($q) => $q->whereNull('queued_at')
                ->orWhere('queued_at', '<=', now()->subMinutes(2)))
            ->orderBy('id')->limit($limit)->pluck('id');

        $dispatched = 0;

        foreach ($ids as $id) {
            $dispatched += (int) $dispatch->dispatch((int) $id);
        }

        $this->info('GitHub status tasks dispatched: '.$dispatched);

        return self::SUCCESS;
    }
}
