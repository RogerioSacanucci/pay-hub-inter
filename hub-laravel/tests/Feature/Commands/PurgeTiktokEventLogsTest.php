<?php

namespace Tests\Feature\Commands;

use App\Models\TiktokEventLog;
use App\Models\TiktokPixel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeTiktokEventLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purges_logs_older_than_two_days(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        $old = TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->create([
            'created_at' => now()->subDays(3),
        ]);
        $borderline = TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->create([
            'created_at' => now()->subDays(2)->subMinute(),
        ]);
        $fresh = TiktokEventLog::factory()->for($user)->for($pixel, 'pixel')->create([
            'created_at' => now()->subHour(),
        ]);

        $this->artisan('app:purge-tiktok-event-logs')->assertOk();

        $this->assertDatabaseMissing('tiktok_events_log', ['id' => $old->id]);
        $this->assertDatabaseMissing('tiktok_events_log', ['id' => $borderline->id]);
        $this->assertDatabaseHas('tiktok_events_log', ['id' => $fresh->id]);
    }
}
