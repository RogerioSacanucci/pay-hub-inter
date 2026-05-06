<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateCode;
use App\Models\CartpandaShop;
use App\Models\ShopPool;
use App\Models\ShopPoolTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminShopPoolTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth')->plainTextToken;
    }

    public function test_admin_can_list_pools_with_targets(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $pool = ShopPool::factory()->for($user)->create();
        ShopPoolTarget::factory()->count(2)->create(['shop_pool_id' => $pool->id]);

        $this->withToken($token)->getJson('/api/admin/shop-pools')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'cap_period', 'targets']]])
            ->assertJsonPath('data.0.targets.0.checkout_template', $pool->targets->first()->checkout_template);
    }

    public function test_admin_can_create_pool(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();

        $this->withToken($token)->postJson('/api/admin/shop-pools', [
            'user_id' => $user->id,
            'name' => 'Mat-balance',
            'cap_period' => 'day',
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Mat-balance');

        $this->assertDatabaseHas('shop_pools', ['user_id' => $user->id, 'name' => 'Mat-balance']);
    }

    public function test_create_fails_with_duplicate_pool_name_for_user(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        ShopPool::factory()->for($user)->create(['name' => 'Mat-balance']);

        $this->withToken($token)->postJson('/api/admin/shop-pools', [
            'user_id' => $user->id,
            'name' => 'Mat-balance',
        ])->assertStatus(422);
    }

    public function test_admin_can_create_target_under_pool(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shop = CartpandaShop::factory()->create();

        $this->withToken($token)->postJson("/api/admin/shop-pools/{$pool->id}/targets", [
            'shop_id' => $shop->id,
            'checkout_template' => 'https://shop.test/checkout',
            'priority' => 1,
            'daily_cap' => 10000,
        ])->assertStatus(201)
            ->assertJsonPath('data.shop_id', $shop->id)
            ->assertJsonPath('data.priority', 1);
    }

    public function test_create_target_with_overflow_succeeds_when_no_other_overflow(): void
    {
        $token = $this->adminToken();
        $pool = ShopPool::factory()->create();
        $shop = CartpandaShop::factory()->create();

        $this->withToken($token)->postJson("/api/admin/shop-pools/{$pool->id}/targets", [
            'shop_id' => $shop->id,
            'checkout_template' => 'https://x.test/co',
            'is_overflow' => true,
        ])->assertStatus(201);
    }

    public function test_create_second_overflow_rejected(): void
    {
        $token = $this->adminToken();
        $pool = ShopPool::factory()->create();
        ShopPoolTarget::factory()->overflow()->create(['shop_pool_id' => $pool->id]);
        $shop = CartpandaShop::factory()->create();

        $this->withToken($token)->postJson("/api/admin/shop-pools/{$pool->id}/targets", [
            'shop_id' => $shop->id,
            'checkout_template' => 'https://x.test/co',
            'is_overflow' => true,
        ])->assertStatus(422);
    }

    public function test_admin_can_update_target(): void
    {
        $token = $this->adminToken();
        $pool = ShopPool::factory()->create();
        $target = ShopPoolTarget::factory()->create(['shop_pool_id' => $pool->id, 'daily_cap' => 1000]);

        $this->withToken($token)->patchJson("/api/admin/shop-pools/{$pool->id}/targets/{$target->id}", [
            'daily_cap' => 5000,
            'active' => false,
        ])->assertOk()
            ->assertJsonPath('data.daily_cap', '5000.00')
            ->assertJsonPath('data.active', false);
    }

    public function test_admin_can_delete_target(): void
    {
        $token = $this->adminToken();
        $pool = ShopPool::factory()->create();
        $target = ShopPoolTarget::factory()->create(['shop_pool_id' => $pool->id]);

        $this->withToken($token)->deleteJson("/api/admin/shop-pools/{$pool->id}/targets/{$target->id}")
            ->assertOk();

        $this->assertDatabaseMissing('shop_pool_targets', ['id' => $target->id]);
    }

    public function test_admin_can_delete_pool(): void
    {
        $token = $this->adminToken();
        $pool = ShopPool::factory()->create();
        ShopPoolTarget::factory()->create(['shop_pool_id' => $pool->id]);

        $this->withToken($token)->deleteJson("/api/admin/shop-pools/{$pool->id}")
            ->assertOk();

        $this->assertDatabaseMissing('shop_pools', ['id' => $pool->id]);
        $this->assertDatabaseCount('shop_pool_targets', 0);
    }

    public function test_delete_pool_blocked_when_codes_reference_it(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $pool = ShopPool::factory()->for($user)->create();
        AffiliateCode::factory()->create(['user_id' => $user->id, 'shop_pool_id' => $pool->id]);

        $this->withToken($token)->deleteJson("/api/admin/shop-pools/{$pool->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('shop_pools', ['id' => $pool->id]);
    }

    public function test_target_route_404_when_target_not_in_pool(): void
    {
        $token = $this->adminToken();
        $poolA = ShopPool::factory()->create();
        $poolB = ShopPool::factory()->create();
        $target = ShopPoolTarget::factory()->create(['shop_pool_id' => $poolB->id]);

        $this->withToken($token)->patchJson("/api/admin/shop-pools/{$poolA->id}/targets/{$target->id}", [
            'priority' => 5,
        ])->assertNotFound();
    }
}
