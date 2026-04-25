<?php

namespace Database\Factories;

use App\Models\TiktokEventLog;
use App\Models\TiktokPixel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TiktokEventLog>
 */
class TiktokEventLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tiktok_pixel_id' => TiktokPixel::factory(),
            'cartpanda_order_id' => (string) fake()->numberBetween(10000, 99999),
            'event' => 'CompletePayment',
            'http_status' => 200,
            'tiktok_code' => 0,
            'tiktok_message' => 'OK',
            'request_id' => fake()->uuid(),
            'payload' => [
                'event_id' => (string) fake()->numberBetween(1, 99999),
                'event' => 'CompletePayment',
                'value' => fake()->randomFloat(2, 10, 200),
                'currency' => 'USD',
                'content_count' => 1,
            ],
            'response' => [
                'code' => 0,
                'message' => 'OK',
                'request_id' => fake()->uuid(),
            ],
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attrs) => [
            'http_status' => 400,
            'tiktok_code' => 40000,
            'tiktok_message' => 'Invalid pixel_code',
            'response' => ['code' => 40000, 'message' => 'Invalid pixel_code'],
        ]);
    }
}
