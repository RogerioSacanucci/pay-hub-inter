<?php

namespace Tests\Feature\Stats;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_own_stats(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user->id, 'amount' => 100]);
        Transaction::factory()->completed()->create(['user_id' => $other->id, 'amount' => 200]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk()
            ->assertJsonStructure(['overview', 'chart', 'methods', 'period', 'hourly']);
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_admin_sees_all_stats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user1->id, 'amount' => 100]);
        Transaction::factory()->completed()->create(['user_id' => $user2->id, 'amount' => 200]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk();
        $this->assertEquals(2, $response->json('overview.total_transactions'));
        $this->assertEquals(300.0, $response->json('overview.total_volume'));
    }

    public function test_stats_period_today_returns_hourly(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today');
        $response->assertOk();
        $this->assertTrue($response->json('hourly'));
        $this->assertEquals('today', $response->json('period'));
    }

    public function test_stats_default_period_is_30d(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk();
        $this->assertEquals('30d', $response->json('period'));
        $this->assertFalse($response->json('hourly'));
    }

    public function test_stats_overview_counts_statuses(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);
        Transaction::factory()->failed()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'DECLINED']);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk();
        $this->assertEquals(4, $response->json('overview.total_transactions'));
        $this->assertEquals(1, $response->json('overview.completed'));
        $this->assertEquals(1, $response->json('overview.pending'));
        $this->assertEquals(1, $response->json('overview.failed'));
        $this->assertEquals(1, $response->json('overview.declined'));
    }

    public function test_unauthenticated_user_cannot_access_stats(): void
    {
        $this->getJson('/api/stats')->assertUnauthorized();
    }
}
