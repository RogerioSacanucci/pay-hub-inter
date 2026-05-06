<?php

namespace Tests\Unit\Services;

use App\Models\AffiliateCode;
use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\ShopPool;
use App\Models\ShopPoolTarget;
use App\Models\User;
use App\Services\AffiliateRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateRouterTest extends TestCase
{
    use RefreshDatabase;

    private AffiliateRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new AffiliateRouter;
        config(['routing.default_fallback' => 'https://fallback.example.com']);
    }

    public function test_resolves_uncapped_target(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shop = CartpandaShop::factory()->create(['shop_slug' => 'nutra']);
        ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shop->id,
            'checkout_template' => 'https://nutra.test/checkout/1?id=999',
            'priority' => 1,
        ]);
        $code = AffiliateCode::factory()->create([
            'code' => 'abc123', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('abc123');

        $this->assertSame('https://nutra.test/checkout/1?id=999&affiliate=mat1', $result['url']);
        $this->assertSame('nutra', $result['shop_slug']);
        $this->assertSame('abc123', $result['code']);
        $this->assertEquals(1, $code->fresh()->clicks);
    }

    public function test_appends_affiliate_with_question_mark_when_template_has_no_query(): void
    {
        $user = User::factory()->withCartpandaParam('tag99')->create();
        $pool = ShopPool::factory()->for($user)->create();
        ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'checkout_template' => 'https://shop.test/checkout',
        ]);
        AffiliateCode::factory()->create([
            'code' => 'noquery', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('noquery');

        $this->assertSame('https://shop.test/checkout?affiliate=tag99', $result['url']);
    }

    public function test_falls_back_when_code_not_found(): void
    {
        $result = $this->router->resolve('inexistente');

        $this->assertSame('code_not_found', $result['error']);
        $this->assertSame('https://fallback.example.com', $result['fallback_url']);
    }

    public function test_falls_back_when_code_inactive(): void
    {
        $user = User::factory()->withCartpandaParam('x')->create();
        $pool = ShopPool::factory()->for($user)->create();
        AffiliateCode::factory()->inactive()->create([
            'code' => 'paused', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('paused');

        $this->assertSame('code_not_found', $result['error']);
    }

    public function test_falls_back_when_pool_has_no_active_targets(): void
    {
        $user = User::factory()->withCartpandaParam('x')->create();
        $pool = ShopPool::factory()->for($user)->create();
        ShopPoolTarget::factory()->inactive()->create(['shop_pool_id' => $pool->id]);
        AffiliateCode::factory()->create([
            'code' => 'empty', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('empty');

        $this->assertSame('no_active_targets', $result['error']);
    }

    public function test_waterfall_skips_capped_target_to_next(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shopA = CartpandaShop::factory()->create(['shop_slug' => 'A']);
        $shopB = CartpandaShop::factory()->create(['shop_slug' => 'B']);

        // shop A: cap 1000, but already 1500 in COMPLETED today
        ShopPoolTarget::factory()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopA->id,
            'priority' => 1,
            'daily_cap' => 1000,
            'checkout_template' => 'https://a.test/co',
        ]);
        ShopPoolTarget::factory()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopB->id,
            'priority' => 2,
            'daily_cap' => 5000,
            'checkout_template' => 'https://b.test/co',
        ]);

        CartpandaOrder::factory()->create([
            'shop_id' => $shopA->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 1500,
            'created_at' => now()->startOfDay()->addHour(),
        ]);

        AffiliateCode::factory()->create([
            'code' => 'wf', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('wf');

        $this->assertSame('B', $result['shop_slug']);
    }

    public function test_completed_outside_period_is_ignored(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create(['cap_period' => 'day']);
        $shopA = CartpandaShop::factory()->create(['shop_slug' => 'A']);

        ShopPoolTarget::factory()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopA->id,
            'priority' => 1,
            'daily_cap' => 1000,
            'checkout_template' => 'https://a.test/co',
        ]);

        // yesterday's order should not count toward today's cap
        CartpandaOrder::factory()->create([
            'shop_id' => $shopA->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 999999,
            'created_at' => now()->subDay(),
        ]);

        AffiliateCode::factory()->create([
            'code' => 'yest', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('yest');

        $this->assertSame('A', $result['shop_slug']);
    }

    public function test_pending_orders_are_ignored_in_consumption(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shopA = CartpandaShop::factory()->create(['shop_slug' => 'A']);

        ShopPoolTarget::factory()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopA->id,
            'priority' => 1,
            'daily_cap' => 1000,
            'checkout_template' => 'https://a.test/co',
        ]);

        CartpandaOrder::factory()->create([
            'shop_id' => $shopA->id,
            'user_id' => $user->id,
            'status' => 'PENDING',
            'amount' => 5000,
            'created_at' => now()->startOfDay()->addHour(),
        ]);

        AffiliateCode::factory()->create([
            'code' => 'pending', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('pending');

        // PENDING shouldn't count → still under cap → shop A serves
        $this->assertSame('A', $result['shop_slug']);
    }

    public function test_overflow_target_used_when_all_others_capped(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shopA = CartpandaShop::factory()->create(['shop_slug' => 'A']);
        $shopOverflow = CartpandaShop::factory()->create(['shop_slug' => 'OVF']);

        ShopPoolTarget::factory()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopA->id,
            'priority' => 1,
            'daily_cap' => 100,
            'is_overflow' => false,
        ]);
        ShopPoolTarget::factory()->overflow()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopOverflow->id,
            'priority' => 99,
            'checkout_template' => 'https://overflow.test/co',
        ]);

        CartpandaOrder::factory()->create([
            'shop_id' => $shopA->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 200,
            'created_at' => now()->startOfDay()->addHour(),
        ]);

        AffiliateCode::factory()->create([
            'code' => 'ovf', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('ovf');

        $this->assertSame('OVF', $result['shop_slug']);
    }

    public function test_returns_error_when_all_capped_and_no_overflow(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shopA = CartpandaShop::factory()->create();

        ShopPoolTarget::factory()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shopA->id,
            'priority' => 1,
            'daily_cap' => 100,
        ]);

        CartpandaOrder::factory()->create([
            'shop_id' => $shopA->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 200,
            'created_at' => now()->startOfDay()->addHour(),
        ]);

        AffiliateCode::factory()->create([
            'code' => 'noovf', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('noovf');

        $this->assertSame('all_capped', $result['error']);
        $this->assertSame('https://fallback.example.com', $result['fallback_url']);
    }

    public function test_uses_shop_default_template_when_target_has_none(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shop = CartpandaShop::factory()->create([
            'shop_slug' => 'inherited',
            'default_checkout_template' => 'https://shop.test/checkout/9?id=42',
        ]);
        ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shop->id,
            'checkout_template' => null,
        ]);
        AffiliateCode::factory()->create([
            'code' => 'inherit-test', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('inherit-test');

        $this->assertSame('https://shop.test/checkout/9?id=42&affiliate=mat1', $result['url']);
    }

    public function test_target_template_overrides_shop_default(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shop = CartpandaShop::factory()->create([
            'default_checkout_template' => 'https://default.test/co',
        ]);
        ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shop->id,
            'checkout_template' => 'https://override.test/co',
        ]);
        AffiliateCode::factory()->create([
            'code' => 'override-test', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('override-test');

        $this->assertStringStartsWith('https://override.test/co', $result['url']);
    }

    public function test_returns_error_when_no_template_anywhere(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $shop = CartpandaShop::factory()->create(['default_checkout_template' => null]);
        ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'shop_id' => $shop->id,
            'checkout_template' => null,
        ]);
        AffiliateCode::factory()->create([
            'code' => 'no-tpl', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
        ]);

        $result = $this->router->resolve('no-tpl');

        $this->assertSame('no_checkout_template', $result['error']);
    }

    public function test_increments_clicks_on_code_and_target(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $pool = ShopPool::factory()->for($user)->create();
        $target = ShopPoolTarget::factory()->uncapped()->create([
            'shop_pool_id' => $pool->id,
            'clicks' => 5,
        ]);
        $code = AffiliateCode::factory()->create([
            'code' => 'inc', 'user_id' => $user->id, 'shop_pool_id' => $pool->id,
            'clicks' => 10,
        ]);

        $this->router->resolve('inc');

        $this->assertEquals(11, $code->fresh()->clicks);
        $this->assertEquals(6, $target->fresh()->clicks);
    }
}
