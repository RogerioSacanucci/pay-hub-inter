<?php

namespace Database\Factories;

use App\Models\PayoutLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutLog>
 */
class PayoutLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'admin_user_id' => User::factory(),
            'amount' => fake()->randomFloat(6, 1, 500),
            'type' => fake()->randomElement(['withdrawal', 'adjustment']),
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function withdrawal(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'withdrawal',
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'adjustment',
        ]);
    }
}
