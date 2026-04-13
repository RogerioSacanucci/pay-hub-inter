<?php

namespace Tests\Feature;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CheckoutPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private string $adminToken;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('auth')->plainTextToken;

        $admin = User::factory()->admin()->create();
        $this->adminToken = $admin->createToken('auth')->plainTextToken;
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

    // --- Admin status endpoint ---

    public function test_admin_gets_has_preview_false_when_no_preview(): void
    {
        $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$this->user->id}/checkout-preview")
            ->assertOk()
            ->assertJsonPath('has_preview', false);
    }

    public function test_admin_gets_has_preview_true_when_preview_exists(): void
    {
        CheckoutPreview::factory()->create(['user_id' => $this->user->id]);

        $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$this->user->id}/checkout-preview")
            ->assertOk()
            ->assertJsonPath('has_preview', true);
    }

    // --- Admin upload endpoint ---

    public function test_admin_can_upload_html_preview(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('checkout.html', 100, 'text/html');

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$this->user->id}/checkout-preview", ['file' => $file])
            ->assertOk();

        Storage::disk('local')->assertExists("checkout-previews/{$this->user->id}.html");
        $this->assertDatabaseHas('checkout_previews', ['user_id' => $this->user->id]);
    }

    public function test_admin_reupload_replaces_existing_preview(): void
    {
        Storage::fake('local');

        $file1 = UploadedFile::fake()->create('v1.html', 50, 'text/html');
        $file2 = UploadedFile::fake()->create('v2.html', 60, 'text/html');

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$this->user->id}/checkout-preview", ['file' => $file1])
            ->assertOk();

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$this->user->id}/checkout-preview", ['file' => $file2])
            ->assertOk();

        $this->assertDatabaseCount('checkout_previews', 1);
        Storage::disk('local')->assertExists("checkout-previews/{$this->user->id}.html");
    }

    public function test_upload_rejects_wrong_mime_type(): void
    {
        Storage::fake('local');

        $badFile = UploadedFile::fake()->create('shell.php', 10, 'text/x-php');

        $this->withToken($this->adminToken)
            ->withHeader('Accept', 'application/json')
            ->post("/api/admin/users/{$this->user->id}/checkout-preview", ['file' => $badFile])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_rejects_file_over_2mb(): void
    {
        Storage::fake('local');

        $bigFile = UploadedFile::fake()->create('big.html', 3000, 'text/html');

        $this->withToken($this->adminToken)
            ->withHeader('Accept', 'application/json')
            ->post("/api/admin/users/{$this->user->id}/checkout-preview", ['file' => $bigFile])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_non_admin_cannot_upload_preview(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('checkout.html', 100, 'text/html');

        $this->withToken($this->token)
            ->post("/api/admin/users/{$this->user->id}/checkout-preview", ['file' => $file])
            ->assertForbidden();
    }

    // --- Admin delete endpoint ---

    public function test_admin_can_delete_preview(): void
    {
        Storage::fake('local');

        $preview = CheckoutPreview::factory()->create([
            'user_id' => $this->user->id,
            'file_path' => "checkout-previews/{$this->user->id}.html",
        ]);
        Storage::disk('local')->put($preview->file_path, '<html></html>');

        $this->withToken($this->adminToken)
            ->delete("/api/admin/users/{$this->user->id}/checkout-preview")
            ->assertOk();

        Storage::disk('local')->assertMissing("checkout-previews/{$this->user->id}.html");
        $this->assertDatabaseMissing('checkout_previews', ['user_id' => $this->user->id]);
    }

    public function test_delete_with_no_preview_returns_404(): void
    {
        $this->withToken($this->adminToken)
            ->delete("/api/admin/users/{$this->user->id}/checkout-preview")
            ->assertNotFound();
    }
}
