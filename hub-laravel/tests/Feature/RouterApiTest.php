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

    public function test_resolve_returns_shop_ck_url_and_final_url_with_opaque_affiliate_token(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'routing_priority' => 1,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout/x?id=1',
        ]);

        $response = $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1');

        $response->assertOk()
            ->assertJsonStructure(['shop_slug', 'ck_url', 'final_url'])
            ->assertJsonPath('shop_slug', 'nutra')
            ->assertJsonPath('ck_url', 'https://nutra.mycartpanda.com/ck');

        $finalUrl = (string) $response->json('final_url');
        $this->assertStringStartsWith('https://nutra.mycartpanda.com/checkout/x?id=1&affiliate=', $finalUrl);
        $this->assertStringNotContainsString('affiliate=mat1', $finalUrl, 'cartpanda_param must not be exposed in URL');
    }

    public function test_resolve_mints_fresh_affiliate_token_each_request(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout/x',
        ]);

        $a = $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1')->json('final_url');
        $b = $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1')->json('final_url');

        $this->assertNotSame($a, $b);
    }

    public function test_resolve_picks_priority_one_first(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'second',
            'active_for_routing' => true,
            'routing_priority' => 2,
            'default_checkout_template' => 'https://second.mycartpanda.com/checkout/y',
        ]);
        CartpandaShop::factory()->create([
            'shop_slug' => 'first',
            'active_for_routing' => true,
            'routing_priority' => 1,
            'default_checkout_template' => 'https://first.mycartpanda.com/checkout/x',
        ]);

        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1')
            ->assertOk()
            ->assertJsonPath('shop_slug', 'first');
    }

    public function test_resolve_falls_over_when_first_capped(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $capped = CartpandaShop::factory()->create([
            'shop_slug' => 'capped',
            'active_for_routing' => true,
            'routing_priority' => 1,
            'daily_cap' => 100,
            'default_checkout_template' => 'https://capped.mycartpanda.com/checkout/x',
        ]);
        CartpandaShop::factory()->create([
            'shop_slug' => 'overflow',
            'active_for_routing' => true,
            'routing_priority' => 2,
            'default_checkout_template' => 'https://overflow.mycartpanda.com/checkout/y',
        ]);

        CartpandaOrder::factory()->create([
            'user_id' => $user->id,
            'shop_id' => $capped->id,
            'status' => 'COMPLETED',
            'amount' => 100.0,
            'created_at' => now(),
        ]);

        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1')
            ->assertOk()
            ->assertJsonPath('shop_slug', 'overflow');
    }

    public function test_resolve_returns_404_for_unknown_affiliate(): void
    {
        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/UNKNOWN')
            ->assertStatus(404)
            ->assertJsonPath('error', 'affiliate_not_found');
    }

    public function test_resolve_returns_503_when_no_active_shops(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1')
            ->assertStatus(503)
            ->assertJsonPath('error', 'no_active_shops');
    }

    public function test_resolve_returns_503_when_all_capped(): void
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
            ->getJson('/api/router/resolve/mat1')
            ->assertStatus(503)
            ->assertJsonPath('error', 'all_capped');
    }

    public function test_resolve_uses_custom_ck_url_when_set(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'custom',
            'active_for_routing' => true,
            'ck_url' => 'https://gateway.example.com/ck',
            'default_checkout_template' => 'https://custom.mycartpanda.com/checkout',
        ]);

        $this->withHeader('X-Router-Key', self::KEY)
            ->getJson('/api/router/resolve/mat1')
            ->assertOk()
            ->assertJsonPath('ck_url', 'https://gateway.example.com/ck');
    }

    public function test_resolve_rejects_request_without_api_key(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $this->getJson('/api/router/resolve/mat1')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');
    }

    public function test_resolve_rejects_request_with_wrong_api_key(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $this->withHeader('X-Router-Key', 'wrong-key')
            ->getJson('/api/router/resolve/mat1')
            ->assertStatus(401);
    }
}
