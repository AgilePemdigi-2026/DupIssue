<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExtensionSessions extends Command
{
    protected $signature = 'extension:sessions-prune';

    protected $description = 'Clear expired credential hashes and retain inactive session metadata for seven days';

    public function handle(): int
    {
        DB::transaction(function (): void {
            DB::table('extension_sessions')->where('pairing_expires_at', '<=', now())->whereNotNull('pairing_hash')
                ->update(['pairing_hash' => null]);
            DB::table('extension_sessions')->where('access_expires_at', '<=', now())->whereNotNull('access_hash')
                ->update(['access_hash' => null]);
            DB::table('extension_sessions')->where(function ($query): void {
                $query->where('revoked_at', '<=', now()->subDays(7))
                    ->orWhere('access_expires_at', '<=', now()->subDays(7))
                    ->orWhere(fn ($q) => $q->whereNull('paired_at')->where('pairing_expires_at', '<=', now()->subDays(7)));
            })->delete();
        }, 3);
        $this->info('Expired extension credentials cleared; retention applied.');

        return self::SUCCESS;
    }
}
