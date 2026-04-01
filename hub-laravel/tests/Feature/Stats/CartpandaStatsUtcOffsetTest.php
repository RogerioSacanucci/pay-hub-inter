<?php

namespace Tests\Feature\Stats;

use App\Models\CartpandaOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CartpandaStatsUtcOffsetTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_with_negative_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        // 23:30 DB-time on Mar 30 = yesterday in UTC-3 → should be excluded from "today"
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-30 23:30:00',
        ]);
        // 00:30 DB-time on Mar 31 = today in UTC-3 → should be included in "today"
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 00:30:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_today_with_positive_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        // 20:00 UTC on Mar 30 = Mar 31 01:00 in UTC+5 → should be included in "today"
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 75,
            'created_at' => '2026-03-30 20:00:00',
        ]);
        // 19:30 UTC on Mar 31 = Apr 01 00:30 in UTC+5 → should be excluded from "today"
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 200,
            'created_at' => '2026-03-31 19:30:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=today&utc_offset=5');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
        $this->assertEquals(75.0, $response->json('overview.total_volume'));
    }

    public function test_no_utc_offset_defaults_to_zero(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=today');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
