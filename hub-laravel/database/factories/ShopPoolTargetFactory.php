<?php

namespace Database\Factories;

use App\Models\CartpandaShop;
use App\Models\ShopPool;
use App\Models\ShopPoolTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopPoolTarget>
 */
class ShopPoolTargetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_pool_id' => ShopPool::factory(),
            'shop_id' => CartpandaShop::factory(),
            'checkout_template' => fake()->url(),
            'priority' => fake()->numberBetween(1, 100),
            'daily_cap' => fake()->randomFloat(2, 1000, 50000),
            'is_overflow' => false,
            'active' => true,
            'clicks' => 0,
        ];
    }

    public function uncapped(): self
    {
        return $this->state(['daily_cap' => null]);
    }

    public function overflow(): self
    {
        return $this->state(['is_overflow' => true, 'daily_cap' => null]);
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }
}
