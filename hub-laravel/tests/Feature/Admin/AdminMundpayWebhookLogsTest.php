<?php

namespace Tests\Feature\Admin;

use App\Models\MundpayWebhookLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMundpayWebhookLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/mundpay-webhook-logs')->assertForbidden();
    }

    public function test_admin_lists_logs_descending_by_created_at(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $older = MundpayWebhookLog::create([
            'event' => 'order.paid',
            'mundpay_order_id' => 'order-old',
            'status' => 'processed',
            'payload' => [],
        ]);
        $older->created_at = now()->subHour();
        $older->save();

        $newer = MundpayWebhookLog::create([
            'event' => 'order.refunded',
            'mundpay_order_id' => 'order-new',
            'status' => 'processed',
            'payload' => [],
        ]);
        $newer->created_at = now();
        $newer->save();

        $response = $this->getJson('/api/admin/mundpay-webhook-logs');

        $response->assertOk();
        $response->assertJsonPath('data.0.mundpay_order_id', 'order-new');
        $response->assertJsonPath('data.1.mundpay_order_id', 'order-old');
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_filters_by_event(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        MundpayWebhookLog::create([
            'event' => 'order.paid', 'mundpay_order_id' => 'a',
            'status' => 'processed', 'payload' => [],
        ]);
        MundpayWebhookLog::create([
            'event' => 'order.refunded', 'mundpay_order_id' => 'b',
            'status' => 'processed', 'payload' => [],
        ]);

        $this->getJson('/api/admin/mundpay-webhook-logs?event=order.refunded')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.mundpay_order_id', 'b');
    }

    public function test_filters_by_status(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        MundpayWebhookLog::create([
            'event' => 'order.paid', 'mundpay_order_id' => 'a',
            'status' => 'processed', 'payload' => [],
        ]);
        MundpayWebhookLog::create([
            'event' => 'order.cancelled', 'mundpay_order_id' => 'b',
            'status' => 'ignored', 'status_reason' => 'unknown_event', 'payload' => [],
        ]);

        $this->getJson('/api/admin/mundpay-webhook-logs?status=ignored')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
