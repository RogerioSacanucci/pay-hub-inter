<?php

namespace Tests\Feature;

use App\Models\CartpandaOrder;
use App\Models\RevenueMilestone;
use App\Models\User;
use App\Models\UserMilestoneAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zero_progress_without_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);
        RevenueMilestone::factory()->create(['value' => 50000, 'order' => 2]);

        $response = $this->withToken($token)
            ->getJson('/api/milestones/progress');

        $response->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('next_milestone.id', RevenueMilestone::orderBy('order')->first()->id)
            ->assertJsonPath('next_milestone.value', 10000)
            ->assertJsonPath('next_milestone.progress_pct', 0)
            ->assertJsonCount(0, 'achieved')
            ->assertJsonCount(2, 'all_milestones');
    }

    public function test_calculates_correctly_with_completed_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $m1 = RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);
        $m2 = RevenueMilestone::factory()->create(['value' => 50000, 'order' => 2]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 30000, 'status' => 'COMPLETED']);
        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 17200, 'status' => 'COMPLETED']);

        UserMilestoneAchievement::factory()->create([
            'user_id' => $user->id,
            'milestone_id' => $m1->id,
            'total_at_achievement' => 10000,
            'achieved_at' => now()->subDays(30),
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/milestones/progress');

        $response->assertOk()
            ->assertJsonPath('total', 47200)
            ->assertJsonPath('next_milestone.id', $m2->id)
            ->assertJsonPath('next_milestone.value', 50000)
            ->assertJsonPath('next_milestone.progress_pct', 94.4)
            ->assertJsonCount(1, 'achieved')
            ->assertJsonPath('achieved.0.id', $m1->id)
            ->assertJsonPath('achieved.0.value', 10000)
            ->assertJsonPath('all_milestones.0.achieved', true)
            ->assertJsonPath('all_milestones.1.achieved', false);
    }

    public function test_next_milestone_is_null_when_all_achieved(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $m1 = RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);
        $m2 = RevenueMilestone::factory()->create(['value' => 50000, 'order' => 2]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 60000, 'status' => 'COMPLETED']);

        UserMilestoneAchievement::factory()->create([
            'user_id' => $user->id,
            'milestone_id' => $m1->id,
            'total_at_achievement' => 10000,
            'achieved_at' => now()->subDays(60),
        ]);
        UserMilestoneAchievement::factory()->create([
            'user_id' => $user->id,
            'milestone_id' => $m2->id,
            'total_at_achievement' => 50000,
            'achieved_at' => now()->subDays(30),
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/milestones/progress');

        $response->assertOk()
            ->assertJsonPath('total', 60000)
            ->assertJsonPath('next_milestone', null)
            ->assertJsonCount(2, 'achieved')
            ->assertJsonPath('all_milestones.0.achieved', true)
            ->assertJsonPath('all_milestones.1.achieved', true);
    }

    public function test_non_completed_orders_are_not_counted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        RevenueMilestone::factory()->create(['value' => 10000, 'order' => 1]);

        CartpandaOrder::factory()->create(['user_id' => $user->id, 'amount' => 5000, 'status' => 'COMPLETED']);
        CartpandaOrder::factory()->pending()->create(['user_id' => $user->id, 'amount' => 3000]);
        CartpandaOrder::factory()->refunded()->create(['user_id' => $user->id, 'amount' => 2000]);
        CartpandaOrder::factory()->declined()->create(['user_id' => $user->id, 'amount' => 1000]);

        $response = $this->withToken($token)
            ->getJson('/api/milestones/progress');

        $response->assertOk()
            ->assertJsonPath('total', 5000)
            ->assertJsonPath('next_milestone.progress_pct', 50);
    }

    public function test_unauthenticated_request_receives_401(): void
    {
        $response = $this->getJson('/api/milestones/progress');

        $response->assertUnauthorized();
    }
}
