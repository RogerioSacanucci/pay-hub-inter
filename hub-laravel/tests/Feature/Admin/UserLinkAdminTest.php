<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserAapanelConfig;
use App\Models\UserLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLinkAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_user_links(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        UserLink::factory()->count(3)->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/user-links');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'user_email', 'aapanel_config_id', 'aapanel_config_label', 'label', 'external_url', 'file_path', 'created_at']],
            ]);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_admin_can_create_link(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/user-links', [
            'user_id' => $user->id,
            'aapanel_config_id' => $config->id,
            'label' => 'Landing Page',
            'external_url' => 'https://meusite.com',
            'file_path' => '/www/wwwroot/meusite.com/index.html',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.user_email', $user->email)
            ->assertJsonPath('data.aapanel_config_id', $config->id)
            ->assertJsonPath('data.aapanel_config_label', $config->label)
            ->assertJsonPath('data.label', 'Landing Page')
            ->assertJsonPath('data.external_url', 'https://meusite.com')
            ->assertJsonPath('data.file_path', '/www/wwwroot/meusite.com/index.html');

        $this->assertDatabaseHas('user_links', [
            'user_id' => $user->id,
            'aapanel_config_id' => $config->id,
            'label' => 'Landing Page',
        ]);
    }

    public function test_store_rejects_config_belonging_to_different_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $otherUser->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/user-links', [
            'user_id' => $user->id,
            'aapanel_config_id' => $config->id,
            'label' => 'Landing Page',
            'external_url' => 'https://meusite.com',
            'file_path' => '/www/wwwroot/meusite.com/index.html',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.aapanel_config_id.0', 'aaPanel config does not belong to specified user');
    }

    public function test_store_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/user-links', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'aapanel_config_id', 'label', 'external_url', 'file_path']);
    }

    public function test_admin_can_update_label(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/user-links/{$link->id}", [
            'label' => 'Updated Label',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.label', 'Updated Label');

        $this->assertDatabaseHas('user_links', ['id' => $link->id, 'label' => 'Updated Label']);
    }

    public function test_admin_can_delete_link(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->deleteJson("/api/admin/user-links/{$link->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Link deleted');

        $this->assertDatabaseMissing('user_links', ['id' => $link->id]);
    }

    public function test_non_admin_gets_403(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/user-links')->assertStatus(403);
        $this->withToken($token)->postJson('/api/admin/user-links', [
            'user_id' => $user->id,
            'aapanel_config_id' => 1,
            'label' => 'Test',
            'external_url' => 'https://example.com',
            'file_path' => '/www/test.html',
        ])->assertStatus(403);
    }
}
