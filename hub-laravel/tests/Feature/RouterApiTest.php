<?php

namespace Tests\Feature;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-router-key';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'routing.router_api_key' => self::KEY,
            'routing.default_fallback' => 'https://fallback.example.com',
        ]);
    }

    public function test_pick_returns_shop_and_token_for_known_affiliate(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        $shop = CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'routing_priority' => 1,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout/x',
        ]);

        $response = $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/pick/mat1');

        $response->assertOk()
            ->assertJsonStructure(['shop_slug', 'ck_url', 'token'])
            ->assertJsonPath('shop_slug', 'nutra')
            ->assertJsonPath('ck_url', 'https://nutra.mycartpanda.com/ck');
    }

    public function test_pick_returns_404_for_unknown_affiliate(): void
    {
        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/pick/UNKNOWN')
            ->assertStatus(404)
            ->assertJsonPath('error', 'affiliate_not_found');
    }

    public function test_pick_returns_503_when_no_active_shops(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/pick/mat1')
            ->assertStatus(503)
            ->assertJsonPath('error', 'no_active_shops');
    }

    public function test_pick_returns_503_when_all_capped(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $shop = CartpandaShop::factory()->create([
            'active_for_routing' => true,
            'routing_priority' => 1,
            'daily_cap' => 50,
            'default_checkout_template' => 'https://capped.mycartpanda.com/checkout',
        ]);
        CartpandaOrder::factory()->create([
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'status' => 'COMPLETED',
            'amount' => 60.0,
            'created_at' => now(),
        ]);

        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/pick/mat1')
            ->assertStatus(503)
            ->assertJsonPath('error', 'all_capped');
    }

    public function test_pick_rejects_request_without_api_key(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $this->getJson('/api/router/pick/mat1')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');
    }

    public function test_pick_rejects_request_with_wrong_api_key(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $this->withHeader('X-Router-Key', 'wrong-key')
            ->getJson('/api/router/pick/mat1')
            ->assertStatus(401);
    }

    public function test_pick_mints_fresh_token_each_request(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout',
        ]);

        $tokenA = $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/pick/mat1')->json('token');

        $tokenB = $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/pick/mat1')->json('token');

        $this->assertNotSame($tokenA, $tokenB);
    }
}
