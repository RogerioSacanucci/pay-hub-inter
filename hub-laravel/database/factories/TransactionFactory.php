<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => Str::uuid()->toString(),
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 1, 500),
            'currency' => 'EUR',
            'method' => fake()->randomElement(['mbway', 'multibanco']),
            'status' => 'PENDING',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'COMPLETED',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'FAILED',
        ]);
    }
}
