<?php

namespace App\Console\Commands;

use App\Jobs\DetectIssueCandidatesJob;
use App\Models\Issue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class RecoverPendingDetection extends Command
{
    protected $signature = 'nlp:recover-pending {--limit=100}';

    protected $description = 'Recover stale pending/processing NLP work after dispatch or worker crashes';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 1000) {
            return self::INVALID;
        }
        $ids = Issue::whereIn('detection_status', ['PENDING', 'PROCESSING'])->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $issue = Issue::whereKey($id)->lockForUpdate()->first();
                if (! $issue || ! in_array($issue->detection_status, ['PENDING', 'PROCESSING'], true)
                    || $issue->updated_at->gt(now()->subMinutes(5))) {
                    return;
                }
                $issue->detection_status = 'PENDING';
                $issue->touch();
                DB::afterCommit(fn () => Bus::dispatch(new DetectIssueCandidatesJob((int) $id)));
            }, 3);
        }
        $this->info('Stale NLP work checked. Failed items require explicit review/retry.');

        return self::SUCCESS;
    }
}
