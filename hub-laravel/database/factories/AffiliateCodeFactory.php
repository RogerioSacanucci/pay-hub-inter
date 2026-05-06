<?php

namespace Database\Factories;

use App\Models\AffiliateCode;
use App\Models\ShopPool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateCode>
 */
class AffiliateCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('????######'),
            'user_id' => User::factory(),
            'shop_pool_id' => ShopPool::factory(),
            'label' => fake()->optional()->words(2, true),
            'active' => true,
            'clicks' => 0,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }
}
