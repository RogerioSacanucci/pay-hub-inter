<?php

namespace Tests\Feature\Balance;

use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartpandaStatsBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_global_balance_sums_in_stats(): void
    {
        $admin = User::factory()->admin()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserBalance::factory()->for($user1)->create(['balance_pending' => 100, 'balance_released' => 200, 'balance_reserve' => 10]);
        UserBalance::factory()->for($user2)->create(['balance_pending' => 50, 'balance_released' => 150, 'balance_reserve' => 5]);

        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats');

        $response->assertOk();
        $this->assertEquals('150.000000', $response->json('overview.balance_pending'));
        $this->assertEquals('15.000000', $response->json('overview.balance_reserve'));
        $this->assertEquals('350.000000', $response->json('overview.balance_released'));
    }

    public function test_regular_user_sees_only_own_balance_in_stats(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        UserBalance::factory()->for($user1)->create(['balance_pending' => 100, 'balance_released' => 200, 'balance_reserve' => 10]);
        UserBalance::factory()->for($user2)->create(['balance_pending' => 50, 'balance_released' => 150, 'balance_reserve' => 5]);

        $token = $user1->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats');

        $response->assertOk();
        $this->assertEquals('100.000000', $response->json('overview.balance_pending'));
        $this->assertEquals('10.000000', $response->json('overview.balance_reserve'));
        $this->assertEquals('200.000000', $response->json('overview.balance_released'));
    }

    public function test_user_without_balance_sees_zeros_in_stats(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats');

        $response->assertOk();
        $this->assertEquals('0.000000', $response->json('overview.balance_pending'));
        $this->assertEquals('0.000000', $response->json('overview.balance_reserve'));
        $this->assertEquals('0.000000', $response->json('overview.balance_released'));
    }

    public function test_balance_is_included_in_stats_overview_structure(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats');

        $response->assertOk()->assertJsonStructure([
            'overview' => ['total_orders', 'completed', 'balance_pending', 'balance_reserve', 'balance_released'],
        ]);
    }
}
