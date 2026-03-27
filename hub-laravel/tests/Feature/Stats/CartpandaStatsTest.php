<?php

namespace Tests\Feature\Stats;

use App\Models\CartpandaOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartpandaStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_counts_statuses_correctly(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        CartpandaOrder::factory()->for($user)->create(['status' => 'COMPLETED', 'amount' => 100.00]);
        CartpandaOrder::factory()->for($user)->refunded()->create(['amount' => 50.00]);
        CartpandaOrder::factory()->for($user)->pending()->create(['amount' => 75.00]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonPath('overview.total_orders', 3)
            ->assertJsonPath('overview.completed', 1)
            ->assertJsonPath('overview.refunded', 1)
            ->assertJsonPath('overview.pending', 1)
            ->assertJsonPath('overview.failed', 0)
            ->assertJsonPath('overview.declined', 0)
            ->assertJsonPath('overview.total_volume', 100);
    }

    public function test_user_sees_only_own_orders(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        $other = User::factory()->withCartpandaParam('afiliado2')->create();
        CartpandaOrder::factory()->for($user)->create(['status' => 'COMPLETED', 'amount' => 100.00]);
        CartpandaOrder::factory()->for($other)->count(5)->create(['status' => 'COMPLETED']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonPath('overview.total_orders', 1)
            ->assertJsonPath('overview.total_volume', 100);
    }

    public function test_admin_sees_all_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->withCartpandaParam('afiliado1')->create();
        $user2 = User::factory()->withCartpandaParam('afiliado2')->create();
        CartpandaOrder::factory()->for($user1)->create(['status' => 'COMPLETED', 'amount' => 100.00]);
        CartpandaOrder::factory()->for($user2)->create(['status' => 'COMPLETED', 'amount' => 200.00]);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk();
        $this->assertEquals(2, $response->json('overview.total_orders'));
        $this->assertEquals(300.00, $response->json('overview.total_volume'));
    }

    public function test_admin_filters_by_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->withCartpandaParam('afiliado1')->create();
        $user2 = User::factory()->withCartpandaParam('afiliado2')->create();
        CartpandaOrder::factory()->for($user1)->create(['status' => 'COMPLETED', 'amount' => 100.00]);
        CartpandaOrder::factory()->for($user2)->create(['status' => 'COMPLETED', 'amount' => 200.00]);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/cartpanda-stats?period=30d&user_id={$user1->id}");

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
        $this->assertEquals(100.00, $response->json('overview.total_volume'));
    }

    public function test_period_today_returns_hourly_chart(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        CartpandaOrder::factory()->for($user)->create(['status' => 'COMPLETED']);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=today');

        $response->assertOk();
        $this->assertTrue($response->json('hourly'));
        $this->assertEquals('today', $response->json('period'));
    }

    public function test_default_period_is_30d(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats');

        $response->assertOk();
        $this->assertEquals('30d', $response->json('period'));
        $this->assertFalse($response->json('hourly'));
    }

    public function test_response_has_correct_structure(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonStructure(['overview', 'chart', 'period', 'hourly']);
    }

    public function test_unauthenticated_user_cannot_access(): void
    {
        $this->getJson('/api/cartpanda-stats')->assertUnauthorized();
    }
}
