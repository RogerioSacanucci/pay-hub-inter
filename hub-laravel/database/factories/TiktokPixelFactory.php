<?php

namespace Database\Factories;

use App\Models\TiktokPixel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TiktokPixel>
 */
class TiktokPixelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pixel_code' => strtoupper(fake()->bothify('?????????????')),
            'access_token' => 'tt_'.fake()->sha256(),
            'label' => fake()->optional()->words(2, true),
            'test_event_code' => null,
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}
