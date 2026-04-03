<?php

namespace Tests\Feature\Balance;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\PayoutLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceShopsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_shop_balances_for_multi_shop_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $shopA = CartpandaShop::factory()->create();
        $shopB = CartpandaShop::factory()->create();
        $user->shops()->attach([$shopA->id, $shopB->id]);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopA->id,
            'amount' => 100.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopA->id,
            'amount' => 200.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopB->id,
            'amount' => 50.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $response = $this->withToken($token)->getJson('/api/balance/shops');

        $response->assertOk()
            ->assertJsonCount(2, 'shop_balances');

        $shopBalances = collect($response->json('shop_balances'));

        $balanceA = $shopBalances->firstWhere('shop_id', $shopA->id);
        $this->assertEquals(1, $balanceA['account_index']);
        $this->assertEquals(round(200 * 0.95, 2), $balanceA['balance_pending']);
        $this->assertEquals(round(100 * 0.95, 2), $balanceA['balance_released']);
        $this->assertEquals(round(300 * 0.05, 2), $balanceA['balance_reserve']);

        $balanceB = $shopBalances->firstWhere('shop_id', $shopB->id);
        $this->assertEquals(2, $balanceB['account_index']);
        $this->assertEquals(round(50 * 0.95, 2), $balanceB['balance_pending']);
        $this->assertEquals(0.0, $balanceB['balance_released']);
        $this->assertEquals(round(50 * 0.05, 2), $balanceB['balance_reserve']);
    }

    public function test_returns_empty_for_single_shop_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $shop = CartpandaShop::factory()->create();
        $user->shops()->attach($shop->id);

        $this->withToken($token)->getJson('/api/balance/shops')
            ->assertOk()
            ->assertExactJson(['shop_balances' => []]);
    }

    public function test_returns_empty_for_user_with_no_shops(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/balance/shops')
            ->assertOk()
            ->assertExactJson(['shop_balances' => []]);
    }

    public function test_excludes_non_completed_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $shopA = CartpandaShop::factory()->create();
        $shopB = CartpandaShop::factory()->create();
        $user->shops()->attach([$shopA->id, $shopB->id]);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopA->id,
            'amount' => 100.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);
        CartpandaOrder::factory()->for($user)->pending()->create([
            'shop_id' => $shopA->id,
            'amount' => 500.0,
        ]);

        $response = $this->withToken($token)->getJson('/api/balance/shops');

        $response->assertOk();
        $balanceA = collect($response->json('shop_balances'))->firstWhere('shop_id', $shopA->id);
        $this->assertEquals(round(100 * 0.95, 2), $balanceA['balance_pending']);
    }

    public function test_does_not_leak_other_users_orders(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $shopA = CartpandaShop::factory()->create();
        $shopB = CartpandaShop::factory()->create();
        $user->shops()->attach([$shopA->id, $shopB->id]);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopA->id,
            'amount' => 100.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);
        CartpandaOrder::factory()->for($otherUser)->create([
            'shop_id' => $shopA->id,
            'amount' => 9999.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $response = $this->withToken($token)->getJson('/api/balance/shops');

        $balanceA = collect($response->json('shop_balances'))->firstWhere('shop_id', $shopA->id);
        $this->assertEquals(round(100 * 0.95, 2), $balanceA['balance_pending']);
    }

    public function test_balance_released_deducts_shop_specific_payouts(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $shopA = CartpandaShop::factory()->create();
        $shopB = CartpandaShop::factory()->create();
        $user->shops()->attach([$shopA->id, $shopB->id]);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopA->id,
            'amount' => 1000.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopB->id,
            'amount' => 500.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        PayoutLog::factory()->for($user)->forShop($shopA)->create([
            'admin_user_id' => $admin->id,
            'amount' => -200.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson('/api/balance/shops');

        $response->assertOk();
        $shopBalances = collect($response->json('shop_balances'));

        $balA = $shopBalances->firstWhere('shop_id', $shopA->id);
        // 1000 * 0.95 - 200 = 750
        $this->assertEquals(750.0, $balA['balance_released']);

        $balB = $shopBalances->firstWhere('shop_id', $shopB->id);
        // 500 * 0.95, no payouts
        $this->assertEquals(round(500 * 0.95, 2), $balB['balance_released']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/balance/shops')
            ->assertUnauthorized();
    }
}
