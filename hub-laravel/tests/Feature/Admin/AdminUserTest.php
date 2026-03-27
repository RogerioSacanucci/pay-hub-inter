<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/users');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'email', 'payer_email', 'payer_name', 'role', 'active', 'created_at']],
                'meta' => ['total', 'page', 'per_page', 'pages'],
            ]);
        $this->assertCount(4, $response->json('data'));
    }

    public function test_admin_can_create_user(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/users', [
            'email' => 'newuser@example.com',
            'password' => 'secret1234',
            'payer_name' => 'New User',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'newuser@example.com')
            ->assertJsonPath('user.payer_name', 'New User')
            ->assertJsonPath('user.role', 'user');

        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);
    }

    public function test_create_user_validates_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/users', [
            'email' => $admin->email,
            'password' => 'secret1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_admin_can_update_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/users/{$user->id}", [
            'payer_name' => 'Updated Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.payer_name', 'Updated Name');
    }

    public function test_admin_can_reactivate_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['active' => false]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/users/{$user->id}", [
            'active' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.active', true);
        $this->assertTrue($user->fresh()->active);
    }

    public function test_admin_can_deactivate_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/admin/users/{$user->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'User deactivated');
        $this->assertFalse($user->fresh()->active);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/admin/users/{$admin->id}");

        $response->assertStatus(403)
            ->assertJsonPath('error', 'Cannot deactivate yourself');
        $this->assertTrue($admin->fresh()->active);
    }

    public function test_non_admin_cannot_access_admin_users(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/users')->assertStatus(403);
        $this->withToken($token)->postJson('/api/admin/users', [
            'email' => 'test@example.com',
            'password' => 'secret1234',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_admin_users(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->postJson('/api/admin/users', [
            'email' => 'test@example.com',
            'password' => 'secret1234',
        ])->assertUnauthorized();
    }
}
