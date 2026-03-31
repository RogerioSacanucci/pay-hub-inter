<?php

namespace Tests\Feature\PushcutUrl;

use App\Models\User;
use App\Models\UserPushcutUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushcutUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_authenticated_user_urls(): void
    {
        $user = User::factory()->create();
        UserPushcutUrl::factory()->for($user)->create([
            'url'    => 'https://api.pushcut.io/token/notifications/iPhone',
            'notify' => 'all',
            'label'  => 'iPhone',
        ]);
        UserPushcutUrl::factory()->create(); // another user's URL — must not appear

        $this->actingAs($user)
            ->getJson('/api/pushcut-urls')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.url', 'https://api.pushcut.io/token/notifications/iPhone')
            ->assertJsonPath('data.0.notify', 'all')
            ->assertJsonPath('data.0.label', 'iPhone');
    }

    public function test_store_creates_url_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/pushcut-urls', [
                'url'    => 'https://api.pushcut.io/token/notifications/iPhone',
                'notify' => 'paid',
                'label'  => 'iPhone',
            ])
            ->assertCreated()
            ->assertJsonPath('data.url', 'https://api.pushcut.io/token/notifications/iPhone')
            ->assertJsonPath('data.notify', 'paid')
            ->assertJsonPath('data.label', 'iPhone');

        $this->assertDatabaseHas('user_pushcut_urls', [
            'user_id' => $user->id,
            'url'     => 'https://api.pushcut.io/token/notifications/iPhone',
            'notify'  => 'paid',
        ]);
    }

    public function test_store_validates_url_and_notify_are_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/pushcut-urls', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url', 'notify']);
    }

    public function test_store_validates_notify_enum_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/pushcut-urls', [
                'url'    => 'https://api.pushcut.io/token/notifications/iPhone',
                'notify' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notify']);
    }

    public function test_update_changes_notify_and_label(): void
    {
        $user = User::factory()->create();
        $dest = UserPushcutUrl::factory()->for($user)->notifyAll()->create(['label' => null]);

        $this->actingAs($user)
            ->putJson("/api/pushcut-urls/{$dest->id}", [
                'notify' => 'paid',
                'label'  => 'iPad',
            ])
            ->assertOk()
            ->assertJsonPath('data.notify', 'paid')
            ->assertJsonPath('data.label', 'iPad');
    }

    public function test_update_returns_403_for_another_users_url(): void
    {
        $user  = User::factory()->create();
        $other = UserPushcutUrl::factory()->create(); // belongs to a different user

        $this->actingAs($user)
            ->putJson("/api/pushcut-urls/{$other->id}", ['notify' => 'paid'])
            ->assertForbidden();
    }

    public function test_destroy_deletes_the_url(): void
    {
        $user = User::factory()->create();
        $dest = UserPushcutUrl::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/pushcut-urls/{$dest->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('user_pushcut_urls', ['id' => $dest->id]);
    }

    public function test_destroy_returns_403_for_another_users_url(): void
    {
        $user  = User::factory()->create();
        $other = UserPushcutUrl::factory()->create();

        $this->actingAs($user)
            ->deleteJson("/api/pushcut-urls/{$other->id}")
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/pushcut-urls')->assertUnauthorized();
    }
}
