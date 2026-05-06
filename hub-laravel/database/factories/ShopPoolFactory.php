<?php

namespace Database\Factories;

use App\Models\ShopPool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopPool>
 */
class ShopPoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->slug(2),
            'description' => null,
            'cap_period' => 'day',
        ];
    }

    public function hourly(): self
    {
        return $this->state(['cap_period' => 'hour']);
    }

    public function weekly(): self
    {
        return $this->state(['cap_period' => 'week']);
    }
}
