<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPushcutUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPushcutUrl>
 */
class UserPushcutUrlFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url' => fake()->url(),
            'notify' => fake()->randomElement(['all', 'created', 'paid']),
            'label' => fake()->optional()->word(),
        ];
    }

    public function notifyAll(): static
    {
        return $this->state(fn (array $attributes) => ['notify' => 'all']);
    }

    public function notifyPaid(): static
    {
        return $this->state(fn (array $attributes) => ['notify' => 'paid']);
    }

    public function notifyCreated(): static
    {
        return $this->state(fn (array $attributes) => ['notify' => 'created']);
    }

    public function adminOnly(): static
    {
        return $this->state(fn (array $attributes) => ['admin_only' => true]);
    }
}
