<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/users');
        $response->assertOk()
            ->assertJsonStructure(['users' => [['id', 'email', 'payer_email', 'payer_name', 'role', 'created_at']]]);
        $this->assertCount(4, $response->json('users'));
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/users')->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_list_users(): void
    {
        $this->getJson('/api/auth/users')->assertUnauthorized();
    }
}
