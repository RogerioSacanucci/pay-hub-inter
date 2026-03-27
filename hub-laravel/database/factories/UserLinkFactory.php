<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAapanelConfig;
use App\Models\UserLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserLink>
 */
class UserLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'aapanel_config_id' => UserAapanelConfig::factory(),
            'label' => fake()->words(2, true),
            'external_url' => fake()->url(),
            'file_path' => '/www/wwwroot/'.fake()->domainName().'/index.html',
        ];
    }
}
