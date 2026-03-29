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
}
