<?php

namespace Database\Factories;

use App\Models\TiktokOauthConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TiktokOauthConnection>
 */
class TiktokOauthConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bc_id' => '7629530797501841409',
            'bc_name' => 'Risqui Oficial',
            'access_token' => 'oauth_'.fake()->sha256(),
            'refresh_token' => 'refresh_'.fake()->sha256(),
            'expires_at' => now()->addYear(),
            'scope' => ['user.info.basic', 'advertiser', 'pixel_management'],
            'advertiser_ids' => ['7629530770574426128'],
            'status' => 'active',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attrs) => [
            'expires_at' => now()->subDay(),
            'status' => 'expired',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'revoked',
        ]);
    }
}
