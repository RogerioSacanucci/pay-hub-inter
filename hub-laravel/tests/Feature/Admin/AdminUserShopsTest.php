<?php

namespace Tests\Feature\Admin;

use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserShopsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_attach_shop_to_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/users/{$user->id}/shops", ['shop_id' => $shop->id])
            ->assertStatus(201)
            ->assertJson(['message' => 'Shop attached']);

        $this->assertDatabaseHas('cartpanda_shop_user', [
            'user_id' => $user->id,
            'shop_id' => $shop->id,
        ]);
    }

    public function test_attach_shop_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        $user->shops()->attach($shop->id);
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/users/{$user->id}/shops", ['shop_id' => $shop->id])
            ->assertStatus(201);

        $this->assertCount(1, $user->shops()->get());
    }

    public function test_admin_can_detach_shop_from_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        $user->shops()->attach($shop->id);
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/admin/users/{$user->id}/shops/{$shop->id}")
            ->assertOk()
            ->assertJson(['message' => 'Shop detached']);

        $this->assertDatabaseMissing('cartpanda_shop_user', [
            'user_id' => $user->id,
            'shop_id' => $shop->id,
        ]);
    }

    public function test_non_admin_cannot_attach_shop(): void
    {
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/users/{$user->id}/shops", ['shop_id' => $shop->id])
            ->assertForbidden();
    }

    public function test_attach_validates_shop_id_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/admin/users/{$user->id}/shops", ['shop_id' => 9999])
            ->assertUnprocessable();
    }

    public function test_admin_user_list_includes_shops(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        $user->shops()->attach($shop->id);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/users');
        $response->assertOk();

        $userData = collect($response->json('data'))->firstWhere('id', $user->id);
        $this->assertArrayHasKey('shops', $userData);
        $this->assertCount(1, $userData['shops']);
        $this->assertEquals($shop->id, $userData['shops'][0]['id']);
    }
}
