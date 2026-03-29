<?php

namespace Database\Factories;

use App\Models\CartpandaShop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartpandaShop>
 */
class CartpandaShopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cartpanda_shop_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'shop_slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
        ];
    }
}
