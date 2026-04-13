# Checkout Preview & Change Requests — Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Laravel API endpoints for checkout HTML preview (upload, signed URL serving) and user change requests (submit, list, admin manage).

**Architecture:** Two new DB tables (`checkout_previews`, `checkout_change_requests`), four controllers following the existing single-responsibility pattern, signed URL for serving the HTML preview without session auth issues, manual offset/limit pagination matching the codebase.

**Tech Stack:** PHP 8.4, Laravel 13, Octane, Sanctum, SQLite (test), Storage::disk('local'), URL::temporarySignedRoute

---

## File Map

**Create:**
- `database/migrations/2026_04_13_000001_create_checkout_previews_table.php`
- `database/migrations/2026_04_13_000002_create_checkout_change_requests_table.php`
- `app/Models/CheckoutPreview.php`
- `app/Models/CheckoutChangeRequest.php`
- `database/factories/CheckoutPreviewFactory.php`
- `database/factories/CheckoutChangeRequestFactory.php`
- `app/Http/Controllers/CheckoutPreviewController.php`
- `app/Http/Controllers/AdminCheckoutPreviewController.php`
- `app/Http/Controllers/CheckoutChangeRequestController.php`
- `app/Http/Controllers/AdminCheckoutChangeRequestController.php`
- `tests/Feature/CheckoutPreviewTest.php`
- `tests/Feature/CheckoutChangeRequestTest.php`

**Modify:**
- `routes/api.php` — add 7 new routes

---

## Task 1: Migrations, Models, and Factories

**Files:**
- Create: `database/migrations/2026_04_13_000001_create_checkout_previews_table.php`
- Create: `database/migrations/2026_04_13_000002_create_checkout_change_requests_table.php`
- Create: `app/Models/CheckoutPreview.php`
- Create: `app/Models/CheckoutChangeRequest.php`
- Create: `database/factories/CheckoutPreviewFactory.php`
- Create: `database/factories/CheckoutChangeRequestFactory.php`

- [ ] **Step 1: Create migrations via Artisan (run from `hub-laravel/hub-laravel/`)**

```bash
php artisan make:migration --no-interaction create_checkout_previews_table
php artisan make:migration --no-interaction create_checkout_change_requests_table
```

Rename the generated files to `2026_04_13_000001_create_checkout_previews_table.php` and `2026_04_13_000002_create_checkout_change_requests_table.php`.

- [ ] **Step 2: Write the checkout_previews migration**

Replace the `up()` body in `2026_04_13_000001_create_checkout_previews_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_previews');
    }
};
```

- [ ] **Step 3: Write the checkout_change_requests migration**

Replace `2026_04_13_000002_create_checkout_change_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->string('status')->default('pending');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_change_requests');
    }
};
```

- [ ] **Step 4: Run migrations**

```bash
php artisan migrate --no-interaction
```

Expected: both tables created, no errors.

- [ ] **Step 5: Create CheckoutPreview model**

```bash
php artisan make:model --no-interaction CheckoutPreview
```

Replace `app/Models/CheckoutPreview.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CheckoutPreviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'file_path'])]
class CheckoutPreview extends Model
{
    /** @use HasFactory<CheckoutPreviewFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 6: Create CheckoutChangeRequest model**

```bash
php artisan make:model --no-interaction CheckoutChangeRequest
```

Replace `app/Models/CheckoutChangeRequest.php`:

```php
<?php

namespace App\Models;

