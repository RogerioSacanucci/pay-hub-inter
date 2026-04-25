<?php

namespace Tests\Feature\TiktokPixel;

use App\Models\TiktokEventLog;
use App\Models\TiktokPixel;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\TiktokEventsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiktokEventLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_persists_log_row_on_success(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create(['pixel_code' => 'CPIX_OK']);

        Http::fake([
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'request_id' => 'req-success-1',
            ], 200),
        ]);

        app(TiktokEventsService::class)->sendPurchaseEvent(
            collect([$pixel]),
            $this->orderPayload(),
        );

        $this->assertDatabaseCount('tiktok_events_log', 1);
        $log = TiktokEventLog::first();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($pixel->id, $log->tiktok_pixel_id);
        $this->assertSame('48700977', $log->cartpanda_order_id);
        $this->assertSame('Purchase', $log->event);
        $this->assertSame(200, $log->http_status);
        $this->assertSame(0, $log->tiktok_code);
        $this->assertSame('req-success-1', $log->request_id);
    }

    public function test_service_persists_log_row_on_tiktok_rejection(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        Http::fake([
            'business-api.tiktok.com/*' => Http::response([
                'code' => 40000,
                'message' => 'Invalid pixel_code',
                'request_id' => 'req-fail-1',
            ], 400),
        ]);

        app(TiktokEventsService::class)->sendPurchaseEvent(
            collect([$pixel]),
            $this->orderPayload(),
        );

        $log = TiktokEventLog::first();
        $this->assertSame(400, $log->http_status);
        $this->assertSame(40000, $log->tiktok_code);
        $this->assertSame('Invalid pixel_code', $log->tiktok_message);
    }

    public function test_log_payload_omits_pii(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'], 200),
        ]);

        app(TiktokEventsService::class)->sendPurchaseEvent(
            collect([$pixel]),
            $this->orderPayload(),
        );

        $log = TiktokEventLog::first();
        $payload = $log->payload;
        $this->assertArrayNotHasKey('context', $payload);
        $this->assertArrayNotHasKey('user', $payload);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('value', $payload);
        $this->assertArrayHasKey('currency', $payload);
        $this->assertArrayHasKey('content_count', $payload);
    }

    public function test_index_returns_only_authenticated_user_logs(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();
        TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->create();
        TiktokEventLog::factory()->create(); // another user's log

        $this->actingAs($user)
            ->getJson('/api/tiktok-events')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_filters_by_status_class(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();
        TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->count(2)->create();
        TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->failed()->create();

        $this->actingAs($user)
            ->getJson('/api/tiktok-events?status=success')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($user)
            ->getJson('/api/tiktok-events?status=error')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_show_returns_full_payload_for_owner(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();
        $log = TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->create();

        $this->actingAs($user)
            ->getJson("/api/tiktok-events/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonStructure(['data' => ['payload', 'response']]);
    }

    public function test_show_returns_403_for_other_users_log(): void
    {
        $user = User::factory()->create();
        $other = TiktokEventLog::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/tiktok-events/{$other->id}")
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/tiktok-events')->assertUnauthorized();
    }

    public function test_service_sends_full_advanced_matching_when_present(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'], 200),
        ]);

        app(TiktokEventsService::class)->sendPurchaseEvent(collect([$pixel]), $this->orderPayload());

        Http::assertSent(function ($req) {
            $u = $req->data()['context']['user'] ?? [];

            return ! empty($u['email'])
                && ! empty($u['phone_number'])
                && ! empty($u['external_id'])
                && ! empty($u['first_name'])
                && ! empty($u['last_name'])
                && ! empty($u['city'])
                && ! empty($u['state'])
                && ! empty($u['zip_code'])
                && ! empty($u['country'])
                && $u['email'] === hash('sha256', 'john@example.com')
                && $u['phone_number'] === hash('sha256', '14145249343')
                && $u['first_name'] === hash('sha256', 'john')
                && $u['last_name'] === hash('sha256', 'doe')
                && $u['city'] === hash('sha256', 'butternut')
                && $u['state'] === hash('sha256', 'wi')
                && $u['country'] === hash('sha256', 'us');
        });
    }

    public function test_service_falls_back_to_top_level_email_phone_when_customer_empty(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'], 200),
        ]);

        $payload = $this->orderPayload();
        $payload['customer']['email'] = '';
        $payload['customer']['phone'] = '';
        $payload['email'] = 'fallback@example.com';
        $payload['phone'] = '+15551234567';

        app(TiktokEventsService::class)->sendPurchaseEvent(collect([$pixel]), $payload);

        Http::assertSent(function ($req) {
            $u = $req->data()['context']['user'] ?? [];

            return ($u['email'] ?? null) === hash('sha256', 'fallback@example.com')
                && ($u['phone_number'] ?? null) === hash('sha256', '15551234567');
        });
    }

    public function test_service_omits_advanced_matching_fields_when_address_missing(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'message' => 'OK'], 200),
        ]);

        $payload = $this->orderPayload();
        unset($payload['address']);

        app(TiktokEventsService::class)->sendPurchaseEvent(collect([$pixel]), $payload);

        Http::assertSent(function ($req) {
            $u = $req->data()['context']['user'] ?? [];

            return ! isset($u['city']) && ! isset($u['state']) && ! isset($u['zip_code']) && ! isset($u['country']);
        });
    }

    public function test_retry_creates_new_log_when_webhook_payload_exists(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create(['pixel_code' => 'CRETRY1']);
        $oldLog = TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->failed()->create([
            'cartpanda_order_id' => '99999',
        ]);
        WebhookLog::factory()->create([
            'event' => 'order.paid',
            'cartpanda_order_id' => '99999',
            'payload' => [
                'event' => 'order.paid',
                'order' => $this->orderPayload(),
            ],
        ]);

        Http::fake([
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'message' => 'OK',
                'request_id' => 'req-retry-ok',
            ], 200),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/tiktok-events/{$oldLog->id}/retry")
            ->assertCreated()
            ->assertJsonPath('data.success', true);

        $this->assertSame(2, TiktokEventLog::count());
        $newLogId = $response->json('data.id');
        $this->assertNotSame($oldLog->id, $newLogId);
    }

    public function test_retry_returns_410_when_webhook_log_purged(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();
        $oldLog = TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->failed()->create();

        $this->actingAs($user)
            ->postJson("/api/tiktok-events/{$oldLog->id}/retry")
            ->assertStatus(410);
    }

    public function test_retry_returns_403_for_other_users_log(): void
    {
        $user = User::factory()->create();
        $other = TiktokEventLog::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/tiktok-events/{$other->id}/retry")
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(): array
    {
        return [
            'id' => 48700977,
            'checkout_params' => ['ttclid' => 'TTCLID_TEST'],
            'customer' => [
                'id' => 12345,
                'email' => 'john@example.com',
                'phone' => '+14145249343',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'full_name' => 'John Doe',
            ],
            'address' => [
                'zip' => '54514',
                'city' => 'Butternut',
                'province' => 'Wisconsin',
                'province_code' => 'WI',
                'country' => 'United States',
                'country_code' => 'US',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+14145249343',
            ],
            'payment' => [
                'actual_price_paid' => 39.96,
                'actual_price_paid_currency' => 'USD',
            ],
            'line_items' => [[
                'sku' => 'SKU1',
                'title' => 'Test Product',
                'quantity' => 1,
                'actual_price_paid' => 39.96,
            ]],
            'browser_ip' => '127.0.0.1',
            'user_agent' => 'Test',
            'processed_at' => '2026-04-25 10:00:00',
            'thank_you_page' => 'https://example.com/thanks',
        ];
    }
}
