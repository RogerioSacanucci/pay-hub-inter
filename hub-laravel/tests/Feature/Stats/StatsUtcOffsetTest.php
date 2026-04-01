<?php

namespace Tests\Feature\Stats;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatsUtcOffsetTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_with_negative_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        // 01:00 UTC on Mar 31 = still Mar 30 in UTC-3 → should be excluded from "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC on Mar 31 = Mar 31 01:00 in UTC-3 → should be included in "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_today_with_positive_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        // 20:00 UTC on Mar 30 = Mar 31 01:00 in UTC+5 → should be included in "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 75,
            'created_at' => '2026-03-30 20:00:00',
        ]);
        // 19:30 UTC on Mar 31 = Apr 01 00:30 in UTC+5 → should be excluded from "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 200,
            'created_at' => '2026-03-31 19:30:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today&utc_offset=5');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(75.0, $response->json('overview.total_volume'));
    }

    public function test_custom_period_with_offset_shifts_boundaries(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        // 02:00 UTC = still Mar 30 in UTC-3 → excluded
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'created_at' => '2026-03-31 02:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=custom&date_from=2026-03-31&date_to=2026-03-31&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_no_utc_offset_defaults_to_zero(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
