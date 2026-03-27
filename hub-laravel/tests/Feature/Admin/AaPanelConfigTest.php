<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserAapanelConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AaPanelConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_configs(): void
    {
        $admin = User::factory()->admin()->create();
        UserAapanelConfig::factory()->count(3)->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/aapanel-configs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'user_email', 'label', 'panel_url', 'api_key_masked', 'created_at']],
            ]);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_api_key_is_masked_in_response(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create(['api_key' => 'my-secret-api-key-abcd']);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/aapanel-configs');

        $response->assertOk();
        $data = $response->json('data.0');
        $this->assertSame('****abcd', $data['api_key_masked']);
        $this->assertArrayNotHasKey('api_key', $data);
    }

    public function test_api_key_is_encrypted_in_database(): void
    {
        $config = UserAapanelConfig::factory()->create(['api_key' => 'plaintext-secret']);

        $raw = \DB::table('user_aapanel_configs')->where('id', $config->id)->value('api_key');
        $this->assertNotEquals('plaintext-secret', $raw);
    }

    public function test_admin_can_create_config(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/aapanel-configs', [
            'user_id' => $user->id,
            'label' => 'Servidor Principal',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'secret-key-1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.label', 'Servidor Principal')
            ->assertJsonPath('data.panel_url', 'https://panel.example.com:7800')
            ->assertJsonPath('data.api_key_masked', '****1234')
            ->assertJsonPath('data.user_email', $user->email);

        $this->assertDatabaseHas('user_aapanel_configs', [
            'user_id' => $user->id,
            'label' => 'Servidor Principal',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/aapanel-configs', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'label', 'panel_url', 'api_key']);
    }

    public function test_store_validates_user_id_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/aapanel-configs', [
            'user_id' => 99999,
            'label' => 'Test',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'secret',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_admin_can_update_label_and_panel_url(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/aapanel-configs/{$config->id}", [
            'label' => 'Updated Label',
            'panel_url' => 'https://new-panel.example.com:7800',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.label', 'Updated Label')
            ->assertJsonPath('data.panel_url', 'https://new-panel.example.com:7800');
    }

    public function test_admin_can_update_api_key(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create(['api_key' => 'old-key-1234']);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/aapanel-configs/{$config->id}", [
            'api_key' => 'new-secret-key-5678',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.api_key_masked', '****5678');

        $this->assertSame('new-secret-key-5678', $config->fresh()->api_key);
    }

    public function test_admin_can_delete_config(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/admin/aapanel-configs/{$config->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Config deleted');

        $this->assertDatabaseMissing('user_aapanel_configs', ['id' => $config->id]);
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/aapanel-configs')->assertStatus(403);
        $this->withToken($token)->postJson('/api/admin/aapanel-configs', [
            'user_id' => $user->id,
            'label' => 'Test',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'secret',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/admin/aapanel-configs')->assertUnauthorized();
        $this->postJson('/api/admin/aapanel-configs', [
            'user_id' => 1,
            'label' => 'Test',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'secret',
        ])->assertUnauthorized();
    }
}
