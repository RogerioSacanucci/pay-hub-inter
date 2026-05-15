<?php

namespace Tests\Feature\Commands;

use App\Models\MundpayWebhookLog;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeWebhookLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purges_cartpanda_and_mundpay_logs_older_than_one_day(): void
    {
        $oldCartpanda = WebhookLog::factory()->create([
            'created_at' => now()->subDays(2),
        ]);
        $freshCartpanda = WebhookLog::factory()->create([
            'created_at' => now()->subHour(),
        ]);

        $oldMundpay = new MundpayWebhookLog;
        $oldMundpay->fill([
            'event' => 'payment.paid',
            'mundpay_order_id' => 'old-1',
            'status' => 'processed',
            'payload' => ['event' => 'payment.paid'],
        ]);
        $oldMundpay->created_at = now()->subDays(2);
        $oldMundpay->save();

        $freshMundpay = new MundpayWebhookLog;
        $freshMundpay->fill([
            'event' => 'payment.paid',
            'mundpay_order_id' => 'fresh-1',
            'status' => 'processed',
            'payload' => ['event' => 'payment.paid'],
        ]);
        $freshMundpay->created_at = now()->subHour();
        $freshMundpay->save();

        $this->artisan('app:purge-webhook-logs')->assertOk();

        $this->assertDatabaseMissing('webhook_logs', ['id' => $oldCartpanda->id]);
        $this->assertDatabaseHas('webhook_logs', ['id' => $freshCartpanda->id]);

        $this->assertDatabaseMissing('mundpay_webhook_logs', ['id' => $oldMundpay->id]);
        $this->assertDatabaseHas('mundpay_webhook_logs', ['id' => $freshMundpay->id]);
    }
}
