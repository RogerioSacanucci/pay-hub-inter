<?php

namespace Database\Factories;

use App\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookLog>
 */
class WebhookLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event' => fake()->randomElement(['order.paid', 'order.created', 'order.cancelled', 'order.chargeback', 'order.refunded']),
            'cartpanda_order_id' => (string) fake()->numberBetween(100000, 999999),
            'shop_slug' => fake()->slug(2),
            'status' => fake()->randomElement(['processed', 'ignored', 'failed']),
            'status_reason' => null,
            'payload' => ['event' => 'order.paid', 'order' => ['id' => fake()->numberBetween(100000, 999999)]],
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => ['status' => 'processed', 'status_reason' => null]);
    }

    public function ignored(string $reason): static
    {
        return $this->state(fn () => ['status' => 'ignored', 'status_reason' => $reason]);
    }

    public function failed(string $reason): static
    {
        return $this->state(fn () => ['status' => 'failed', 'status_reason' => $reason]);
    }
}
