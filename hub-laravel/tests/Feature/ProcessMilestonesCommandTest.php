<?php

namespace Tests\Feature;

use App\Models\CartpandaOrder;
use App\Models\RevenueMilestone;
use App\Models\User;
use App\Models\UserMilestoneAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessMilestonesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_milestones_for_eligible_users(): void
    {
        $user = User::factory()->create();
        $m1 = RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);
        $m2 = RevenueMilestone::factory()->create(['value' => 50000, 'order' => 2]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 30000, 'status' => 'COMPLETED']);

        $this->artisan('milestones:process')
            ->expectsOutputToContain('Created')
            ->assertSuccessful();

        $this->assertDatabaseHas('user_milestone_achievements', [
            'user_id' => $user->id,
            'milestone_id' => $m1->id,
        ]);

        $this->assertDatabaseMissing('user_milestone_achievements', [
            'user_id' => $user->id,
            'milestone_id' => $m2->id,
        ]);
    }

    public function test_records_total_at_achievement(): void
    {
        $user = User::factory()->create();
        RevenueMilestone::factory()->create(['value' => 5000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 8000, 'status' => 'COMPLETED']);

        $this->artisan('milestones:process')->assertSuccessful();

        $achievement = UserMilestoneAchievement::where('user_id', $user->id)->first();
        $this->assertEquals(8000, (float) $achievement->total_at_achievement);
        $this->assertNotNull($achievement->achieved_at);
    }

    public function test_is_idempotent(): void
    {
        $user = User::factory()->create();
        $m1 = RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 15000, 'status' => 'COMPLETED']);

        $this->artisan('milestones:process')->assertSuccessful();
        $this->artisan('milestones:process')->assertSuccessful();

        $this->assertDatabaseCount('user_milestone_achievements', 1);
    }

    public function test_dry_run_does_not_write(): void
    {
        $user = User::factory()->create();
        RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 15000, 'status' => 'COMPLETED']);

        $this->artisan('milestones:process --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would create')
            ->assertSuccessful();

        $this->assertDatabaseCount('user_milestone_achievements', 0);
    }

    public function test_ignores_non_completed_orders(): void
    {
        $user = User::factory()->create();
        RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 5000, 'status' => 'COMPLETED']);
        CartpandaOrder::factory()->pending()->create(['user_id' => $user->id, 'amount' => 8000]);
        CartpandaOrder::factory()->refunded()->create(['user_id' => $user->id, 'amount' => 7000]);

        $this->artisan('milestones:process')->assertSuccessful();

        $this->assertDatabaseCount('user_milestone_achievements', 0);
    }

    public function test_handles_no_milestones(): void
    {
        $this->artisan('milestones:process')
            ->expectsOutputToContain('No milestones configured')
            ->assertSuccessful();
    }

    public function test_processes_multiple_users(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $m1 = RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user1->id, 'amount' => 15000, 'status' => 'COMPLETED']);
        CartpandaOrder::factory()->create(['user_id' => $user2->id, 'amount' => 12000, 'status' => 'COMPLETED']);

        $this->artisan('milestones:process')->assertSuccessful();

        $this->assertDatabaseCount('user_milestone_achievements', 2);
        $this->assertDatabaseHas('user_milestone_achievements', [
            'user_id' => $user1->id,
            'milestone_id' => $m1->id,
        ]);
        $this->assertDatabaseHas('user_milestone_achievements', [
            'user_id' => $user2->id,
            'milestone_id' => $m1->id,
        ]);
    }

    public function test_skips_already_achieved_milestones(): void
    {
        $user = User::factory()->create();
        $m1 = RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 15000, 'status' => 'COMPLETED']);

        UserMilestoneAchievement::factory()->create([
            'user_id' => $user->id,
            'milestone_id' => $m1->id,
            'total_at_achievement' => 10000,
            'achieved_at' => now()->subDays(30),
        ]);

        $this->artisan('milestones:process')
            ->expectsOutputToContain('SKIP')
            ->assertSuccessful();

        $this->assertDatabaseCount('user_milestone_achievements', 1);
    }
}
