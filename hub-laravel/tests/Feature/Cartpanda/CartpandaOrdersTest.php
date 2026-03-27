<?php

namespace Tests\Feature\Cartpanda;

use App\Models\CartpandaOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartpandaOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_cartpanda_orders(): void
    {
        $this->getJson('/api/cartpanda-orders')->assertUnauthorized();
    }

    public function test_user_sees_only_own_orders(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        CartpandaOrder::factory()->create(['user_id' => $user->id]);
        CartpandaOrder::factory()->create(['user_id' => $other->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-orders');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_sees_all_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        CartpandaOrder::factory()->create(['user_id' => $user1->id]);
        CartpandaOrder::factory()->create(['user_id' => $user2->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-orders');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_admin_can_filter_by_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        CartpandaOrder::factory()->create(['user_id' => $user1->id]);
        CartpandaOrder::factory()->create(['user_id' => $user2->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-orders?user_id='.$user1->id);
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_filter_by_status(): void
    {
        $user = User::factory()->create();
        CartpandaOrder::factory()->create(['user_id' => $user->id, 'status' => 'COMPLETED']);
        CartpandaOrder::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-orders?status=COMPLETED');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_pagination(): void
    {
        $user = User::factory()->create();
        CartpandaOrder::factory()->count(25)->create(['user_id' => $user->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-orders?page=1');
        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);
        $this->assertCount(20, $response->json('data'));
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.pages'));
    }

    public function test_response_shape_has_expected_keys(): void
    {
        $user = User::factory()->create();
        CartpandaOrder::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-orders');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'cartpanda_order_id',
                        'amount',
                        'currency',
                        'status',
                        'event',
                        'payer_name',
                        'payer_email',
                        'created_at',
                    ],
                ],
                'meta' => ['total', 'page', 'per_page', 'pages'],
            ]);

        $order = $response->json('data.0');
        $this->assertArrayNotHasKey('payload', $order);
        $this->assertArrayNotHasKey('id', $order);
        $this->assertArrayNotHasKey('user_id', $order);
    }
}
