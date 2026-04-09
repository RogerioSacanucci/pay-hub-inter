<?php

namespace Database\Factories;

use App\Models\RevenueMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RevenueMilestone>
 */
class RevenueMilestoneFactory extends Factory
{
    private static int $order = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'value' => fake()->randomFloat(2, 1000, 100000),
            'order' => ++self::$order,
        ];
    }
}
