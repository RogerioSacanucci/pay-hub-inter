<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAapanelConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAapanelConfig>
 */
class UserAapanelConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->words(2, true),
            'panel_url' => 'https://'.fake()->domainName().':7800',
            'api_key' => fake()->uuid(),
        ];
    }
}
