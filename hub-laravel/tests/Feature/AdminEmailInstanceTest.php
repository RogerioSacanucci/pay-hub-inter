<?php

namespace Tests\Feature;

use App\Models\EmailServiceInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminEmailInstanceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->token = $admin->createToken('auth')->plainTextToken;
    }

    // ── Index ─────────────────────────────────────────────────────

    public function test_admin_can_list_email_instances(): void
    {
        EmailServiceInstance::factory()->count(3)->create();

        $response = $this->withToken($this->token)->getJson('/api/admin/email-instances');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'url', 'active', 'created_at', 'updated_at']],
            ]);

        // api_key must never appear in the response
        $this->assertArrayNotHasKey('api_key', $response->json('data.0'));
    }

    public function test_index_returns_instances_ordered_by_created_at_desc(): void
    {
        $older = EmailServiceInstance::factory()->create(['name' => 'older', 'created_at' => now()->subDay()]);
        $newer = EmailServiceInstance::factory()->create(['name' => 'newer', 'created_at' => now()]);

        $response = $this->withToken($this->token)->getJson('/api/admin/email-instances');

        $response->assertOk();
        $this->assertEquals('newer', $response->json('data.0.name'));
        $this->assertEquals('older', $response->json('data.1.name'));
    }

    // ── Store ─────────────────────────────────────────────────────

    public function test_admin_can_create_email_instance(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/admin/email-instances', [
            'name' => 'Instance 1',
            'url' => 'https://email.example.com',
            'api_key' => 'secret-key-123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Instance 1')
            ->assertJsonPath('data.url', 'https://email.example.com')
            ->assertJsonPath('data.active', true);

        // api_key must not appear in store response
        $this->assertArrayNotHasKey('api_key', $response->json('data'));

        $this->assertDatabaseHas('email_service_instances', ['name' => 'Instance 1']);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/admin/email-instances', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'url', 'api_key']);
    }

    public function test_store_validates_url_format(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/admin/email-instances', [
            'name' => 'Bad URL',
            'url' => 'not-a-url',
            'api_key' => 'key',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    // ── Update ────────────────────────────────────────────────────

    public function test_admin_can_update_email_instance(): void
    {
        $instance = EmailServiceInstance::factory()->create(['name' => 'Old Name']);

        $response = $this->withToken($this->token)->putJson("/api/admin/email-instances/{$instance->id}", [
            'name' => 'New Name',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        // api_key must not appear in update response
        $this->assertArrayNotHasKey('api_key', $response->json('data'));
    }

    public function test_admin_can_deactivate_instance(): void
    {
        $instance = EmailServiceInstance::factory()->create(['active' => true]);

        $response = $this->withToken($this->token)->putJson("/api/admin/email-instances/{$instance->id}", [
            'active' => false,
        ]);

        $response->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('email_service_instances', ['id' => $instance->id, 'active' => false]);
    }

    public function test_update_returns_404_for_nonexistent_instance(): void
    {
        $response = $this->withToken($this->token)->putJson('/api/admin/email-instances/9999', [
            'name' => 'Nope',
        ]);

        $response->assertNotFound();
    }

    // ── Destroy ───────────────────────────────────────────────────

    public function test_admin_can_delete_email_instance(): void
    {
        $instance = EmailServiceInstance::factory()->create();

        $response = $this->withToken($this->token)->deleteJson("/api/admin/email-instances/{$instance->id}");

        $response->assertOk()->assertJsonPath('message', 'Instance deleted');
        $this->assertDatabaseMissing('email_service_instances', ['id' => $instance->id]);
    }

    public function test_destroy_returns_404_for_nonexistent_instance(): void
    {
        $response = $this->withToken($this->token)->deleteJson('/api/admin/email-instances/9999');

        $response->assertNotFound();
    }

    // ── Auth ──────────────────────────────────────────────────────

    public function test_non_admin_cannot_access_email_instances(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/email-instances')->assertForbidden();
        $this->withToken($token)->postJson('/api/admin/email-instances', [])->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_email_instances(): void
    {
        $this->getJson('/api/admin/email-instances')->assertUnauthorized();
    }
}
