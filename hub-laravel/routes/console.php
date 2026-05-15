<?php

use App\Console\Commands\PurgeTiktokEventLogs;
use App\Console\Commands\PurgeWebhookLogs;
use App\Jobs\ReleaseBalanceJob;
use App\Jobs\ReleaseMundpayBalanceJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app()->call([new ReleaseBalanceJob, 'handle']))->hourly();
Schedule::call(fn () => app()->call([new ReleaseMundpayBalanceJob, 'handle']))->hourly();
Schedule::command(PurgeWebhookLogs::class)->daily();
Schedule::command(PurgeTiktokEventLogs::class)->daily();
