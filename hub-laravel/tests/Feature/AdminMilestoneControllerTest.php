<?php

namespace Tests\Feature;

use App\Models\RevenueMilestone;
use App\Models\User;
use App\Models\UserMilestoneAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMilestoneControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->adminToken = $admin->createToken('auth')->plainTextToken;
    }

    public function test_admin_lists_milestones_ordered_by_order(): void
    {
        RevenueMilestone::factory()->create(['value' => 5000, 'order' => 2]);
        RevenueMilestone::factory()->create(['value' => 1000, 'order' => 1]);
        RevenueMilestone::factory()->create(['value' => 10000, 'order' => 3]);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/milestones');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.order', 1)
            ->assertJsonPath('data.1.order', 2)
            ->assertJsonPath('data.2.order', 3);
    }

    public function test_admin_creates_milestone(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', [
                'value' => 5000.00,
                'order' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('milestone.value', '5000.00')
            ->assertJsonPath('milestone.order', 1);

        $this->assertDatabaseHas('revenue_milestones', ['value' => 5000.00, 'order' => 1]);
    }

    public function test_admin_creates_milestone_without_order_defaults_to_next(): void
    {
        RevenueMilestone::factory()->create(['order' => 3]);

        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', [
                'value' => 7500.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('milestone.order', 4);
    }

    public function test_admin_updates_milestone(): void
    {
        $milestone = RevenueMilestone::factory()->create(['value' => 1000, 'order' => 1]);

        $response = $this->withToken($this->adminToken)
            ->putJson("/api/admin/milestones/{$milestone->id}", [
                'value' => 2000.00,
                'order' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('milestone.value', '2000.00')
            ->assertJsonPath('milestone.order', 5);
    }

    public function test_admin_deletes_milestone(): void
    {
        $milestone = RevenueMilestone::factory()->create();

        $response = $this->withToken($this->adminToken)
            ->deleteJson("/api/admin/milestones/{$milestone->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Milestone deleted');

        $this->assertDatabaseMissing('revenue_milestones', ['id' => $milestone->id]);
    }

    public function test_delete_cascades_to_achievements(): void
    {
        $milestone = RevenueMilestone::factory()->create();
        $user = User::factory()->create();
        UserMilestoneAchievement::factory()->create([
            'user_id' => $user->id,
            'milestone_id' => $milestone->id,
        ]);

        $this->withToken($this->adminToken)
            ->deleteJson("/api/admin/milestones/{$milestone->id}")
            ->assertOk();

        $this->assertDatabaseMissing('user_milestone_achievements', ['milestone_id' => $milestone->id]);
    }

    public function test_regular_user_receives_403(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/milestones')->assertForbidden();
        $this->withToken($token)->postJson('/api/admin/milestones', ['value' => 1000])->assertForbidden();
    }

    public function test_unauthenticated_receives_401(): void
    {
        $this->getJson('/api/admin/milestones')->assertUnauthorized();
    }

    public function test_create_requires_value(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_value_must_be_numeric(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', ['value' => 'not-a-number']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }
}
