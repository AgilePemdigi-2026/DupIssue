<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('github:webhooks-recover --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('github:status-recover --limit=100')->everyMinute()->withoutOverlapping();
Schedule::command('extension:sessions-prune')->hourly()->withoutOverlapping();
Schedule::command('nlp:recover-pending --limit=100')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
