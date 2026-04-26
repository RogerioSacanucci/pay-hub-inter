<?php

namespace Tests\Feature\TiktokPixel;

use App\Models\CartpandaOrder;
use App\Models\TiktokOauthConnection;
use App\Models\TiktokPixel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TiktokDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.tiktok.app_id', 'test_app');
        Config::set('services.tiktok.app_secret', 'test_secret');
        Config::set('services.tiktok.open_api_base', 'https://business-api.tiktok.com/open_api/v1.3');
        Cache::flush();
    }

    public function test_discover_returns_flat_pixels_and_advertisers_lists(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111', '222'],
        ]);

        $existing = TiktokPixel::factory()->for($user)->for($conn, 'oauthConnection')->create([
            'pixel_code' => 'CAAA',
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response([
                'code' => 0,
                'data' => ['list' => [
                    ['id' => '111', 'name' => 'Adv One', 'currency' => 'USD'],
                    ['id' => '222', 'name' => 'Adv Two', 'currency' => 'BRL'],
                ]],
            ]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response([
                'code' => 0,
                'data' => ['list' => [
                    ['advertiser_id' => '111', 'balance' => 234.50, 'currency' => 'USD'],
                    ['advertiser_id' => '222', 'balance' => 1000.0, 'currency' => 'BRL'],
                ]],
            ]),
            // Same pixel CAAA shared between advertisers 111 and 222 (typical BC pattern)
            'business-api.tiktok.com/open_api/v1.3/pixel/list/?advertiser_id=111' => Http::response([
                'code' => 0,
                'data' => ['pixels' => [
                    ['pixel_code' => 'CAAA', 'pixel_name' => 'Pixel A'],
                    ['pixel_code' => 'CBBB', 'pixel_name' => 'Pixel B'],
                ]],
            ]),
            'business-api.tiktok.com/open_api/v1.3/pixel/list/?advertiser_id=222' => Http::response([
                'code' => 0,
                'data' => ['pixels' => [
                    ['pixel_code' => 'CAAA', 'pixel_name' => 'Pixel A'],
                ]],
            ]),
        ]);

        $res = $this->actingAs($user)
            ->getJson("/api/tiktok/oauth/connections/{$conn->id}/discover")
            ->assertOk();

        $advertisers = $res->json('data.advertisers');
        $this->assertCount(2, $advertisers);
        $this->assertSame('Adv One', $advertisers[0]['name']);
        $this->assertSame(234.50, $advertisers[0]['balance']);

        $pixels = $res->json('data.pixels');
        // CAAA + CBBB — only 2 unique pixels, even though CAAA appears in 2 advertisers
        $this->assertCount(2, $pixels);

        $caaa = collect($pixels)->firstWhere('pixel_code', 'CAAA');
        $this->assertSame(2, $caaa['shared_with_count']);
        $this->assertTrue($caaa['tracked']);
        $this->assertSame($existing->id, $caaa['tracked_pixel_id']);

        $cbbb = collect($pixels)->firstWhere('pixel_code', 'CBBB');
        $this->assertSame(1, $cbbb['shared_with_count']);
        $this->assertFalse($cbbb['tracked']);
    }

    public function test_discover_returns_403_for_other_users_connection(): void
    {
        $user = User::factory()->create();
        $other = TiktokOauthConnection::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/tiktok/oauth/connections/{$other->id}/discover")
            ->assertForbidden();
    }

    public function test_validate_pixel_returns_valid_when_pixel_in_connection(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111'],
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response([
                'code' => 0,
                'data' => ['list' => [['id' => '111', 'name' => 'Adv One']]],
            ]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response([
                'code' => 0, 'data' => ['list' => []],
            ]),
            'business-api.tiktok.com/open_api/v1.3/pixel/list/*' => Http::response([
                'code' => 0,
                'data' => ['pixels' => [['pixel_code' => 'CKNOWN', 'pixel_name' => 'Known']]],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson("/api/tiktok/oauth/connections/{$conn->id}/validate-pixel", ['pixel_code' => 'CKNOWN'])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.advertiser_id', '111');
    }

    public function test_validate_pixel_returns_invalid_for_unknown_pixel(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111'],
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['id' => '111', 'name' => 'X']]]]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
            'business-api.tiktok.com/open_api/v1.3/pixel/list/*' => Http::response(['code' => 0, 'data' => ['pixels' => []]]),
        ]);

        $this->actingAs($user)
            ->postJson("/api/tiktok/oauth/connections/{$conn->id}/validate-pixel", ['pixel_code' => 'CGHOST'])
            ->assertOk()
            ->assertJsonPath('data.valid', false);
    }

    public function test_track_pixel_creates_pixel_row_when_valid(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111'],
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['id' => '111', 'name' => 'X']]]]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
            'business-api.tiktok.com/open_api/v1.3/pixel/list/*' => Http::response([
                'code' => 0,
                'data' => ['pixels' => [['pixel_code' => 'CTRACK', 'pixel_name' => 'Track Me']]],
            ]),
        ]);

        $this->actingAs($user)
            ->postJson("/api/tiktok/oauth/connections/{$conn->id}/track-pixel", ['pixel_code' => 'CTRACK'])
            ->assertCreated()
            ->assertJsonPath('data.pixel_code', 'CTRACK');

        $this->assertDatabaseHas('tiktok_pixels', [
            'user_id' => $user->id,
            'pixel_code' => 'CTRACK',
            'tiktok_oauth_connection_id' => $conn->id,
        ]);
    }

    public function test_track_pixel_rejects_unknown_pixel(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111'],
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['id' => '111', 'name' => 'X']]]]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
            'business-api.tiktok.com/open_api/v1.3/pixel/list/*' => Http::response(['code' => 0, 'data' => ['pixels' => []]]),
        ]);

        $this->actingAs($user)
            ->postJson("/api/tiktok/oauth/connections/{$conn->id}/track-pixel", ['pixel_code' => 'CGHOST'])
            ->assertUnprocessable();
    }

    public function test_pixel_stats_returns_events_when_pixel_in_connection(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111'],
        ]);
        $pixel = TiktokPixel::factory()->for($user)->for($conn, 'oauthConnection')->create([
            'pixel_code' => 'CSTATS',
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response(['code' => 0, 'data' => ['list' => [['id' => '111', 'name' => 'X']]]]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
            'business-api.tiktok.com/open_api/v1.3/pixel/list/*' => Http::response([
                'code' => 0, 'data' => ['pixels' => [['pixel_code' => 'CSTATS', 'pixel_name' => 'S']]],
            ]),
            'business-api.tiktok.com/open_api/v1.3/pixel/event/stats/*' => Http::response([
                'code' => 0,
                'data' => ['stats' => [
                    ['event' => 'Purchase', 'total' => 38],
                    ['event' => 'ViewContent', 'total' => 305],
                ]],
            ]),
        ]);

        $this->actingAs($user)
            ->getJson("/api/tiktok-pixels/{$pixel->id}/stats?days=7")
            ->assertOk()
            ->assertJsonPath('data.events.Purchase', 38)
            ->assertJsonPath('data.events.ViewContent', 305);
    }

    public function test_pixel_stats_returns_unavailable_without_oauth_connection(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson("/api/tiktok-pixels/{$pixel->id}/stats")
            ->assertOk()
            ->assertJsonPath('data.unavailable', 'no_oauth_connection');
    }

    public function test_roas_returns_combined_spend_and_revenue(): void
    {
        $user = User::factory()->create();
        $conn = TiktokOauthConnection::factory()->for($user)->create([
            'advertiser_ids' => ['111'],
        ]);

        // CartPanda orders inside window
        CartpandaOrder::factory()->for($user)->create([
            'amount' => 39.96,
            'status' => 'COMPLETED',
            'created_at' => now()->subDays(2),
        ]);
        CartpandaOrder::factory()->for($user)->create([
            'amount' => 100.00,
            'status' => 'COMPLETED',
            'created_at' => now()->subDays(1),
        ]);
        // Pending order should not count
        CartpandaOrder::factory()->for($user)->create([
            'amount' => 999,
            'status' => 'PENDING',
            'created_at' => now()->subDays(1),
        ]);

        Http::fake([
            'business-api.tiktok.com/open_api/v1.3/advertiser/info/*' => Http::response([
                'code' => 0, 'data' => ['list' => [['id' => '111', 'name' => 'X', 'currency' => 'USD']]],
            ]),
            'business-api.tiktok.com/open_api/v1.3/advertiser/balance/get/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
            'business-api.tiktok.com/open_api/v1.3/report/integrated/get/*' => Http::response([
                'code' => 0,
                'data' => ['list' => [
                    ['dimensions' => ['advertiser_id' => '111', 'stat_time_day' => now()->subDays(2)->format('Y-m-d')], 'metrics' => ['spend' => 25.0]],
                    ['dimensions' => ['advertiser_id' => '111', 'stat_time_day' => now()->subDays(1)->format('Y-m-d')], 'metrics' => ['spend' => 50.0]],
                ]],
            ]),
        ]);

        $from = now()->subDays(7)->format('Y-m-d');
        $to = now()->format('Y-m-d');

        $res = $this->actingAs($user)
            ->getJson("/api/tiktok/reports/roas?date_from={$from}&date_to={$to}")
            ->assertOk();

        $this->assertEquals(75, $res->json('data.total_spend'));
        $this->assertEquals(139.96, $res->json('data.total_revenue'));
        $this->assertEqualsWithDelta(1.87, $res->json('data.roas'), 0.01);
        $this->assertSame(2, $res->json('data.orders'));
    }

    public function test_roas_today_period_with_utc_offset_excludes_yesterday(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-04-25 18:00:00');

        $user = User::factory()->create();
        TiktokOauthConnection::factory()->for($user)->create(['advertiser_ids' => []]);

        // Today UTC at noon — should be in window
        CartpandaOrder::factory()->for($user)->create([
            'amount' => 50.00,
            'status' => 'COMPLETED',
            'created_at' => '2026-04-25 12:00:00',
        ]);
        // 3 days ago — must NOT be in window
        CartpandaOrder::factory()->for($user)->create([
            'amount' => 999.99,
            'status' => 'COMPLETED',
            'created_at' => '2026-04-22 12:00:00',
        ]);

        Http::fake([
            'business-api.tiktok.com/*' => Http::response(['code' => 0, 'data' => ['list' => []]]),
        ]);

        $res = $this->actingAs($user)
            ->getJson('/api/tiktok/reports/roas?period=today&utc_offset=0')
            ->assertOk();

        $this->assertEquals(50.00, $res->json('data.total_revenue'));
        $this->assertSame(1, $res->json('data.orders'));

        \Illuminate\Support\Carbon::setTestNow();
    }
}
