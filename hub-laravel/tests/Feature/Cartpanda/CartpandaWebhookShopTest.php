<?php

namespace Tests\Feature\Cartpanda;

use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartpandaWebhookShopTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_factory_creates_record(): void
    {
        $shop = CartpandaShop::factory()->create();
        $this->assertDatabaseHas('cartpanda_shops', ['id' => $shop->id]);
    }

    public function test_shop_can_have_users(): void
    {
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        $this->assertCount(1, $shop->users);
        $this->assertDatabaseHas('cartpanda_shop_user', [
            'shop_id' => $shop->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_webhook_auto_creates_shop_and_links_to_user(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();

        $this->postJson('/api/cartpanda-webhook', $this->makePayload(
            event: 'order.paid',
            affiliateKey: 'afiliado1',
            orderId: 99001,
            amount: 43.24,
            shopId: 777533,
            shopSlug: 'lifeproductsx',
            shopName: 'LifeProductsx',
        ))->assertOk();

        $this->assertDatabaseHas('cartpanda_shops', [
            'cartpanda_shop_id' => '777533',
            'shop_slug' => 'lifeproductsx',
            'name' => 'LifeProductsx',
        ]);

        $shop = CartpandaShop::where('cartpanda_shop_id', '777533')->first();
        $this->assertNotNull($shop);
        $this->assertTrue($user->shops->contains($shop->id));

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '99001',
            'shop_id' => $shop->id,
        ]);
    }

    public function test_webhook_updates_existing_shop_slug_and_name(): void
    {
        $shop = CartpandaShop::factory()->create([
            'cartpanda_shop_id' => '777533',
            'shop_slug' => 'old-slug',
            'name' => 'Old Name',
        ]);
        $user = User::factory()->withCartpandaParam('afiliado1')->create();

        $this->postJson('/api/cartpanda-webhook', $this->makePayload(
            event: 'order.paid',
            affiliateKey: 'afiliado1',
            orderId: 99002,
            amount: 20.00,
            shopId: 777533,
            shopSlug: 'lifeproductsx',
            shopName: 'LifeProductsx',
        ))->assertOk();

        $shop->refresh();
        $this->assertEquals('lifeproductsx', $shop->shop_slug);
        $this->assertEquals('LifeProductsx', $shop->name);
    }

    public function test_webhook_without_shop_data_sets_shop_id_null(): void
    {
        $user = User::factory()->withCartpandaParam('afiliado1')->create();

        $payload = $this->makePayload('order.paid', 'afiliado1', 99003, 20.00, null, null, null);

        $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

        $this->assertDatabaseHas('cartpanda_orders', [
            'cartpanda_order_id' => '99003',
            'shop_id' => null,
        ]);
    }

    public function test_webhook_attaches_second_user_to_existing_shop(): void
    {
        $shop = CartpandaShop::factory()->create(['cartpanda_shop_id' => '777533']);
        $user1 = User::factory()->withCartpandaParam('afiliado1')->create();
        $user2 = User::factory()->withCartpandaParam('afiliado2')->create();
        $shop->users()->attach($user1->id);

        $this->postJson('/api/cartpanda-webhook', $this->makePayload(
            event: 'order.paid',
            affiliateKey: 'afiliado2',
            orderId: 99004,
            amount: 20.00,
            shopId: 777533,
            shopSlug: 'lifeproductsx',
            shopName: 'LifeProductsx',
        ))->assertOk();

        $shop->refresh();
        $this->assertCount(2, $shop->users);
        $this->assertTrue($shop->users->contains($user2->id));
        $this->assertTrue($shop->users->contains($user1->id));
    }

    private function makePayload(
        string $event,
        ?string $affiliateKey,
        int $orderId,
        float $amount,
        ?int $shopId = 777533,
        ?string $shopSlug = 'lifeproductsx',
        ?string $shopName = 'LifeProductsx',
    ): array {
        $payload = [
            'event' => $event,
            'order' => [
                'id' => $orderId,
                'checkout_params' => $affiliateKey ? [$affiliateKey => 'tracking_value'] : null,
                'customer' => [
                    'email' => 'buyer@example.com',
                    'full_name' => 'Test Buyer',
                ],
                'payment' => [
                    'actual_price_paid' => $amount,
                    'actual_price_paid_currency' => 'USD',
                ],
            ],
        ];

        if ($shopId !== null) {
            $payload['order']['shop'] = [
                'id' => $shopId,
                'slug' => $shopSlug,
                'name' => $shopName,
            ];
        }

        return $payload;
    }
}
