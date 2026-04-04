<?php

namespace Tests\Feature\Admin;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\PayoutLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCartpandaShopsTest extends TestCase
{
    use RefreshDatabase;

    // ── index ────────────────────────────────────────────────────

    public function test_non_admin_cannot_list_shops(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/internacional-shops')
            ->assertForbidden();
    }

    public function test_unauthenticated_cannot_list_shops(): void
    {
        $this->getJson('/api/admin/internacional-shops')->assertUnauthorized();
    }

    public function test_admin_can_list_shops_with_aggregate_stats(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $shop->users()->attach([$user1->id, $user2->id]);

        CartpandaOrder::factory()->create(['shop_id' => $shop->id, 'user_id' => $user1->id, 'status' => 'COMPLETED', 'amount' => 50]);
        CartpandaOrder::factory()->create(['shop_id' => $shop->id, 'user_id' => $user2->id, 'status' => 'PENDING', 'amount' => 30]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops?period=30d');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'shop_slug', 'name', 'users_count', 'orders_count', 'completed', 'total_volume']],
                'period',
            ]);

        $data = $response->json('data.0');
        $this->assertEquals(2, $data['users_count']);
        $this->assertEquals(2, $data['orders_count']);
        $this->assertEquals(1, $data['completed']);
        $this->assertEquals(50.0, $data['total_volume']);
    }

    public function test_shops_list_returns_empty_array_when_no_shops(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops?period=30d');
        $response->assertOk()->assertJson(['data' => []]);
    }

    // ── show ─────────────────────────────────────────────────────

    public function test_admin_can_view_shop_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create(['name' => 'Test Shop']);
        $user = User::factory()->create();
        $shop->users()->attach($user->id);
        CartpandaOrder::factory()->create(['shop_id' => $shop->id, 'user_id' => $user->id, 'status' => 'COMPLETED', 'amount' => 75]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk()
            ->assertJsonStructure([
                'shop' => ['id', 'shop_slug', 'name'],
                'aggregate' => ['total_orders', 'completed', 'pending', 'failed', 'declined', 'refunded', 'total_volume'],
                'chart',
                'users' => [['id', 'email', 'payer_name', 'orders_count', 'completed', 'total_volume', 'balance_pending', 'balance_released']],
                'period',
                'hourly',
            ]);

        $this->assertEquals('Test Shop', $response->json('shop.name'));
        $this->assertEquals(1, $response->json('aggregate.total_orders'));
        $this->assertEquals(75.0, $response->json('aggregate.total_volume'));
        $this->assertCount(1, $response->json('users'));
        $this->assertEquals(75.0, $response->json('users.0.total_volume'));
    }

    public function test_shop_detail_includes_user_balance_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // 1 pending order (not released) + 1 released order for this shop
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 200.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 100.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk();

        // balance_pending = 200 * 0.95 (amount is already net, only reserve applies)
        $this->assertEquals(round(200 * 0.95, 2), $response->json('users.0.balance_pending'));
        // balance_released = 100 * 0.95
        $this->assertEquals(round(100 * 0.95, 2), $response->json('users.0.balance_released'));
    }

    public function test_shop_detail_defaults_balance_to_zero_when_no_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk();

        $this->assertEquals(0.0, $response->json('users.0.balance_pending'));
        $this->assertEquals(0.0, $response->json('users.0.balance_released'));
    }

    public function test_shop_detail_balance_ignores_orders_from_other_shops(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $otherShop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // Order in another shop — must not be counted
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $otherShop->id,
            'amount' => 500.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk();

        $this->assertEquals(0.0, $response->json('users.0.balance_pending'));
        $this->assertEquals(0.0, $response->json('users.0.balance_released'));
    }

    public function test_shop_detail_returns_404_for_unknown_id(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/internacional-shops/9999')
            ->assertNotFound();
    }

    public function test_non_admin_cannot_view_shop_detail(): void
    {
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id)
            ->assertForbidden();
    }

    public function test_shop_detail_balance_released_deducts_shop_specific_payouts(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 1000.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        PayoutLog::factory()->for($user)->forShop($shop)->create([
            'admin_user_id' => $admin->id,
            'amount' => -200.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk();

        // 1000 * 0.95 - 200 = 750
        $this->assertEquals(750.0, $response->json('users.0.balance_released'));
    }

    public function test_shop_detail_balance_released_not_affected_by_null_shop_payouts(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 1000.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        // Payout without shop_id (legacy) — must not affect shop breakdown
        PayoutLog::factory()->for($user)->create([
            'admin_user_id' => $admin->id,
            'shop_id' => null,
            'amount' => -300.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk();

        // null-shop payout must NOT reduce this shop's balance
        $this->assertEquals(round(1000 * 0.95, 2), $response->json('users.0.balance_released'));
    }

    public function test_shop_detail_balance_released_not_affected_by_other_shop_payouts(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $otherShop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 1000.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        // Payout from a different shop — must not affect this shop's balance
        PayoutLog::factory()->for($user)->forShop($otherShop)->create([
            'admin_user_id' => $admin->id,
            'amount' => -500.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=30d');
        $response->assertOk();

        $this->assertEquals(round(1000 * 0.95, 2), $response->json('users.0.balance_released'));
    }
}
