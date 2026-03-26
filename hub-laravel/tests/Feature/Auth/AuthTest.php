<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'password_hash' => bcrypt('password123'),
            'role' => 'user',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'user@test.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'password_hash' => bcrypt('password123'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'user@test.com',
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_me_fails_without_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_register_requires_admin_key(): void
    {
        $this->postJson('/api/auth/register', [
            'admin_key' => 'wrong_key',
            'email' => 'new@test.com',
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_register_creates_user_with_correct_key(): void
    {
        $this->postJson('/api/auth/register', [
            'admin_key' => config('app.admin_register_key'),
            'email' => 'new@test.com',
            'password' => 'password123',
            'payer_email' => 'payer@test.com',
        ])
            ->assertStatus(201)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_update_settings_saves_pushcut_config(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/update', [
                'pushcut_url' => 'https://api.pushcut.io/webhook/abc',
                'pushcut_notify' => 'paid',
            ])
            ->assertOk()
            ->assertJsonPath('user.pushcut_notify', 'paid');
    }
}
