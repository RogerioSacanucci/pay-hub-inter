<?php

namespace Database\Factories;

use App\Models\CartpandaOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartpandaOrder>
 */
class CartpandaOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cartpanda_order_id' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(6, 1, 500),
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'event' => 'order.paid',
            'payer_email' => fake()->safeEmail(),
            'payer_name' => fake()->name(),
            'payload' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'PENDING',
            'event' => 'order.created',
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'REFUNDED',
            'event' => 'order.refunded',
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'DECLINED',
            'event' => 'order.chargeback',
            'chargeback_penalty' => 30,
        ]);
    }
}
