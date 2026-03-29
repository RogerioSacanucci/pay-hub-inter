<?php

namespace Tests\Feature\Balance;

use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_gets_their_balance(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create([
            'balance_pending' => 150.500000,
            'balance_released' => 75.250000,
            'currency' => 'USD',
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/balance')
            ->assertOk()
            ->assertExactJson([
                'balance_pending' => '150.500000',
                'balance_released' => '75.250000',
                'currency' => 'USD',
            ]);
    }

    public function test_user_without_balance_gets_zeros(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/balance')
            ->assertOk()
            ->assertExactJson([
                'balance_pending' => '0.000000',
                'balance_released' => '0.000000',
                'currency' => 'USD',
            ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/balance')
            ->assertUnauthorized();
    }
}
