<?php

namespace Tests\Feature;

use App\Models\CheckoutChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_cannot_list_change_requests(): void
    {
        $this->getJson('/api/checkout-change-requests')->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_submit_change_request(): void
    {
        $this->postJson('/api/checkout-change-requests', [
            'message' => 'Please change logo',
        ])->assertUnauthorized();
    }

    public function test_user_only_sees_own_change_requests(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        CheckoutChangeRequest::factory()->count(2)->create(['user_id' => $user->id]);
        CheckoutChangeRequest::factory()->count(3)->create(['user_id' => $other->id]);

        $response = $this->withToken($token)->getJson('/api/checkout-change-requests');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_returns_correct_fields(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        CheckoutChangeRequest::factory()->create(['user_id' => $user->id]);

        $response = $this->withToken($token)->getJson('/api/checkout-change-requests');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'message', 'status', 'created_at']],
            ]);
    }

    public function test_list_returns_meta_pagination(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        CheckoutChangeRequest::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->withToken($token)->getJson('/api/checkout-change-requests');

        $response->assertOk()
            ->assertJsonStructure([
                'meta' => ['total', 'page', 'per_page', 'pages'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.pages', 1);
    }

    public function test_user_can_submit_change_request(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/checkout-change-requests', [
            'message' => 'Please update the logo',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', 'Please update the logo')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('checkout_change_requests', [
            'user_id' => $user->id,
            'message' => 'Please update the logo',
            'status' => 'pending',
        ]);
    }

    public function test_message_is_required(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/checkout-change-requests', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_message_max_2000_chars(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/checkout-change-requests', [
            'message' => str_repeat('a', 2001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }
}
