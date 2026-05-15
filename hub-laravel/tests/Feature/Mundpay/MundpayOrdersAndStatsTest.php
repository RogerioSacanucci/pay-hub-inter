<?php

namespace Tests\Feature\Mundpay;

use App\Models\MundpayOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MundpayOrdersAndStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_endpoint_returns_user_orders_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        MundpayOrder::factory()->for($user)->create(['mundpay_order_id' => 'mine']);
        MundpayOrder::factory()->for($other)->create(['mundpay_order_id' => 'theirs']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mundpay-orders');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.mundpay_order_id', 'mine');
    }

    public function test_orders_admin_sees_all_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        MundpayOrder::factory()->for($u1)->create();
        MundpayOrder::factory()->for($u2)->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/mundpay-orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_orders_admin_can_filter_by_user(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();
        $other = User::factory()->create();
        MundpayOrder::factory()->for($target)->create();
        MundpayOrder::factory()->for($other)->create();
        MundpayOrder::factory()->for($other)->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/mundpay-orders?user_id='.$target->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_orders_filter_by_status(): void
    {
        $user = User::factory()->create();
        MundpayOrder::factory()->for($user)->create(['status' => 'COMPLETED']);
        MundpayOrder::factory()->for($user)->refunded()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/mundpay-orders?status=refunded')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'REFUNDED');
    }

    public function test_stats_overview_aggregates(): void
    {
        $user = User::factory()->create();
        MundpayOrder::factory()->for($user)->create(['amount' => 100, 'reserve_amount' => 15, 'status' => 'COMPLETED']);
        MundpayOrder::factory()->for($user)->create(['amount' => 50, 'reserve_amount' => 7.5, 'status' => 'COMPLETED']);
        MundpayOrder::factory()->for($user)->refunded()->create(['amount' => 25]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mundpay-stats');
        $response->assertOk();
        $response->assertJsonPath('overview.total_orders', 3);
        $response->assertJsonPath('overview.completed', 2);
        $response->assertJsonPath('overview.refunded', 1);
        $this->assertEquals(150.0, $response->json('overview.total_volume'));
        $this->assertEquals(25.0, $response->json('overview.refunded_volume'));
    }

    public function test_stats_unauthenticated_rejected(): void
    {
        $this->getJson('/api/mundpay-stats')->assertUnauthorized();
    }
}
