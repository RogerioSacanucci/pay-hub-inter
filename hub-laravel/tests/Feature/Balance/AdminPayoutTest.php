<?php

namespace Tests\Feature\Balance;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_user_balance_and_payout_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create([
            'balance_pending' => 120.5,
            'balance_released' => 340.0,
        ]);

        PayoutLog::factory()->for($user)->create([
            'admin_user_id' => $admin->id,
            'amount' => -200.0,
            'type' => 'withdrawal',
            'note' => 'Test payout',
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk()
            ->assertJsonStructure([
                'balance' => ['balance_pending', 'balance_reserve', 'balance_released', 'currency'],
                'payout_logs' => [
                    'data' => [['id', 'amount', 'type', 'note', 'admin_email', 'shop_name', 'created_at']],
                    'meta' => ['total', 'page', 'per_page', 'pages'],
                ],
            ])
            ->assertJsonPath('balance.balance_pending', '120.500000')
            ->assertJsonPath('balance.balance_released', '340.000000')
            ->assertJsonPath('payout_logs.meta.total', 1);
    }

    public function test_admin_can_view_balance_for_user_without_balance_record(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk()
            ->assertJsonPath('balance.balance_pending', '0.000000')
            ->assertJsonPath('balance.balance_released', '0.000000')
            ->assertJsonPath('balance.currency', 'USD')
            ->assertJsonPath('payout_logs.data', []);
    }

    public function test_admin_can_create_withdrawal_payout(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create([
            'balance_pending' => 0,
            'balance_released' => 500.0,
        ]);

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 200.00,
            'type' => 'withdrawal',
            'note' => 'Monthly payout',
        ]);

        $response->assertOk()
            ->assertJsonPath('balance.balance_released', '300.000000');

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'type' => 'withdrawal',
            'note' => 'Monthly payout',
        ]);
    }

    public function test_admin_can_create_adjustment_payout(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create([
            'balance_pending' => 0,
            'balance_released' => 100.0,
        ]);

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 50.00,
            'type' => 'adjustment',
            'note' => 'Correction',
        ]);

        $response->assertOk()
            ->assertJsonPath('balance.balance_released', '150.000000');

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'type' => 'adjustment',
        ]);
    }

    public function test_payout_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'type']);
    }

    public function test_payout_validates_type_enum(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_payout_validates_note_max_length(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'withdrawal',
            'note' => str_repeat('a', 501),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_non_admin_cannot_access_balance(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance")
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_create_payout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'withdrawal',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_balance(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/admin/users/{$user->id}/balance")->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_create_payout(): void
    {
        $user = User::factory()->create();

        $this->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'withdrawal',
        ])->assertUnauthorized();
    }

    public function test_returns_404_for_nonexistent_user(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/users/99999/balance')
            ->assertNotFound();

        $this->withToken($token)->postJson('/api/admin/users/99999/payout', [
            'amount' => 100,
            'type' => 'withdrawal',
        ])->assertNotFound();
    }

    public function test_balance_response_includes_shop_balances_for_multi_shop_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();

        $shopA = CartpandaShop::factory()->create(['name' => 'Shop A']);
        $shopB = CartpandaShop::factory()->create(['name' => 'Shop B']);
        $user->shops()->attach([$shopA->id, $shopB->id]);

        // Shop A: 2 orders, one released, one pending
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

        // Shop B: 1 order pending
        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shopB->id,
            'amount' => 50.0,
            'status' => 'COMPLETED',
            'released_at' => null,
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk()
            ->assertJsonCount(2, 'shop_balances');

        $shopBalances = collect($response->json('shop_balances'));

        $balanceA = $shopBalances->firstWhere('shop_id', $shopA->id);
        $this->assertEquals('Shop A', $balanceA['shop_name']);
        // amount is already net; released = 100 * 0.95 = 95
        $this->assertEquals(round(100 * 0.95, 2), $balanceA['balance_released']);
        // pending = 200 * 0.95 = 190
        $this->assertEquals(round(200 * 0.95, 2), $balanceA['balance_pending']);
        // reserve = (100 + 200) * 0.05 = 15
        $this->assertEquals(round(300 * 0.05, 2), $balanceA['balance_reserve']);

        $balanceB = $shopBalances->firstWhere('shop_id', $shopB->id);
        $this->assertEquals('Shop B', $balanceB['shop_name']);
        $this->assertEquals(round(50 * 0.95, 2), $balanceB['balance_pending']);
        $this->assertEquals(0.0, $balanceB['balance_released']);
        $this->assertEquals(round(50 * 0.05, 2), $balanceB['balance_reserve']);
    }

    public function test_balance_response_includes_shop_balances_for_single_shop_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();

        $shop = CartpandaShop::factory()->create(['name' => 'My Shop']);
        $user->shops()->attach($shop->id);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 100.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk()
            ->assertJsonCount(1, 'shop_balances')
            ->assertJsonPath('shop_balances.0.shop_name', 'My Shop');

        $this->assertEquals(round(100 * 0.95, 2), $response->json('shop_balances.0.balance_released'));
    }

    public function test_shop_balance_released_deducts_shop_specific_payouts(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();

        $shopA = CartpandaShop::factory()->create(['name' => 'Shop A']);
        $shopB = CartpandaShop::factory()->create(['name' => 'Shop B']);
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

        // Withdrawal from Shop A only
        PayoutLog::factory()->for($user)->forShop($shopA)->create([
            'admin_user_id' => $admin->id,
            'amount' => -200.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk();
        $shopBalances = collect($response->json('shop_balances'));

        $balA = $shopBalances->firstWhere('shop_id', $shopA->id);
        // 1000 * 0.95 - 200 = 750
        $this->assertEquals(750.0, $balA['balance_released']);

        $balB = $shopBalances->firstWhere('shop_id', $shopB->id);
        // 500 * 0.95, no payouts
        $this->assertEquals(round(500 * 0.95, 2), $balB['balance_released']);
    }

    public function test_shop_balance_released_not_affected_by_null_shop_payouts(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();

        $shop = CartpandaShop::factory()->create(['name' => 'Shop A']);
        $user->shops()->attach($shop->id);

        CartpandaOrder::factory()->for($user)->create([
            'shop_id' => $shop->id,
            'amount' => 1000.0,
            'status' => 'COMPLETED',
            'released_at' => now(),
        ]);

        // Payout with no shop_id (legacy)
        PayoutLog::factory()->for($user)->create([
            'admin_user_id' => $admin->id,
            'shop_id' => null,
            'amount' => -300.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk();
        $bal = collect($response->json('shop_balances'))->firstWhere('shop_id', $shop->id);
        // null-shop payout does NOT affect shop breakdown
        $this->assertEquals(round(1000 * 0.95, 2), $bal['balance_released']);
    }

    public function test_withdrawal_stores_shop_id_in_payout_log(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create(['balance_released' => 500.0]);

        $shop = CartpandaShop::factory()->create();

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100.0,
            'type' => 'withdrawal',
            'shop_id' => $shop->id,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'type' => 'withdrawal',
        ]);
    }

    public function test_withdrawal_without_shop_id_is_valid(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create(['balance_released' => 500.0]);

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100.0,
            'type' => 'withdrawal',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'shop_id' => null,
            'type' => 'withdrawal',
        ]);
    }

    public function test_payout_log_includes_shop_name(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();

        $shop = CartpandaShop::factory()->create(['name' => 'My Shop']);

        PayoutLog::factory()->for($user)->forShop($shop)->create([
            'admin_user_id' => $admin->id,
            'amount' => -100.0,
            'type' => 'withdrawal',
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk();

        $log = $response->json('payout_logs.data.0');
        $this->assertEquals('My Shop', $log['shop_name']);
    }

    public function test_payout_logs_are_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();
        PayoutLog::factory()->for($user)->count(25)->create([
            'admin_user_id' => $admin->id,
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance?page=1");

        $response->assertOk()
            ->assertJsonPath('payout_logs.meta.total', 25)
            ->assertJsonPath('payout_logs.meta.per_page', 20)
            ->assertJsonPath('payout_logs.meta.pages', 2);
        $this->assertCount(20, $response->json('payout_logs.data'));

        $page2 = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance?page=2");
        $this->assertCount(5, $page2->json('payout_logs.data'));
    }
}
