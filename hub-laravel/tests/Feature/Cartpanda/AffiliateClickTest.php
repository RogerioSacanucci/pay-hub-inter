<?php

namespace Tests\Feature\Cartpanda;

use App\Models\CartpandaShop;
use App\Models\User;
use App\Services\AffiliateRouter;
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

    public function test_show_requires_shop_query(): void
    {
        $token = app(AffiliateRouter::class)->mintToken(1);

        $this->getJson("/api/click/{$token}")
            ->assertStatus(400)
            ->assertJson(['error' => 'shop_required']);
    }

    public function test_show_returns_url_for_valid_token_and_active_shop(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $shop = CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout/abc?id=1',
        ]);

        $token = app(AffiliateRouter::class)->mintToken($user->id);

        $this->getJson("/api/click/{$token}?shop=nutra")
            ->assertOk()
            ->assertJson([
                'shop_slug' => 'nutra',
                'url' => 'https://nutra.mycartpanda.com/checkout/abc?id=1&affiliate=mat1',
            ]);
    }

    public function test_show_returns_404_for_invalid_token(): void
    {
        $shop = CartpandaShop::factory()->create([
            'shop_slug' => 'nutra',
            'active_for_routing' => true,
            'default_checkout_template' => 'https://nutra.mycartpanda.com/checkout/abc',
        ]);

        $this->getJson('/api/click/eyJpdiI6Im5vcGUifQ?shop=nutra')
            ->assertStatus(404)
            ->assertJson(['error' => 'invalid_or_expired_token']);
    }

    public function test_show_returns_503_for_inactive_shop(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        CartpandaShop::factory()->create([
            'shop_slug' => 'inactive',
            'active_for_routing' => false,
            'default_checkout_template' => 'https://inactive.mycartpanda.com/checkout',
        ]);

        $token = app(AffiliateRouter::class)->mintToken($user->id);

        $this->getJson("/api/click/{$token}?shop=inactive")
            ->assertStatus(503)
            ->assertJson(['error' => 'shop_not_active']);
    }

    public function test_show_returns_503_for_unknown_shop(): void
    {
        $user = User::factory()->withCartpandaParam('mat1')->create();
        $token = app(AffiliateRouter::class)->mintToken($user->id);

        $this->getJson("/api/click/{$token}?shop=nonexistent")
            ->assertStatus(503)
            ->assertJson(['error' => 'shop_not_active']);
    }
}
