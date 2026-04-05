<?php

namespace Database\Factories;

use App\Models\EmailServiceInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailServiceInstance>
 */
class EmailServiceInstanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => fake()->url(),
            'api_key' => fake()->uuid(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
