<?php

namespace Tests\Feature\TiktokPixel;

use App\Models\TiktokPixel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiktokPixelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_only_authenticated_user_pixels(): void
    {
        $user = User::factory()->create();
        TiktokPixel::factory()->for($user)->create([
            'pixel_code' => 'CABC12345',
            'label' => 'Main',
        ]);
        TiktokPixel::factory()->create(); // another user's pixel

        $this->actingAs($user)
            ->getJson('/api/tiktok-pixels')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pixel_code', 'CABC12345')
            ->assertJsonPath('data.0.label', 'Main');
    }

    public function test_index_hides_access_token(): void
    {
        $user = User::factory()->create();
        TiktokPixel::factory()->for($user)->create(['access_token' => 'secret-token']);

        $this->actingAs($user)
            ->getJson('/api/tiktok-pixels')
            ->assertOk()
            ->assertJsonMissingPath('data.0.access_token');
    }

    public function test_store_creates_pixel_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tiktok-pixels', [
                'pixel_code' => 'CNEW12345',
                'access_token' => 'tt_access_123',
                'label' => 'Shop A',
                'enabled' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.pixel_code', 'CNEW12345')
            ->assertJsonPath('data.label', 'Shop A');

        $pixel = TiktokPixel::where('user_id', $user->id)->first();
        $this->assertNotNull($pixel);
        $this->assertSame('tt_access_123', $pixel->access_token);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tiktok-pixels', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pixel_code']);
    }

    public function test_store_requires_token_or_oauth_connection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/tiktok-pixels', ['pixel_code' => 'CFOO12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access_token']);
    }

    public function test_update_changes_enabled_and_label(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create(['enabled' => true, 'label' => null]);

        $this->actingAs($user)
            ->putJson("/api/tiktok-pixels/{$pixel->id}", [
                'enabled' => false,
                'label' => 'Backup',
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.label', 'Backup');
    }

    public function test_update_returns_403_for_another_users_pixel(): void
    {
        $user = User::factory()->create();
        $other = TiktokPixel::factory()->create();

        $this->actingAs($user)
            ->putJson("/api/tiktok-pixels/{$other->id}", ['enabled' => false])
            ->assertForbidden();
    }

    public function test_destroy_deletes_the_pixel(): void
    {
        $user = User::factory()->create();
        $pixel = TiktokPixel::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/tiktok-pixels/{$pixel->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('tiktok_pixels', ['id' => $pixel->id]);
    }

    public function test_destroy_returns_403_for_another_users_pixel(): void
    {
        $user = User::factory()->create();
        $other = TiktokPixel::factory()->create();

        $this->actingAs($user)
            ->deleteJson("/api/tiktok-pixels/{$other->id}")
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/tiktok-pixels')->assertUnauthorized();
    }
}
