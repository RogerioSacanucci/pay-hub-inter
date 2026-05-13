<?php

namespace Tests\Feature;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateClickRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['routing.default_fallback' => 'https://fallback.example.com']);
    }

    public function test_r_redirects_to_active_shop_by_priority(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $priorityOne = CartpandaShop::factory()->create([
            'shop_slug' => 'priority-one',
            'active_for_routing' => true,
            'routing_priority' => 1,
            'default_checkout_template' => 'https://priority-one.mycartpanda.com/checkout/x',
        ]);
        CartpandaShop::factory()->create([
            'shop_slug' => 'priority-two',
            'active_for_routing' => true,
            'routing_priority' => 2,
            'default_checkout_template' => 'https://priority-two.mycartpanda.com/checkout/y',
        ]);

        $response = $this->get('/r/mat1');

        $response->assertStatus(302);
        $this->assertStringStartsWith(
            'https://priority-one.mycartpanda.com/ck?c=',
            $response->headers->get('Location')
        );
    }

    public function test_r_falls_over_when_first_shop_capped(): void
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

        $response = $this->get('/r/mat1');

        $response->assertStatus(302);
        $this->assertStringStartsWith(
            'https://overflow.mycartpanda.com/ck?c=',
            $response->headers->get('Location')
        );
    }

    public function test_r_mints_fresh_token_each_request(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout/x',
        ]);

        $first = $this->get('/r/mat1')->headers->get('Location');
        $second = $this->get('/r/mat1')->headers->get('Location');

        $this->assertNotSame($first, $second);
    }

    public function test_r_redirects_to_fallback_when_param_unknown(): void
    {
        $response = $this->get('/r/unknown');

        $response->assertStatus(302);
        $this->assertSame('https://fallback.example.com', $response->headers->get('Location'));
    }

    public function test_r_redirects_to_fallback_when_no_active_shops(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();

        $response = $this->get('/r/mat1');

        $response->assertStatus(302);
        $this->assertSame('https://fallback.example.com', $response->headers->get('Location'));
    }

    public function test_r_redirects_to_fallback_when_all_capped(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $shop = CartpandaShop::factory()->create([
            'shop_slug' => 'capped',
            'active_for_routing' => true,
            'routing_priority' => 1,
            'daily_cap' => 50,
            'default_checkout_template' => 'https://capped.mycartpanda.com/checkout/x',
        ]);
        CartpandaOrder::factory()->create([
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'status' => 'COMPLETED',
            'amount' => 60.0,
            'created_at' => now(),
        ]);

        $response = $this->get('/r/mat1');

        $response->assertStatus(302);
        $this->assertSame('https://fallback.example.com', $response->headers->get('Location'));
    }

    public function test_r_uses_custom_ck_url_when_set(): void
    {
        User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'custom',
            'active_for_routing' => true,
            'ck_url' => 'https://custom.example.com/redirect',
            'default_checkout_template' => 'https://custom.mycartpanda.com/checkout',
        ]);

        $response = $this->get('/r/mat1');

        $response->assertStatus(302);
        $this->assertStringStartsWith(
            'https://custom.example.com/redirect?c=',
            $response->headers->get('Location')
        );
    }
}
