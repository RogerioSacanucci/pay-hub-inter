<?php

namespace Database\Factories;

use App\Models\MundpayOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MundpayOrder>
 */
class MundpayOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 10, 500); // já em USD

        return [
            'mundpay_order_id' => fake()->unique()->uuid(),
            'mundpay_ref' => 'ref_'.fake()->bothify('############'),
            'user_id' => User::factory(),
            'amount' => $amount,
            'reserve_amount' => round($amount * 0.15, 6),
            'chargeback_penalty' => 0,
            'currency' => 'USD',
            'status' => 'COMPLETED',
            'event' => 'order.paid',
            'payment_method' => 'credit_card',
            'payer_email' => fake()->safeEmail(),
            'payer_name' => fake()->name(),
            'payer_phone' => fake()->phoneNumber(),
            'payer_document' => null,
            'paid_at' => now(),
            'chargeback_at' => null,
            'payload' => [],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'PENDING',
            'event' => 'order.created',
            'paid_at' => null,
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'REFUNDED',
            'event' => 'order.refunded',
            'chargeback_at' => now(),
        ]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (MundpayOrder $order) {
            if ($order->status !== 'COMPLETED' || $order->release_eligible_at !== null) {
                return;
            }

            $order->forceFill([
                'release_eligible_at' => ($order->paid_at ?? $order->created_at)->copy()->addDays(3),
            ])->saveQuietly();
        });
    }
}
