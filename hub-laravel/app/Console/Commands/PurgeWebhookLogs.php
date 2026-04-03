<?php

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:purge-webhook-logs')]
#[Description('Delete webhook logs older than 1 day')]
class PurgeWebhookLogs extends Command
{
    public function handle(): void
    {
        $deleted = WebhookLog::where('created_at', '<', now()->subDay())->delete();

        $this->info("Deleted {$deleted} webhook log(s).");
    }
}
