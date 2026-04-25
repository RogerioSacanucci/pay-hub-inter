<?php

namespace App\Console\Commands;

use App\Models\TiktokEventLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:purge-tiktok-event-logs')]
#[Description('Delete TikTok event logs older than 2 days (real-time monitoring only)')]
class PurgeTiktokEventLogs extends Command
{
    public function handle(): void
    {
        $deleted = TiktokEventLog::where('created_at', '<', now()->subDays(2))->delete();

        $this->info("Deleted {$deleted} TikTok event log(s).");
    }
}
