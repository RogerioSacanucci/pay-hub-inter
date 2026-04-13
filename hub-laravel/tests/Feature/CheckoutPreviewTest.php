<?php

namespace Tests\Feature;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CheckoutPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('auth')->plainTextToken;
    }

    // --- Token endpoint ---

    public function test_unauthenticated_cannot_call_token(): void
    {
        $this->getJson('/api/checkout-preview/token')
            ->assertUnauthorized();
    }

    public function test_user_with_no_preview_gets_has_preview_false(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/checkout-preview/token')
            ->assertOk()
            ->assertJsonPath('has_preview', false)
            ->assertJsonMissingPath('url');
    }

    public function test_user_with_preview_gets_has_preview_true_and_url(): void
    {
        CheckoutPreview::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withToken($this->token)
            ->getJson('/api/checkout-preview/token')
            ->assertOk()
            ->assertJsonPath('has_preview', true);

        $this->assertNotEmpty($response->json('url'));
    }

    // --- Show endpoint (signed URL) ---

    public function test_valid_signed_url_returns_html(): void
    {
        Storage::fake('local');

        $preview = CheckoutPreview::factory()->create(['user_id' => $this->user->id]);
        Storage::disk('local')->put($preview->file_path, '<html><body>Preview</body></html>');

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $this->user->id]
        );

        $response = $this->get($url);

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Preview', $response->getContent());
    }

    public function test_invalid_signature_returns_forbidden(): void
    {
        $this->get("/api/checkout-preview/{$this->user->id}?signature=invalid")
            ->assertForbidden();
    }

    public function test_valid_signature_but_no_db_record_returns_not_found(): void
    {
        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $this->user->id]
        );

        $this->get($url)->assertNotFound();
    }

    public function test_valid_signature_but_file_missing_returns_not_found(): void
    {
        Storage::fake('local');

        CheckoutPreview::factory()->create([
            'user_id' => $this->user->id,
            'file_path' => 'previews/nonexistent.html',
        ]);

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $this->user->id]
        );

        $this->get($url)->assertNotFound();
    }
}
