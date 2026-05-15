<?php

namespace App\Console\Commands;

use App\Models\MundpayWebhookLog;
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
        $cutoff = now()->subDay();

        $cartpanda = WebhookLog::where('created_at', '<', $cutoff)->delete();
        $mundpay = MundpayWebhookLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$cartpanda} cartpanda + {$mundpay} mundpay webhook log(s).");
    }
}
