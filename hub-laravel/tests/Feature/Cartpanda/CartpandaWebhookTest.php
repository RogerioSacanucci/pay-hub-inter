<?php

namespace Tests\Feature\Cartpanda;

use App\Models\CartpandaOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartpandaWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_cartpanda_order_factory_creates_record(): void
    {
        $order = CartpandaOrder::factory()->create();
        $this->assertDatabaseHas('cartpanda_orders', ['id' => $order->id]);
    }

    public function test_cartpanda_order_is_terminal_for_completed(): void
    {
        $order = CartpandaOrder::factory()->create(['status' => 'COMPLETED']);
        $this->assertTrue($order->isTerminal());
    }

    public function test_cartpanda_order_is_terminal_for_refunded(): void
    {
        $order = CartpandaOrder::factory()->refunded()->create();
        $this->assertTrue($order->isTerminal());
    }

    public function test_cartpanda_order_is_not_terminal_for_pending(): void
    {
        $order = CartpandaOrder::factory()->pending()->create();
        $this->assertFalse($order->isTerminal());
    }
}
