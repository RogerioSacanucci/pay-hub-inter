<?php

namespace Tests\Feature;

use App\Models\CartpandaShop;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_own_balance_and_payouts(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => 100.50,
            'balance_reserve' => 25.00,
            'balance_released' => 200.75,
            'currency' => 'USD',
        ]);

        $shop = CartpandaShop::factory()->create(['name' => 'Test Shop']);
        PayoutLog::factory()->for($user)->forShop($shop)->count(3)->create();

        $response = $this->withToken($token)
            ->getJson('/api/payouts');

        $response->assertOk()
            ->assertJsonStructure([
                'balance' => ['balance_pending', 'balance_reserve', 'balance_released', 'currency'],
                'payout_logs' => [
                    'data' => [['id', 'amount', 'type', 'note', 'shop_name', 'created_at']],
                    'meta' => ['total', 'page', 'per_page', 'pages'],
                ],
            ])
            ->assertJsonPath('balance.currency', 'USD')
            ->assertJsonCount(3, 'payout_logs.data')
            ->assertJsonPath('payout_logs.meta.total', 3);
    }

    public function test_user_sees_only_own_payouts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        PayoutLog::factory()->for($user)->count(2)->create();
        PayoutLog::factory()->for($otherUser)->count(3)->create();

        $response = $this->withToken($token)
            ->getJson('/api/payouts');

        $response->assertOk()
            ->assertJsonCount(2, 'payout_logs.data')
            ->assertJsonPath('payout_logs.meta.total', 2);
    }

    public function test_balance_created_automatically_if_not_exists(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->assertDatabaseMissing('user_balances', ['user_id' => $user->id]);

        $response = $this->withToken($token)
            ->getJson('/api/payouts');

        $response->assertOk()
            ->assertJsonPath('balance.balance_pending', '0.000000')
            ->assertJsonPath('balance.balance_reserve', '0.000000')
            ->assertJsonPath('balance.balance_released', '0.000000')
            ->assertJsonPath('balance.currency', 'USD');

        $this->assertDatabaseHas('user_balances', ['user_id' => $user->id]);
    }

    public function test_unauthenticated_request_receives_401(): void
    {
        $response = $this->getJson('/api/payouts');

        $response->assertUnauthorized();
    }
}
