<?php

namespace Tests\Feature\PushcutUrl;

use App\Models\User;
use App\Models\UserPushcutUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPushcutUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_admin_only_urls_for_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        UserPushcutUrl::factory()->for($user)->adminOnly()->create(['url' => 'https://api.pushcut.io/admin/notifications/iPhone']);
        UserPushcutUrl::factory()->for($user)->create(); // user-visible — must not appear

        $this->actingAs($admin)
            ->getJson("/api/admin/users/{$user->id}/pushcut-urls")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', 'https://api.pushcut.io/admin/notifications/iPhone')
            ->assertJsonPath('data.0.admin_only', true);
    }

    public function test_admin_can_create_admin_only_url_for_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->id}/pushcut-urls", [
                'url' => 'https://api.pushcut.io/secret/notifications/iPhone',
                'notify' => 'paid',
                'label' => 'Admin iPhone',
            ])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://api.pushcut.io/secret/notifications/iPhone')
            ->assertJsonPath('data.notify', 'paid')
            ->assertJsonPath('data.admin_only', true);

        $this->assertDatabaseHas('user_pushcut_urls', [
            'user_id' => $user->id,
            'url' => 'https://api.pushcut.io/secret/notifications/iPhone',
            'admin_only' => true,
        ]);
    }

    public function test_admin_only_url_is_hidden_from_user_index(): void
    {
        $user = User::factory()->create();

        UserPushcutUrl::factory()->for($user)->adminOnly()->create();
        UserPushcutUrl::factory()->for($user)->create(['label' => 'visible']);

        $this->actingAs($user)
            ->getJson('/api/pushcut-urls')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'visible');
    }

    public function test_admin_can_delete_admin_only_url(): void
    {
        $admin = User::factory()->admin()->create();
        $url = UserPushcutUrl::factory()->adminOnly()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/pushcut-urls/{$url->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('user_pushcut_urls', ['id' => $url->id]);
    }

    public function test_admin_cannot_delete_user_visible_url_via_admin_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $url = UserPushcutUrl::factory()->create(); // admin_only = false

        $this->actingAs($admin)
            ->deleteJson("/api/admin/pushcut-urls/{$url->id}")
            ->assertForbidden();
    }

    public function test_non_admin_cannot_access_admin_pushcut_endpoints(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/admin/users/{$target->id}/pushcut-urls", [
                'url' => 'https://api.pushcut.io/token/notifications/iPhone',
                'notify' => 'all',
            ])
            ->assertForbidden();
    }
}
