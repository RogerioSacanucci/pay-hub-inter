<?php

namespace Tests\Feature\Links;

use App\Models\User;
use App\Models\UserAapanelConfig;
use App\Models\UserLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UserLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_their_own_links(): void
    {
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        UserLink::factory()->count(2)->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);

        $otherUser = User::factory()->create();
        $otherConfig = UserAapanelConfig::factory()->create(['user_id' => $otherUser->id]);
        UserLink::factory()->count(3)->create(['user_id' => $otherUser->id, 'aapanel_config_id' => $otherConfig->id]);

        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/links');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_response_never_exposes_panel_url_or_api_key(): void
    {
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/links');

        $response->assertOk();
        $linkData = $response->json('data.0');
        $this->assertArrayNotHasKey('panel_url', $linkData);
        $this->assertArrayNotHasKey('api_key', $linkData);
        $this->assertArrayNotHasKey('aapanel_config_id', $linkData);
        $this->assertArrayHasKey('id', $linkData);
        $this->assertArrayHasKey('label', $linkData);
        $this->assertArrayHasKey('external_url', $linkData);
        $this->assertArrayHasKey('file_path', $linkData);
    }

    public function test_user_can_get_file_content(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => '<!DOCTYPE html>'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/links/{$link->id}/content");

        $response->assertOk()
            ->assertJsonPath('content', '<!DOCTYPE html>');
    }

    public function test_user_cannot_get_content_of_another_users_link(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $otherUser->id]);
        $link = UserLink::factory()->create(['user_id' => $otherUser->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/links/{$link->id}/content");

        $response->assertStatus(403);
    }

    public function test_aapanel_read_failure_returns_502(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'msg' => 'File not found'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/links/{$link->id}/content");

        $response->assertStatus(502);
    }

    public function test_user_can_save_file_content(): void
    {
        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/links/{$link->id}/content", [
            'content' => '<html>updated</html>',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'File saved successfully');

        Http::assertSent(function ($request) {
            return $request['action'] === 'SaveFileBody'
                && $request['data'] === '<html>updated</html>';
        });
    }

    public function test_save_content_validates_content_required(): void
    {
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/links/{$link->id}/content", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    public function test_user_cannot_save_to_another_users_link(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $otherUser->id]);
        $link = UserLink::factory()->create(['user_id' => $otherUser->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/links/{$link->id}/content", [
            'content' => '<html>hacked</html>',
        ]);

        $response->assertStatus(403);
    }

    public function test_aapanel_write_failure_returns_502(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'msg' => 'Write failed'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/links/{$link->id}/content", [
            'content' => '<html>test</html>',
        ]);

        $response->assertStatus(502);
    }

    public function test_admin_can_get_and_save_content_of_any_users_link(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => '<!DOCTYPE html>'], 200)]);

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->create(['user_id' => $user->id]);
        $link = UserLink::factory()->create(['user_id' => $user->id, 'aapanel_config_id' => $config->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson("/api/links/{$link->id}/content")
            ->assertOk()
            ->assertJsonPath('content', '<!DOCTYPE html>');

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->withToken($token)->putJson("/api/links/{$link->id}/content", [
            'content' => '<html>admin edit</html>',
        ])->assertOk()
            ->assertJsonPath('message', 'File saved successfully');
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/links')->assertStatus(401);
        $this->getJson('/api/links/1/content')->assertStatus(401);
        $this->putJson('/api/links/1/content', ['content' => 'test'])->assertStatus(401);
    }
}