use Database\Factories\CheckoutChangeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'message', 'status'])]
class CheckoutChangeRequest extends Model
{
    /** @use HasFactory<CheckoutChangeRequestFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /** @var list<string> */
    public const STATUSES = ['pending', 'done'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 7: Create CheckoutPreviewFactory**

Create `database/factories/CheckoutPreviewFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutPreview>
 */
class CheckoutPreviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'file_path' => 'checkout-previews/' . fake()->numberBetween(1, 999) . '.html',
        ];
    }
}
```

- [ ] **Step 8: Create CheckoutChangeRequestFactory**

Create `database/factories/CheckoutChangeRequestFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\CheckoutChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckoutChangeRequest>
 */
class CheckoutChangeRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message' => fake()->paragraph(),
            'status' => 'pending',
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'done']);
    }
}
```

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_04_13_000001_create_checkout_previews_table.php \
        database/migrations/2026_04_13_000002_create_checkout_change_requests_table.php \
        app/Models/CheckoutPreview.php \
        app/Models/CheckoutChangeRequest.php \
        database/factories/CheckoutPreviewFactory.php \
        database/factories/CheckoutChangeRequestFactory.php
git commit -m "feat: checkout preview and change request models"
```

---

## Task 2: Register Routes

**Files:**
- Modify: `routes/api.php`

All controllers in this task are stubs — they will be implemented in later tasks. Register routes now so tests can reference them.

- [ ] **Step 1: Add all new routes to `routes/api.php`**

Add the following imports at the top of `routes/api.php` alongside existing imports:

```php
use App\Http\Controllers\AdminCheckoutChangeRequestController;
use App\Http\Controllers\AdminCheckoutPreviewController;
use App\Http\Controllers\CheckoutChangeRequestController;
use App\Http\Controllers\CheckoutPreviewController;
```

Add this route in the **Public** section (before the `auth:sanctum` group), so it must go before the `/checkout-preview/token` authenticated route to avoid route conflict:

```php
Route::get('/checkout-preview/{user}', [CheckoutPreviewController::class, 'show'])
    ->name('checkout-preview.show')
    ->middleware('signed');
```

Add inside the `auth:sanctum` group (before the admin middleware group):

```php
Route::get('/checkout-preview/token', [CheckoutPreviewController::class, 'token']);
Route::get('/checkout-change-requests', [CheckoutChangeRequestController::class, 'index']);
Route::post('/checkout-change-requests', [CheckoutChangeRequestController::class, 'store']);
```

Add inside the `auth:sanctum` + `AdminMiddleware` group:

```php
Route::get('admin/users/{user}/checkout-preview', [AdminCheckoutPreviewController::class, 'show']);
Route::post('admin/users/{user}/checkout-preview', [AdminCheckoutPreviewController::class, 'store']);
Route::delete('admin/users/{user}/checkout-preview', [AdminCheckoutPreviewController::class, 'destroy']);
Route::get('admin/checkout-change-requests', [AdminCheckoutChangeRequestController::class, 'index']);
Route::patch('admin/checkout-change-requests/{checkoutChangeRequest}', [AdminCheckoutChangeRequestController::class, 'update']);
```

> **Critical:** The public `GET /checkout-preview/{user}` route must be registered **before** the `auth:sanctum` group, and the `GET /checkout-preview/token` route (inside the authenticated group) must be declared before any parameterized route that could match "token" as a `{user}` segment. Since these are in separate route groups (public vs. authenticated), there's no conflict — Laravel matches the authenticated route first for authenticated requests.

- [ ] **Step 2: Create stub controllers (so route registration doesn't fail)**

```bash
php artisan make:controller --no-interaction CheckoutPreviewController
php artisan make:controller --no-interaction AdminCheckoutPreviewController
php artisan make:controller --no-interaction CheckoutChangeRequestController
php artisan make:controller --no-interaction AdminCheckoutChangeRequestController
```

- [ ] **Step 3: Verify routes are registered**

```bash
php artisan route:list --path=checkout --except-vendor
```

Expected: 8 rows showing the new routes with correct methods and middleware.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/api.php \
        app/Http/Controllers/CheckoutPreviewController.php \
        app/Http/Controllers/AdminCheckoutPreviewController.php \
        app/Http/Controllers/CheckoutChangeRequestController.php \
        app/Http/Controllers/AdminCheckoutChangeRequestController.php
git commit -m "feat: register checkout preview and change request routes"
```

---

## Task 3: Checkout Preview Token + Show (TDD)

**Files:**
- Modify: `app/Http/Controllers/CheckoutPreviewController.php`
- Create: `tests/Feature/CheckoutPreviewTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/CheckoutPreviewTest.php`:

```php
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

    public function test_unauthenticated_cannot_get_token(): void
    {
        $this->getJson('/api/checkout-preview/token')
            ->assertUnauthorized();
    }

    public function test_token_returns_has_preview_false_when_no_preview(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/checkout-preview/token')
            ->assertOk()
            ->assertJsonPath('has_preview', false)
            ->assertJsonMissing(['url']);
    }

    public function test_token_returns_signed_url_when_preview_exists(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;
        $path = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($path, '<html><body>Test</body></html>');
        CheckoutPreview::factory()->create(['user_id' => $user->id, 'file_path' => $path]);

        $response = $this->withToken($token)
            ->getJson('/api/checkout-preview/token')
            ->assertOk()
            ->assertJsonPath('has_preview', true);

        $this->assertNotEmpty($response->json('url'));
    }

    public function test_signed_url_serves_html_content(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($path, '<html><body>Preview</body></html>');
        CheckoutPreview::factory()->create(['user_id' => $user->id, 'file_path' => $path]);

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $user->id]
        );

        $response = $this->get($url);

        $response->assertOk();
        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $response->assertSee('Preview');
    }

    public function test_invalid_signature_returns_403(): void
    {
        $user = User::factory()->create();

        $this->get("/api/checkout-preview/{$user->id}?signature=invalid")
            ->assertForbidden();
    }

    public function test_valid_signature_but_no_preview_returns_404(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $user->id]
        );

        $this->get($url)->assertNotFound();
    }

    public function test_valid_signature_but_file_missing_from_disk_returns_404(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        // DB record exists but file is NOT on disk
        CheckoutPreview::factory()->create([
            'user_id' => $user->id,
            'file_path' => "checkout-previews/{$user->id}.html",
        ]);

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $user->id]
        );

        $this->get($url)->assertNotFound();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/CheckoutPreviewTest.php
```

Expected: all tests fail (controllers have no implementation).

- [ ] **Step 3: Implement CheckoutPreviewController**

Replace `app/Http/Controllers/CheckoutPreviewController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CheckoutPreviewController extends Controller
{
    public function token(): JsonResponse
    {
        $user = auth()->user();
        $preview = CheckoutPreview::where('user_id', $user->id)->first();

        if (! $preview) {
            return response()->json(['has_preview' => false]);
        }

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $user->id]
        );

        return response()->json(['has_preview' => true, 'url' => $url]);
    }

    public function show(User $user, Request $request): Response
    {
        $preview = CheckoutPreview::where('user_id', $user->id)->first();

        if (! $preview) {
            abort(404, 'No checkout preview found');
        }

        if (! Storage::disk('local')->exists($preview->file_path)) {
            abort(404, 'Preview file not found');
        }

        $content = Storage::disk('local')->get($preview->file_path);

        return response($content, 200, ['Content-Type' => 'text/html']);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/CheckoutPreviewTest.php
```

Expected: all 6 tests pass.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CheckoutPreviewController.php \
        tests/Feature/CheckoutPreviewTest.php
git commit -m "feat: checkout preview token and serve endpoints"
```

---

## Task 4: Admin Checkout Preview Upload + Delete (TDD)

**Files:**
- Modify: `app/Http/Controllers/AdminCheckoutPreviewController.php`
- Modify: `tests/Feature/CheckoutPreviewTest.php`

- [ ] **Step 1: Add failing tests to `tests/Feature/CheckoutPreviewTest.php`**

Add these test methods inside the class:

```php
    private string $adminToken;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->admin()->create();
        $this->adminToken = $this->adminUser->createToken('auth')->plainTextToken;
    }
```

> **Note:** Move the `setUp` above into the class and update any existing tests that create their own `$user` / `$token` locally — they still work since `setUp` only sets admin properties.

Append these test methods:

```php
    public function test_admin_can_get_preview_status(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$user->id}/checkout-preview")
            ->assertOk()
            ->assertJsonPath('has_preview', false);
    }

    public function test_admin_can_get_preview_status_when_exists(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($path, '<html></html>');
        CheckoutPreview::factory()->create(['user_id' => $user->id, 'file_path' => $path]);

        $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$user->id}/checkout-preview")
            ->assertOk()
            ->assertJsonPath('has_preview', true);
    }

    public function test_admin_can_upload_checkout_preview(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $file = \Illuminate\Http\UploadedFile::fake()->create('checkout.html', 100, 'text/html');

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$user->id}/checkout-preview", ['file' => $file])
            ->assertOk()
            ->assertJsonPath('message', 'Preview uploaded successfully');

        $this->assertDatabaseHas('checkout_previews', ['user_id' => $user->id]);
        Storage::disk('local')->assertExists("checkout-previews/{$user->id}.html");
    }

    public function test_upload_replaces_existing_preview(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($path, '<html>old</html>');
        CheckoutPreview::factory()->create(['user_id' => $user->id, 'file_path' => $path]);

        $newFile = \Illuminate\Http\UploadedFile::fake()->create('new.html', 50, 'text/html');

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$user->id}/checkout-preview", ['file' => $newFile])
            ->assertOk();

        $this->assertDatabaseCount('checkout_previews', 1);
    }

    public function test_upload_rejects_non_html_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $file = \Illuminate\Http\UploadedFile::fake()->create('shell.php', 10, 'text/x-php');

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$user->id}/checkout-preview", ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_rejects_file_over_2mb(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $file = \Illuminate\Http\UploadedFile::fake()->create('big.html', 3000, 'text/html');

        $this->withToken($this->adminToken)
            ->post("/api/admin/users/{$user->id}/checkout-preview", ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_non_admin_cannot_upload(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;
        $file = \Illuminate\Http\UploadedFile::fake()->create('checkout.html', 100, 'text/html');

        $this->withToken($token)
            ->post("/api/admin/users/{$user->id}/checkout-preview", ['file' => $file])
            ->assertForbidden();
    }

    public function test_admin_can_delete_checkout_preview(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($path, '<html></html>');
        CheckoutPreview::factory()->create(['user_id' => $user->id, 'file_path' => $path]);

        $this->withToken($this->adminToken)
            ->deleteJson("/api/admin/users/{$user->id}/checkout-preview")
            ->assertOk()
            ->assertJsonPath('message', 'Preview deleted');

        $this->assertDatabaseMissing('checkout_previews', ['user_id' => $user->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_delete_returns_404_when_no_preview(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminToken)
            ->deleteJson("/api/admin/users/{$user->id}/checkout-preview")
            ->assertNotFound();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/CheckoutPreviewTest.php
```

Expected: new admin tests fail.

- [ ] **Step 3: Implement AdminCheckoutPreviewController**

Replace `app/Http/Controllers/AdminCheckoutPreviewController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminCheckoutPreviewController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $exists = CheckoutPreview::where('user_id', $user->id)->exists();

        return response()->json(['has_preview' => $exists]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:html,htm', 'max:2048'],
        ]);

        $relativePath = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($relativePath, $request->file('file')->get());

        CheckoutPreview::updateOrCreate(
            ['user_id' => $user->id],
            ['file_path' => $relativePath]
        );

        return response()->json(['message' => 'Preview uploaded successfully']);
    }

    public function destroy(User $user): JsonResponse
    {
        $preview = CheckoutPreview::where('user_id', $user->id)->first();

        if (! $preview) {
            return response()->json(['message' => 'No preview found'], 404);
        }

        Storage::disk('local')->delete($preview->file_path);
        $preview->delete();

        return response()->json(['message' => 'Preview deleted']);
    }
}
```

- [ ] **Step 4: Run full test file**

```bash
php artisan test --compact tests/Feature/CheckoutPreviewTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AdminCheckoutPreviewController.php \
        tests/Feature/CheckoutPreviewTest.php
git commit -m "feat: admin checkout preview upload/delete endpoints"
```

---

## Task 5: Change Request User Endpoints (TDD)

**Files:**
- Modify: `app/Http/Controllers/CheckoutChangeRequestController.php`
- Create: `tests/Feature/CheckoutChangeRequestTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/CheckoutChangeRequestTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\CheckoutChangeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $userToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->userToken = $this->user->createToken('auth')->plainTextToken;
    }

    public function test_unauthenticated_cannot_list_requests(): void
    {
        $this->getJson('/api/checkout-change-requests')
            ->assertUnauthorized();
    }

    public function test_user_can_list_own_change_requests(): void
    {
        CheckoutChangeRequest::factory()->count(3)->create(['user_id' => $this->user->id]);
        CheckoutChangeRequest::factory()->create(); // another user's request

        $response = $this->withToken($this->userToken)
            ->getJson('/api/checkout-change-requests')
            ->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_list_returns_correct_fields(): void
    {
        CheckoutChangeRequest::factory()->create([
            'user_id' => $this->user->id,
            'message' => 'Change the color',
            'status' => 'pending',
        ]);

        $response = $this->withToken($this->userToken)
            ->getJson('/api/checkout-change-requests')
            ->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('message', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertEquals('Change the color', $item['message']);
        $this->assertEquals('pending', $item['status']);
    }

    public function test_list_returns_meta_pagination(): void
    {
        $this->withToken($this->userToken)
            ->getJson('/api/checkout-change-requests')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);
    }

    public function test_user_can_submit_change_request(): void
    {
        $response = $this->withToken($this->userToken)
            ->postJson('/api/checkout-change-requests', [
                'message' => 'Please change the font to Arial.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', 'Please change the font to Arial.')
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('checkout_change_requests', [
            'user_id' => $this->user->id,
            'message' => 'Please change the font to Arial.',
            'status' => 'pending',
        ]);
    }

    public function test_message_is_required(): void
    {
        $this->withToken($this->userToken)
            ->postJson('/api/checkout-change-requests', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_message_cannot_exceed_2000_chars(): void
    {
        $this->withToken($this->userToken)
            ->postJson('/api/checkout-change-requests', [
                'message' => str_repeat('a', 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_unauthenticated_cannot_submit(): void
    {
        $this->postJson('/api/checkout-change-requests', ['message' => 'test'])
            ->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/CheckoutChangeRequestTest.php
```

Expected: all fail.

- [ ] **Step 3: Implement CheckoutChangeRequestController**

Replace `app/Http/Controllers/CheckoutChangeRequestController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CheckoutChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = CheckoutChangeRequest::where('user_id', auth()->id())
            ->orderByDesc('created_at');

        $total = $query->count();
        $requests = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $requests->map(fn (CheckoutChangeRequest $r) => [
                'id' => $r->id,
                'message' => $r->message,
                'status' => $r->status,
                'created_at' => $r->created_at,
            ]),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $changeRequest = CheckoutChangeRequest::create([
            'user_id' => auth()->id(),
            'message' => $data['message'],
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'id' => $changeRequest->id,
                'message' => $changeRequest->message,
                'status' => $changeRequest->status,
                'created_at' => $changeRequest->created_at,
            ],
        ], 201);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/CheckoutChangeRequestTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CheckoutChangeRequestController.php \
        tests/Feature/CheckoutChangeRequestTest.php
git commit -m "feat: checkout change request user endpoints"
```

---

## Task 6: Admin Change Request Endpoints (TDD)

**Files:**
- Modify: `app/Http/Controllers/AdminCheckoutChangeRequestController.php`
- Modify: `tests/Feature/CheckoutChangeRequestTest.php`

- [ ] **Step 1: Add failing admin tests to `tests/Feature/CheckoutChangeRequestTest.php`**

Add a second setUp pattern for admin — add these properties and methods to the existing test class:

```php
    private User $admin;
    private string $adminToken;
```

Update `setUp()` to also create admin:

```php
    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->userToken = $this->user->createToken('auth')->plainTextToken;
        $this->admin = User::factory()->admin()->create();
        $this->adminToken = $this->admin->createToken('auth')->plainTextToken;
    }
```

Append test methods:

```php
    public function test_admin_can_list_all_change_requests(): void
    {
        CheckoutChangeRequest::factory()->count(2)->create(['user_id' => $this->user->id]);
        CheckoutChangeRequest::factory()->create(['user_id' => $this->admin->id]);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/checkout-change-requests')
            ->assertOk();

        $this->assertCount(3, $response->json('data'));
    }

    public function test_admin_list_includes_user_email(): void
    {
        CheckoutChangeRequest::factory()->create(['user_id' => $this->user->id]);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/checkout-change-requests')
            ->assertOk();

        $item = $response->json('data.0');
        $this->assertArrayHasKey('user_email', $item);
        $this->assertEquals($this->user->email, $item['user_email']);
    }

    public function test_admin_list_returns_meta(): void
    {
        $this->withToken($this->adminToken)
            ->getJson('/api/admin/checkout-change-requests')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);
    }

    public function test_regular_user_cannot_access_admin_list(): void
    {
        $this->withToken($this->userToken)
            ->getJson('/api/admin/checkout-change-requests')
            ->assertForbidden();
    }

    public function test_admin_can_mark_request_as_done(): void
    {
        $request = CheckoutChangeRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $this->withToken($this->adminToken)
            ->patchJson("/api/admin/checkout-change-requests/{$request->id}", [
                'status' => 'done',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertDatabaseHas('checkout_change_requests', [
            'id' => $request->id,
            'status' => 'done',
        ]);
    }

    public function test_admin_can_reopen_request(): void
    {
        $request = CheckoutChangeRequest::factory()->done()->create([
            'user_id' => $this->user->id,
        ]);

        $this->withToken($this->adminToken)
            ->patchJson("/api/admin/checkout-change-requests/{$request->id}", [
                'status' => 'pending',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_status_must_be_valid_value(): void
    {
        $request = CheckoutChangeRequest::factory()->create(['user_id' => $this->user->id]);

        $this->withToken($this->adminToken)
            ->patchJson("/api/admin/checkout-change-requests/{$request->id}", [
                'status' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_regular_user_cannot_update_status(): void
    {
        $request = CheckoutChangeRequest::factory()->create(['user_id' => $this->user->id]);

        $this->withToken($this->userToken)
            ->patchJson("/api/admin/checkout-change-requests/{$request->id}", [
                'status' => 'done',
            ])
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify new ones fail**

```bash
php artisan test --compact tests/Feature/CheckoutChangeRequestTest.php
```

Expected: new admin tests fail.

- [ ] **Step 3: Implement AdminCheckoutChangeRequestController**

Replace `app/Http/Controllers/AdminCheckoutChangeRequestController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CheckoutChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCheckoutChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = CheckoutChangeRequest::with('user:id,email')
            ->orderByDesc('created_at');

        $total = $query->count();
        $requests = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $requests->map(fn (CheckoutChangeRequest $r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'user_email' => $r->user?->email,
                'message' => $r->message,
                'status' => $r->status,
                'created_at' => $r->created_at,
            ]),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function update(Request $request, CheckoutChangeRequest $checkoutChangeRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,done'],
        ]);

        $checkoutChangeRequest->update($data);

        return response()->json([
            'data' => [
                'id' => $checkoutChangeRequest->id,
                'status' => $checkoutChangeRequest->status,
            ],
        ]);
    }
}
```

- [ ] **Step 4: Run full test suite for both test files**

```bash
php artisan test --compact tests/Feature/CheckoutPreviewTest.php tests/Feature/CheckoutChangeRequestTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Run full suite to confirm no regressions**

```bash
php artisan test --compact
```

Expected: all existing tests still pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AdminCheckoutChangeRequestController.php \
        tests/Feature/CheckoutChangeRequestTest.php
git commit -m "feat: admin checkout change request endpoints"
```
