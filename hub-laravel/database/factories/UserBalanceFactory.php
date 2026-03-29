<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserBalance>
 */
class UserBalanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance_pending' => fake()->randomFloat(6, 0, 1000),
            'balance_released' => fake()->randomFloat(6, 0, 1000),
            'currency' => 'USD',
        ];
    }
}
