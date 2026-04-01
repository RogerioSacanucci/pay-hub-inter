<?php

namespace Tests\Feature\Admin;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminCartpandaShopsUtcOffsetTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_index_with_utc_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // 01:00 UTC on Mar 31 = still Mar 30 in UTC-3 → excluded from "today"
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC on Mar 31 = Mar 31 01:00 in UTC-3 → included in "today"
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('data.0.orders_count'));
        $this->assertEquals(100.0, $response->json('data.0.total_volume'));
    }

    public function test_shop_show_with_utc_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // 01:00 UTC on Mar 31 = still Mar 30 in UTC-3 → excluded
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC on Mar 31 = Mar 31 01:00 in UTC-3 → included
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('aggregate.total_orders'));
        $this->assertEquals(100.0, $response->json('aggregate.total_volume'));
    }

    public function test_no_utc_offset_defaults_to_zero(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=today');

        $response->assertOk();
        $this->assertEquals(1, $response->json('aggregate.total_orders'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
