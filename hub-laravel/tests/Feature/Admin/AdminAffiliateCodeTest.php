<?php

namespace Tests\Feature\Admin;

use App\Models\AffiliateCode;
use App\Models\ShopPool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAffiliateCodeTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->admin()->create()->createToken('auth')->plainTextToken;
    }

    public function test_admin_can_list_codes(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        AffiliateCode::factory()->count(2)->create(['user_id' => $user->id, 'shop_pool_id' => $pool->id]);

        $this->withToken($token)->getJson('/api/admin/affiliate-codes')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'user_id', 'user_email', 'cartpanda_param', 'pool_name', 'active', 'clicks']]])
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_filter_codes_by_user(): void
    {
        $token = $this->adminToken();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $poolA = ShopPool::factory()->for($userA)->create();
        $poolB = ShopPool::factory()->for($userB)->create();
        AffiliateCode::factory()->create(['user_id' => $userA->id, 'shop_pool_id' => $poolA->id]);
        AffiliateCode::factory()->create(['user_id' => $userB->id, 'shop_pool_id' => $poolB->id]);

        $this->withToken($token)->getJson("/api/admin/affiliate-codes?user_id={$userA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_create_code(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();

        $this->withToken($token)->postJson('/api/admin/affiliate-codes', [
            'code' => 'abc123',
            'user_id' => $user->id,
            'shop_pool_id' => $pool->id,
            'label' => 'Mat principal',
        ])->assertStatus(201)
            ->assertJsonPath('data.code', 'abc123')
            ->assertJsonPath('data.label', 'Mat principal');

        $this->assertDatabaseHas('affiliate_codes', ['code' => 'abc123', 'user_id' => $user->id]);
    }

    public function test_create_fails_when_pool_does_not_belong_to_user(): void
    {
        $token = $this->adminToken();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $poolB = ShopPool::factory()->for($userB)->create();

        $this->withToken($token)->postJson('/api/admin/affiliate-codes', [
            'code' => 'mismatch',
            'user_id' => $userA->id,
            'shop_pool_id' => $poolB->id,
        ])->assertStatus(422);
    }

    public function test_create_fails_with_duplicate_code(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $pool = ShopPool::factory()->for($user)->create();
        AffiliateCode::factory()->create(['code' => 'taken', 'user_id' => $user->id, 'shop_pool_id' => $pool->id]);

        $this->withToken($token)->postJson('/api/admin/affiliate-codes', [
            'code' => 'taken',
            'user_id' => $user->id,
            'shop_pool_id' => $pool->id,
        ])->assertStatus(422);
    }

    public function test_admin_can_update_code(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $pool = ShopPool::factory()->for($user)->create();
        $code = AffiliateCode::factory()->create(['user_id' => $user->id, 'shop_pool_id' => $pool->id, 'active' => true]);

        $this->withToken($token)->patchJson("/api/admin/affiliate-codes/{$code->id}", [
            'active' => false,
            'label' => 'Pausado',
        ])->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.label', 'Pausado');
    }

    public function test_admin_can_delete_code(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $pool = ShopPool::factory()->for($user)->create();
        $code = AffiliateCode::factory()->create(['user_id' => $user->id, 'shop_pool_id' => $pool->id]);

        $this->withToken($token)->deleteJson("/api/admin/affiliate-codes/{$code->id}")
            ->assertOk();

        $this->assertDatabaseMissing('affiliate_codes', ['id' => $code->id]);
    }

    public function test_non_admin_forbidden(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/affiliate-codes')
            ->assertForbidden();
    }
}
