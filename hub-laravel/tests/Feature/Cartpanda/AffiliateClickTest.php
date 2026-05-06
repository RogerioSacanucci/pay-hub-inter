<?php

namespace Tests\Feature\Cartpanda;

use App\Models\AffiliateCode;
use App\Models\CartpandaShop;
use App\Models\ShopPool;
use App\Models\ShopPoolTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateClickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['routing.default_fallback' => 'https://fallback.example.com']);
    }

    public function test_returns_url_for_valid_code(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shop = CartpandaShop::factory()->create(['shop_slug' => 'nutra']);
        ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shop->id,
            'checkout_template' => 'https://nutra.test/co?id=1',
        ]);
        AffiliateCode::factory()->create([
            'code' => 'abc123',
            'user_id' => $user->id,
            'shop_pool_id' => $pool->id,
        ]);

        $this->getJson('/api/click/abc123')
            ->assertOk()
            ->assertJson([
                'url' => 'https://nutra.test/co?id=1&affiliate=mat1',
                'shop_slug' => 'nutra',
                'code' => 'abc123',
            ]);
    }

    public function test_returns_404_with_fallback_for_unknown_code(): void
    {
        $this->getJson('/api/click/missing')
            ->assertStatus(404)
            ->assertJson([
                'error' => 'code_not_found',
                'fallback_url' => 'https://fallback.example.com',
            ]);
    }

    public function test_returns_503_when_no_active_targets(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        ShopPoolTarget::factory()->inactive()->create(['shop_pool_id' => $pool->id]);
        AffiliateCode::factory()->create([
            'code' => 'empty',
            'user_id' => $user->id,
            'shop_pool_id' => $pool->id,
        ]);

        $this->getJson('/api/click/empty')
            ->assertStatus(503)
            ->assertJsonPath('error', 'no_active_targets');
    }

    public function test_endpoint_is_public_no_auth_required(): void
    {
        // No actingAs / no token — should still work
        $user = User::factory()->withCartpandaParam('x')->create();
        $pool = ShopPool::factory()->for($user)->create();
        ShopPoolTarget::factory()->uncapped()->create(['shop_pool_id' => $pool->id]);
        AffiliateCode::factory()->create([
            'code' => 'pub', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $this->getJson('/api/click/pub')->assertOk();
    }
}
