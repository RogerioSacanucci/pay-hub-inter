# aaPanel File Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow admins to configure per-user aaPanel server connections and editable file links, and allow users to view and edit those files via a split-pane Monaco editor with live HTML preview.

**Architecture:** Backend adds two new tables (`user_aapanel_configs`, `user_links`), an `AaPanelService` for server-side file read/write via the aaPanel REST API, and endpoints for admin CRUD and user file access. Frontend adds a Links list page and a LinkEditor page with Monaco Editor and srcdoc iframe preview, plus admin management tabs in Settings.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, Octane, Sanctum — React 18, TypeScript, Vite, Tailwind CSS 3, `@monaco-editor/react`

---

## File Map

### Backend — New Files
| File | Responsibility |
|------|----------------|
| `database/migrations/2026_03_27_000001_create_user_aapanel_configs_table.php` | Schema for per-user aaPanel server credentials |
| `database/migrations/2026_03_27_000002_create_user_links_table.php` | Schema for editable file links per user |
| `app/Models/UserAapanelConfig.php` | Eloquent model, encrypts `api_key` via cast |
| `app/Models/UserLink.php` | Eloquent model, belongs to user + config |
| `database/factories/UserAapanelConfigFactory.php` | Test factory |
| `database/factories/UserLinkFactory.php` | Test factory |
| `app/Services/AaPanelService.php` | Wraps aaPanel API: `getFileContent`, `saveFileContent` |
| `app/Http/Controllers/AdminAaPanelConfigController.php` | Admin CRUD for aaPanel configs |
| `app/Http/Controllers/AdminUserLinkController.php` | Admin CRUD for user links |
| `app/Http/Controllers/UserLinkController.php` | User: list links, get/save file content |
| `tests/Unit/Services/AaPanelServiceTest.php` | Unit tests for service (Http::fake) |
| `tests/Feature/Admin/AaPanelConfigTest.php` | Feature tests for admin config endpoints |
| `tests/Feature/Admin/UserLinkAdminTest.php` | Feature tests for admin link endpoints |
| `tests/Feature/Links/UserLinkTest.php` | Feature tests for user link endpoints |

### Backend — Modified Files
| File | Change |
|------|--------|
| `app/Models/User.php` | Add `aapanelConfigs()` and `links()` HasMany |
| `routes/api.php` | Register admin + user link routes |

### Frontend — New Files
| File | Responsibility |
|------|----------------|
| `src/pages/Links.tsx` | Lists user's links as cards with Edit button |
| `src/pages/LinkEditor.tsx` | Monaco Editor + srcdoc iframe preview + save |
| `src/hooks/useLinks.ts` | Fetches user links |
| `src/hooks/useLinkEditor.ts` | Editor state, dirty tracking, save action |
| `src/components/admin/AaPanelConfigManager.tsx` | Admin CRUD for aaPanel configs |
| `src/components/admin/UserLinkManager.tsx` | Admin CRUD for user links |

### Frontend — Modified Files
| File | Change |
|------|--------|
| `src/api/client.ts` | Add types + API methods for links |
| `src/App.tsx` | Add `/links` and `/links/:id/edit` routes |
| `src/components/Layout.tsx` | Add Links nav item |
| `src/pages/Settings.tsx` | Add two admin tabs: aaPanel Configs + Links |

---

## Task 1: Database Migrations

**Files:**
- Create: `database/migrations/2026_03_27_000001_create_user_aapanel_configs_table.php`
- Create: `database/migrations/2026_03_27_000002_create_user_links_table.php`

- [ ] **Step 1: Create migration for user_aapanel_configs**

```bash
cd /Users/fabriciojuliano/Documents/ll/hub-laravel/hub-laravel
php artisan make:migration create_user_aapanel_configs_table --no-interaction
```

Edit the generated file to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_aapanel_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('panel_url');
            $table->text('api_key');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_aapanel_configs');
    }
};
```

- [ ] **Step 2: Create migration for user_links**

```bash
php artisan make:migration create_user_links_table --no-interaction
```

Edit the generated file to:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('aapanel_config_id')->constrained('user_aapanel_configs')->cascadeOnDelete();
            $table->string('label');
            $table->string('external_url');
            $table->string('file_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_links');
    }
};
```

- [ ] **Step 3: Run migrations**

```bash
php artisan migrate --no-interaction
```

Expected: both tables created with no errors.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: add user_aapanel_configs and user_links migrations"
```

---

## Task 2: Models, Factories, and User Relationships

**Files:**
- Create: `app/Models/UserAapanelConfig.php`
- Create: `app/Models/UserLink.php`
- Create: `database/factories/UserAapanelConfigFactory.php`
- Create: `database/factories/UserLinkFactory.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create UserAapanelConfig model**

```bash
php artisan make:model UserAapanelConfig --factory --no-interaction
```

Replace the generated model with:

```php
<?php

namespace App\Models;

use Database\Factories\UserAapanelConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'label', 'panel_url', 'api_key'])]
class UserAapanelConfig extends Model
{
    /** @use HasFactory<UserAapanelConfigFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(UserLink::class, 'aapanel_config_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
```

- [ ] **Step 2: Create UserLink model**

```bash
php artisan make:model UserLink --factory --no-interaction
```

Replace the generated model with:

```php
<?php

namespace App\Models;

use Database\Factories\UserLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'aapanel_config_id', 'label', 'external_url', 'file_path'])]
class UserLink extends Model
{
    /** @use HasFactory<UserLinkFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aapanelConfig(): BelongsTo
    {
        return $this->belongsTo(UserAapanelConfig::class, 'aapanel_config_id');
    }
}
```

- [ ] **Step 3: Write UserAapanelConfigFactory**

Replace the generated factory at `database/factories/UserAapanelConfigFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAapanelConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserAapanelConfig>
 */
class UserAapanelConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->words(2, true),
            'panel_url' => 'https://' . fake()->domainName() . ':7800',
            'api_key' => fake()->uuid(),
        ];
    }
}
```

- [ ] **Step 4: Write UserLinkFactory**

Replace the generated factory at `database/factories/UserLinkFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserAapanelConfig;
use App\Models\UserLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserLink>
 */
class UserLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'aapanel_config_id' => UserAapanelConfig::factory(),
            'label' => fake()->words(2, true),
            'external_url' => fake()->url(),
            'file_path' => '/www/wwwroot/' . fake()->domainName() . '/index.html',
        ];
    }
}
```

- [ ] **Step 5: Add relationships to User model**

In `app/Models/User.php`, add after the `cartpandaOrders()` method:

```php
public function aapanelConfigs(): HasMany
{
    return $this->hasMany(UserAapanelConfig::class);
}

public function links(): HasMany
{
    return $this->hasMany(UserLink::class);
}
```

Also add to imports at top of file:
```php
use App\Models\UserAapanelConfig;
use App\Models\UserLink;
```

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/ database/factories/
git commit -m "feat: add UserAapanelConfig and UserLink models with factories"
```

---

## Task 3: AaPanelService + Unit Tests

**Files:**
- Create: `app/Services/AaPanelService.php`
- Create: `tests/Unit/Services/AaPanelServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```bash
php artisan make:test --phpunit --unit Services/AaPanelServiceTest --no-interaction
```

Replace the generated file with:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\AaPanelService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AaPanelServiceTest extends TestCase
{
    public function test_get_file_content_returns_file_data(): void
    {
        Http::fake([
            'panel.example.com/*' => Http::response(['status' => true, 'data' => '<html>hello</html>'], 200),
        ]);

        $service = new AaPanelService('https://panel.example.com:7800', 'secret-key');
        $content = $service->getFileContent('/www/wwwroot/site/index.html');

        $this->assertSame('<html>hello</html>', $content);
    }

    public function test_get_file_content_sends_correct_params(): void
    {
        Http::fake([
            '*' => Http::response(['status' => true, 'data' => 'content'], 200),
        ]);

        $service = new AaPanelService('https://panel.example.com:7800', 'secret-key');
        $service->getFileContent('/www/wwwroot/site/index.html');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/files')
                && $request['action'] === 'GetFileBody'
                && $request['path'] === '/www/wwwroot/site/index.html'
                && isset($request['request_time'])
                && isset($request['request_token']);
        });
    }

    public function test_get_file_content_throws_on_aapanel_failure(): void
    {
        Http::fake([
            '*' => Http::response(['status' => false, 'msg' => 'File not found'], 200),
        ]);

        $service = new AaPanelService('https://panel.example.com:7800', 'secret-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read file: File not found');

        $service->getFileContent('/www/wwwroot/site/index.html');
    }

    public function test_save_file_content_sends_correct_params(): void
    {
        Http::fake([
            '*' => Http::response(['status' => true, 'msg' => 'save_success'], 200),
        ]);

        $service = new AaPanelService('https://panel.example.com:7800', 'secret-key');
        $service->saveFileContent('/www/wwwroot/site/index.html', '<html>new</html>');

        Http::assertSent(function ($request) {
            return $request['action'] === 'SaveFileBody'
                && $request['path'] === '/www/wwwroot/site/index.html'
                && $request['data'] === '<html>new</html>'
                && $request['encoding'] === 'utf-8';
        });
    }

    public function test_save_file_content_throws_on_aapanel_failure(): void
    {
        Http::fake([
            '*' => Http::response(['status' => false, 'msg' => 'Permission denied'], 200),
        ]);

        $service = new AaPanelService('https://panel.example.com:7800', 'secret-key');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to save file: Permission denied');

        $service->saveFileContent('/www/wwwroot/site/index.html', '<html>content</html>');
    }

    public function test_auth_token_is_md5_of_timestamp_and_hashed_key(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => ''], 200)]);

        $apiKey = 'my-secret-key';
        $service = new AaPanelService('https://panel.example.com:7800', $apiKey);
        $service->getFileContent('/some/file.html');

        Http::assertSent(function ($request) use ($apiKey) {
            $time = $request['request_time'];
            $expectedToken = md5($time . md5($apiKey));
            return $request['request_token'] === $expectedToken;
        });
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
php artisan test --compact tests/Unit/Services/AaPanelServiceTest.php
```

Expected: FAIL — `App\Services\AaPanelService` not found.

- [ ] **Step 3: Create AaPanelService**

```bash
php artisan make:class Services/AaPanelService --no-interaction
```

Replace with:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AaPanelService
{
    public function __construct(
        private string $panelUrl,
        private string $apiKey,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function getFileContent(string $filePath): string
    {
        $result = $this->makeRequest('/files', [
            'action' => 'GetFileBody',
            'path' => $filePath,
        ]);

        if (! ($result['status'] ?? false)) {
            throw new \RuntimeException('Failed to read file: '.($result['msg'] ?? 'Unknown error'));
        }

        return $result['data'] ?? '';
    }

    /**
     * @throws \RuntimeException
     */
    public function saveFileContent(string $filePath, string $content): void
    {
        $result = $this->makeRequest('/files', [
            'action' => 'SaveFileBody',
            'path' => $filePath,
            'data' => $content,
            'encoding' => 'utf-8',
        ]);

        if (! ($result['status'] ?? false)) {
            throw new \RuntimeException('Failed to save file: '.($result['msg'] ?? 'Unknown error'));
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function makeRequest(string $path, array $params): array
    {
        $timestamp = time();
        $token = md5($timestamp.md5($this->apiKey));

        $response = Http::timeout(30)
            ->asForm()
            ->post($this->panelUrl.$path, array_merge($params, [
                'request_time' => $timestamp,
                'request_token' => $token,
            ]));

        return $response->json() ?? [];
    }
}
```

- [ ] **Step 4: Run tests — expect pass**

```bash
php artisan test --compact tests/Unit/Services/AaPanelServiceTest.php
```

Expected: 5 tests, all passing.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AaPanelService.php tests/Unit/Services/AaPanelServiceTest.php
git commit -m "feat: add AaPanelService with getFileContent and saveFileContent"
```

---

## Task 4: AdminAaPanelConfigController + Feature Tests

**Files:**
- Create: `app/Http/Controllers/AdminAaPanelConfigController.php`
- Create: `tests/Feature/Admin/AaPanelConfigTest.php`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test --phpunit Admin/AaPanelConfigTest --no-interaction
```

Replace with:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserAapanelConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AaPanelConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_aapanel_configs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        UserAapanelConfig::factory()->for($user)->create(['label' => 'Servidor 1']);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/aapanel-configs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'user_email', 'label', 'panel_url', 'api_key_masked']],
            ])
            ->assertJsonPath('data.0.label', 'Servidor 1');
    }

    public function test_api_key_is_masked_in_list_response(): void
    {
        $admin = User::factory()->admin()->create();
        UserAapanelConfig::factory()->for(User::factory()->create())->create(['api_key' => 'super-secret-1234']);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/aapanel-configs');

        $response->assertOk();
        $this->assertStringNotContainsString('super-secret', $response->json('data.0.api_key_masked'));
        $this->assertStringEndsWith('1234', $response->json('data.0.api_key_masked'));
    }

    public function test_api_key_is_encrypted_in_database(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/aapanel-configs', [
            'user_id' => $user->id,
            'label' => 'Test Server',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'plaintext-secret',
        ]);

        $raw = \DB::table('user_aapanel_configs')->first();
        $this->assertNotSame('plaintext-secret', $raw->api_key);
    }

    public function test_admin_can_create_aapanel_config(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/aapanel-configs', [
            'user_id' => $user->id,
            'label' => 'My Server',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'secret-key-abc',
        ]);

        $response->assertCreated()
            ->assertJsonPath('label', 'My Server')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('user_aapanel_configs', ['user_id' => $user->id, 'label' => 'My Server']);
    }

    public function test_create_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/aapanel-configs', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'label', 'panel_url', 'api_key']);
    }

    public function test_create_validates_user_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/aapanel-configs', [
            'user_id' => 99999,
            'label' => 'Test',
            'panel_url' => 'https://panel.example.com:7800',
            'api_key' => 'secret',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_admin_can_update_aapanel_config(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create(['label' => 'Old Label']);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/admin/aapanel-configs/{$config->id}", [
            'label' => 'New Label',
        ]);

        $response->assertOk()->assertJsonPath('label', 'New Label');
        $this->assertDatabaseHas('user_aapanel_configs', ['id' => $config->id, 'label' => 'New Label']);
    }

    public function test_admin_can_update_api_key(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create(['api_key' => 'old-key-abcd']);
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->putJson("/api/admin/aapanel-configs/{$config->id}", [
            'api_key' => 'new-key-wxyz',
        ])->assertOk();

        $this->assertSame('new-key-wxyz', $config->fresh()->api_key);
    }

    public function test_admin_can_delete_aapanel_config(): void
    {
        $admin = User::factory()->admin()->create();
        $config = UserAapanelConfig::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/admin/aapanel-configs/{$config->id}")
            ->assertOk();

        $this->assertDatabaseMissing('user_aapanel_configs', ['id' => $config->id]);
    }

    public function test_non_admin_cannot_access_aapanel_configs(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/aapanel-configs')->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_aapanel_configs(): void
    {
        $this->getJson('/api/admin/aapanel-configs')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
php artisan test --compact tests/Feature/Admin/AaPanelConfigTest.php
```

Expected: FAIL — route not found.

- [ ] **Step 3: Create controller**

```bash
php artisan make:controller AdminAaPanelConfigController --no-interaction
```

Replace with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserAapanelConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAaPanelConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $configs = UserAapanelConfig::with('user:id,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserAapanelConfig $config) => [
                'id' => $config->id,
                'user_id' => $config->user_id,
                'user_email' => $config->user->email,
                'label' => $config->label,
                'panel_url' => $config->panel_url,
                'api_key_masked' => '****'.substr($config->api_key, -4),
                'created_at' => $config->created_at,
            ]);

        return response()->json(['data' => $configs]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'label' => ['required', 'string', 'max:255'],
            'panel_url' => ['required', 'url'],
            'api_key' => ['required', 'string'],
        ]);

        $config = UserAapanelConfig::create($data);

        return response()->json([
            'id' => $config->id,
            'user_id' => $config->user_id,
            'label' => $config->label,
            'panel_url' => $config->panel_url,
            'api_key_masked' => '****'.substr($config->api_key, -4),
            'created_at' => $config->created_at,
        ], 201);
    }

    public function update(Request $request, UserAapanelConfig $aapanelConfig): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'panel_url' => ['sometimes', 'url'],
            'api_key' => ['sometimes', 'string'],
        ]);

        $aapanelConfig->update($data);

        return response()->json([
            'id' => $aapanelConfig->id,
            'user_id' => $aapanelConfig->user_id,
            'label' => $aapanelConfig->label,
            'panel_url' => $aapanelConfig->panel_url,
            'api_key_masked' => '****'.substr($aapanelConfig->api_key, -4),
        ]);
    }

    public function destroy(UserAapanelConfig $aapanelConfig): JsonResponse
    {
        $aapanelConfig->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
```

- [ ] **Step 4: Register routes (temporary — full route registration in Task 7)**

In `routes/api.php`, inside the `AdminMiddleware` group, add:

```php
Route::apiResource('admin/aapanel-configs', AdminAaPanelConfigController::class)
    ->only(['index', 'store', 'update', 'destroy']);
```

Add import at top:
```php
use App\Http\Controllers\AdminAaPanelConfigController;
```

- [ ] **Step 5: Run tests — expect pass**

```bash
php artisan test --compact tests/Feature/Admin/AaPanelConfigTest.php
```

Expected: 10 tests, all passing.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AdminAaPanelConfigController.php tests/Feature/Admin/AaPanelConfigTest.php routes/api.php
git commit -m "feat: add admin CRUD endpoints for aaPanel configs"
```

---

## Task 5: AdminUserLinkController + Feature Tests

**Files:**
- Create: `app/Http/Controllers/AdminUserLinkController.php`
- Create: `tests/Feature/Admin/UserLinkAdminTest.php`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test --phpunit Admin/UserLinkAdminTest --no-interaction
```

Replace with:

```php
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
        $config = UserAapanelConfig::factory()->for($user)->create();
        UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create(['label' => 'Minha Página']);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/user-links');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'user_email', 'aapanel_config_id', 'aapanel_config_label', 'label', 'external_url', 'file_path']],
            ])
            ->assertJsonPath('data.0.label', 'Minha Página');
    }

    public function test_admin_can_create_user_link(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/user-links', [
            'user_id' => $user->id,
            'aapanel_config_id' => $config->id,
            'label' => 'Landing Page',
            'external_url' => 'https://meusite.com',
            'file_path' => '/www/wwwroot/meusite.com/index.html',
        ]);

        $response->assertCreated()
            ->assertJsonPath('label', 'Landing Page')
            ->assertJsonPath('file_path', '/www/wwwroot/meusite.com/index.html');

        $this->assertDatabaseHas('user_links', ['label' => 'Landing Page', 'user_id' => $user->id]);
    }

    public function test_create_rejects_config_belonging_to_different_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $configOfOtherUser = UserAapanelConfig::factory()->for($otherUser)->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/admin/user-links', [
            'user_id' => $user->id,
            'aapanel_config_id' => $configOfOtherUser->id,
            'label' => 'Bad Link',
            'external_url' => 'https://meusite.com',
            'file_path' => '/www/wwwroot/meusite.com/index.html',
        ]);

        $response->assertStatus(422);
    }

    public function test_create_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->postJson('/api/admin/user-links', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'aapanel_config_id', 'label', 'external_url', 'file_path']);
    }

    public function test_admin_can_update_user_link(): void
    {
        $admin = User::factory()->admin()->create();
        $link = UserLink::factory()->create(['label' => 'Old']);
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->putJson("/api/admin/user-links/{$link->id}", ['label' => 'New'])
            ->assertOk()
            ->assertJsonPath('label', 'New');
    }

    public function test_admin_can_delete_user_link(): void
    {
        $admin = User::factory()->admin()->create();
        $link = UserLink::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->deleteJson("/api/admin/user-links/{$link->id}")
            ->assertOk();

        $this->assertDatabaseMissing('user_links', ['id' => $link->id]);
    }

    public function test_non_admin_cannot_access_admin_user_links(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/user-links')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
php artisan test --compact tests/Feature/Admin/UserLinkAdminTest.php
```

Expected: FAIL — route not found.

- [ ] **Step 3: Create controller**

```bash
php artisan make:controller AdminUserLinkController --no-interaction
```

Replace with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserAapanelConfig;
use App\Models\UserLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserLinkController extends Controller
{
    public function index(): JsonResponse
    {
        $links = UserLink::with(['user:id,email', 'aapanelConfig:id,label'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserLink $link) => [
                'id' => $link->id,
                'user_id' => $link->user_id,
                'user_email' => $link->user->email,
                'aapanel_config_id' => $link->aapanel_config_id,
                'aapanel_config_label' => $link->aapanelConfig->label,
                'label' => $link->label,
                'external_url' => $link->external_url,
                'file_path' => $link->file_path,
                'created_at' => $link->created_at,
            ]);

        return response()->json(['data' => $links]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'aapanel_config_id' => ['required', 'integer', 'exists:user_aapanel_configs,id'],
            'label' => ['required', 'string', 'max:255'],
            'external_url' => ['required', 'url'],
            'file_path' => ['required', 'string', 'max:500'],
        ]);

        $configBelongsToUser = UserAapanelConfig::where('id', $data['aapanel_config_id'])
            ->where('user_id', $data['user_id'])
            ->exists();

        if (! $configBelongsToUser) {
            return response()->json(['errors' => ['aapanel_config_id' => ['aaPanel config does not belong to specified user']]], 422);
        }

        $link = UserLink::create($data);

        return response()->json([
            'id' => $link->id,
            'user_id' => $link->user_id,
            'aapanel_config_id' => $link->aapanel_config_id,
            'label' => $link->label,
            'external_url' => $link->external_url,
            'file_path' => $link->file_path,
            'created_at' => $link->created_at,
        ], 201);
    }

    public function update(Request $request, UserLink $userLink): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'external_url' => ['sometimes', 'url'],
            'file_path' => ['sometimes', 'string', 'max:500'],
        ]);

        $userLink->update($data);

        return response()->json([
            'id' => $userLink->id,
            'user_id' => $userLink->user_id,
            'aapanel_config_id' => $userLink->aapanel_config_id,
            'label' => $userLink->label,
            'external_url' => $userLink->external_url,
            'file_path' => $userLink->file_path,
        ]);
    }

    public function destroy(UserLink $userLink): JsonResponse
    {
        $userLink->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
```

- [ ] **Step 4: Register routes (add inside AdminMiddleware group in routes/api.php)**

```php
Route::apiResource('admin/user-links', AdminUserLinkController::class)
    ->only(['index', 'store', 'update', 'destroy']);
```

Add import:
```php
use App\Http\Controllers\AdminUserLinkController;
```

- [ ] **Step 5: Run tests — expect pass**

```bash
php artisan test --compact tests/Feature/Admin/UserLinkAdminTest.php
```

Expected: 7 tests, all passing.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AdminUserLinkController.php tests/Feature/Admin/UserLinkAdminTest.php routes/api.php
git commit -m "feat: add admin CRUD endpoints for user links"
```

---

## Task 6: UserLinkController + Feature Tests

**Files:**
- Create: `app/Http/Controllers/UserLinkController.php`
- Create: `tests/Feature/Links/UserLinkTest.php`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test --phpunit Links/UserLinkTest --no-interaction
```

Replace with:

```php
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

    public function test_user_can_list_their_links(): void
    {
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        UserLink::factory()->for($user)->for($config, 'aapanelConfig')->count(2)->create();
        UserLink::factory()->create(); // belongs to another user
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/links');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'label', 'external_url', 'file_path']]]);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_list_does_not_expose_aapanel_credentials(): void
    {
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create(['api_key' => 'secret-key']);
        UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/links');

        $content = json_encode($response->json());
        $this->assertStringNotContainsString('secret-key', $content);
        $this->assertStringNotContainsString('panel_url', $content);
    }

    public function test_user_can_get_file_content(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => '<html>page</html>'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        $link = UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/links/{$link->id}/content");

        $response->assertOk()->assertJsonPath('content', '<html>page</html>');
    }

    public function test_user_cannot_get_content_of_another_users_link(): void
    {
        $user = User::factory()->create();
        $otherLink = UserLink::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson("/api/links/{$otherLink->id}/content")
            ->assertForbidden();
    }

    public function test_aapanel_read_failure_returns_502(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'msg' => 'File not found'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        $link = UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson("/api/links/{$link->id}/content")
            ->assertStatus(502);
    }

    public function test_user_can_save_file_content(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'msg' => 'save_success'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        $link = UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->putJson("/api/links/{$link->id}/content", [
            'content' => '<html>updated</html>',
        ]);

        $response->assertOk()->assertJsonPath('message', 'File saved successfully');

        Http::assertSent(fn ($req) => $req['data'] === '<html>updated</html>');
    }

    public function test_save_requires_content_field(): void
    {
        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        $link = UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->putJson("/api/links/{$link->id}/content", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content');
    }

    public function test_user_cannot_save_content_of_another_users_link(): void
    {
        $user = User::factory()->create();
        $otherLink = UserLink::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->putJson("/api/links/{$otherLink->id}/content", [
            'content' => '<html>hack</html>',
        ])->assertForbidden();
    }

    public function test_aapanel_write_failure_returns_502(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'msg' => 'Permission denied'], 200)]);

        $user = User::factory()->create();
        $config = UserAapanelConfig::factory()->for($user)->create();
        $link = UserLink::factory()->for($user)->for($config, 'aapanelConfig')->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->putJson("/api/links/{$link->id}/content", [
            'content' => '<html>x</html>',
        ])->assertStatus(502);
    }

    public function test_admin_can_get_content_of_any_link(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => '<html>admin view</html>'], 200)]);

        $admin = User::factory()->admin()->create();
        $link = UserLink::factory()->create(); // belongs to another user
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson("/api/links/{$link->id}/content")
            ->assertOk()
            ->assertJsonPath('content', '<html>admin view</html>');
    }

    public function test_unauthenticated_cannot_list_links(): void
    {
        $this->getJson('/api/links')->assertUnauthorized();
    }
}
```

- [ ] **Step 2: Run tests — expect failure**

```bash
php artisan test --compact tests/Feature/Links/UserLinkTest.php
```

Expected: FAIL — route not found.

- [ ] **Step 3: Create controller**

```bash
php artisan make:controller UserLinkController --no-interaction
```

Replace with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserLink;
use App\Services\AaPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserLinkController extends Controller
{
    public function index(): JsonResponse
    {
        $links = auth()->user()->links()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (UserLink $link) => [
                'id' => $link->id,
                'label' => $link->label,
                'external_url' => $link->external_url,
                'file_path' => $link->file_path,
            ]);

        return response()->json(['data' => $links]);
    }

    public function getContent(UserLink $link): JsonResponse
    {
        $this->authorizeLink($link);

        $config = $link->aapanelConfig;
        $service = new AaPanelService($config->panel_url, $config->api_key);

        try {
            $content = $service->getFileContent($link->file_path);

            return response()->json(['content' => $content]);
        } catch (\RuntimeException) {
            return response()->json(['error' => 'Failed to fetch file content'], 502);
        }
    }

    public function saveContent(Request $request, UserLink $link): JsonResponse
    {
        $this->authorizeLink($link);

        $data = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $config = $link->aapanelConfig;
        $service = new AaPanelService($config->panel_url, $config->api_key);

        try {
            $service->saveFileContent($link->file_path, $data['content']);

            return response()->json(['message' => 'File saved successfully']);
        } catch (\RuntimeException) {
            return response()->json(['error' => 'Failed to save file content'], 502);
        }
    }

    private function authorizeLink(UserLink $link): void
    {
        $user = auth()->user();

        if ($link->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }
    }
}
```

- [ ] **Step 4: Register user-facing routes in `routes/api.php`**

Inside the `auth:sanctum` middleware group (but outside `AdminMiddleware`), add:

```php
Route::get('/links', [UserLinkController::class, 'index']);
Route::get('/links/{link}/content', [UserLinkController::class, 'getContent']);
Route::put('/links/{link}/content', [UserLinkController::class, 'saveContent']);
```

Add import:
```php
use App\Http\Controllers\UserLinkController;
```

- [ ] **Step 5: Run tests — expect pass**

```bash
php artisan test --compact tests/Feature/Links/UserLinkTest.php
```

Expected: 11 tests, all passing.

- [ ] **Step 6: Run full backend test suite**

```bash
php artisan test --compact
```

Expected: all tests passing.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/UserLinkController.php tests/Feature/Links/UserLinkTest.php routes/api.php
git commit -m "feat: add user link endpoints with aaPanel file read/write"
```

---

## Task 7: Frontend — API Types and Client Methods

**Working directory:** `/Users/fabriciojuliano/Documents/ll/dashboard`

**Files:**
- Modify: `src/api/client.ts`

- [ ] **Step 1: Install Monaco Editor**

```bash
cd /Users/fabriciojuliano/Documents/ll/dashboard
npm install @monaco-editor/react
```

- [ ] **Step 2: Add types and API methods to `src/api/client.ts`**

At the end of the interfaces section (after `UpdateUserPayload`), add:

```typescript
export interface UserLink {
  id: number;
  label: string;
  external_url: string;
  file_path: string;
}

export interface UserLinksResponse {
  data: UserLink[];
}

export interface AdminAaPanelConfig {
  id: number;
  user_id: number;
  user_email: string;
  label: string;
  panel_url: string;
  api_key_masked: string;
  created_at: string;
}

export interface AdminAaPanelConfigsResponse {
  data: AdminAaPanelConfig[];
}

export interface CreateAaPanelConfigPayload {
  user_id: number;
  label: string;
  panel_url: string;
  api_key: string;
}

export type UpdateAaPanelConfigPayload = Partial<Omit<CreateAaPanelConfigPayload, 'user_id'>>;

export interface AdminUserLink {
  id: number;
  user_id: number;
  user_email: string;
  aapanel_config_id: number;
  aapanel_config_label: string;
  label: string;
  external_url: string;
  file_path: string;
  created_at: string;
}

export interface AdminUserLinksResponse {
  data: AdminUserLink[];
}

export interface CreateUserLinkPayload {
  user_id: number;
  aapanel_config_id: number;
  label: string;
  external_url: string;
  file_path: string;
}

export type UpdateUserLinkPayload = Partial<Pick<CreateUserLinkPayload, 'label' | 'external_url' | 'file_path'>>;
```

At the end of the `api` object (before the closing `}`), add:

```typescript
  links: () =>
    request<UserLinksResponse>('/api/links'),

  getLinkContent: (id: number) =>
    request<{ content: string }>(`/api/links/${id}/content`),

  saveLinkContent: (id: number, content: string) =>
    request<{ message: string }>(`/api/links/${id}/content`, {
      method: 'PUT',
      body: JSON.stringify({ content }),
    }),

  adminAaPanelConfigs: () =>
    request<AdminAaPanelConfigsResponse>('/api/admin/aapanel-configs'),

  adminCreateAaPanelConfig: (payload: CreateAaPanelConfigPayload) =>
    request<AdminAaPanelConfig>('/api/admin/aapanel-configs', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),

  adminUpdateAaPanelConfig: (id: number, payload: UpdateAaPanelConfigPayload) =>
    request<AdminAaPanelConfig>(`/api/admin/aapanel-configs/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),

  adminDeleteAaPanelConfig: (id: number) =>
    request<{ message: string }>(`/api/admin/aapanel-configs/${id}`, { method: 'DELETE' }),

  adminUserLinks: () =>
    request<AdminUserLinksResponse>('/api/admin/user-links'),

  adminCreateUserLink: (payload: CreateUserLinkPayload) =>
    request<AdminUserLink>('/api/admin/user-links', {
      method: 'POST',
      body: JSON.stringify(payload),
    }),

  adminUpdateUserLink: (id: number, payload: UpdateUserLinkPayload) =>
    request<AdminUserLink>(`/api/admin/user-links/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    }),

  adminDeleteUserLink: (id: number) =>
    request<{ message: string }>(`/api/admin/user-links/${id}`, { method: 'DELETE' }),
```

- [ ] **Step 3: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/api/client.ts package.json package-lock.json
git commit -m "feat: add link and aaPanel config types and API methods"
```

---

## Task 8: Frontend — Hooks

**Files:**
- Create: `src/hooks/useLinks.ts`
- Create: `src/hooks/useLinkEditor.ts`

- [ ] **Step 1: Create `src/hooks/useLinks.ts`**

```typescript
import { useCallback, useEffect, useState } from 'react';
import { api, UserLink } from '../api/client';

export function useLinks() {
  const [links, setLinks] = useState<UserLink[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await api.links();
      setLinks(res.data);
    } catch {
      setError('Erro ao carregar links');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return { links, loading, error, reload: load };
}
```

- [ ] **Step 2: Create `src/hooks/useLinkEditor.ts`**

```typescript
import { useCallback, useEffect, useState } from 'react';
import { api } from '../api/client';

export function useLinkEditor(linkId: number) {
  const [content, setContent] = useState('');
  const [originalContent, setOriginalContent] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  useEffect(() => {
    setLoading(true);
    setError(null);
    api.getLinkContent(linkId)
      .then((res) => {
        setContent(res.content);
        setOriginalContent(res.content);
      })
      .catch(() => setError('Erro ao carregar conteúdo do arquivo'))
      .finally(() => setLoading(false));
  }, [linkId]);

  const save = useCallback(async (): Promise<boolean> => {
    setSaving(true);
    setSaveError(null);
    try {
      await api.saveLinkContent(linkId, content);
      setOriginalContent(content);
      return true;
    } catch {
      setSaveError('Erro ao salvar arquivo');
      return false;
    } finally {
      setSaving(false);
    }
  }, [linkId, content]);

  const isDirty = content !== originalContent;

  return { content, setContent, loading, saving, error, saveError, save, isDirty };
}
```

- [ ] **Step 3: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/hooks/useLinks.ts src/hooks/useLinkEditor.ts
git commit -m "feat: add useLinks and useLinkEditor hooks"
```

---

## Task 9: Frontend — Links Page

**Files:**
- Create: `src/pages/Links.tsx`

- [ ] **Step 1: Create `src/pages/Links.tsx`**

```typescript
import { useNavigate } from 'react-router-dom';
import { useLinks } from '../hooks/useLinks';

export default function Links() {
  const { links, loading, error } = useLinks();
  const navigate = useNavigate();

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-zinc-400 text-sm">Carregando links...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-red-400 text-sm">{error}</div>
      </div>
    );
  }

  if (links.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-2">
        <div className="text-zinc-400 text-sm">Nenhum link configurado</div>
        <div className="text-zinc-600 text-xs">Peça ao administrador para configurar seus links</div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-4">
      <h1 className="text-xl font-semibold text-white">Meus Links</h1>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {links.map((link) => (
          <div
            key={link.id}
            className="bg-surface-1 rounded-xl border border-zinc-800 p-5 flex flex-col gap-4"
          >
            <div className="flex flex-col gap-1">
              <span className="text-white font-medium text-sm">{link.label}</span>
              <a
                href={link.external_url}
                target="_blank"
                rel="noopener noreferrer"
                className="text-zinc-400 text-xs hover:text-brand truncate"
              >
                {link.external_url}
              </a>
            </div>
            <button
              onClick={() => navigate(`/links/${link.id}/edit`, { state: { link } })}
              className="w-full bg-brand hover:bg-brand-hover text-white text-xs font-medium py-2 px-4 rounded-lg transition-colors"
            >
              Editar arquivo
            </button>
          </div>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/pages/Links.tsx
git commit -m "feat: add Links page with link cards"
```

---

## Task 10: Frontend — LinkEditor Page

**Files:**
- Create: `src/pages/LinkEditor.tsx`

- [ ] **Step 1: Create `src/pages/LinkEditor.tsx`**

```typescript
import Editor from '@monaco-editor/react';
import { useEffect, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { UserLink } from '../api/client';
import { useLinkEditor } from '../hooks/useLinkEditor';

function getLanguage(filePath: string): string {
  return filePath.endsWith('.js') ? 'javascript' : 'html';
}

interface LocationState {
  link?: UserLink;
}

export default function LinkEditor() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const linkId = Number(id);
  const { content, setContent, loading, saving, error, saveError, save, isDirty } = useLinkEditor(linkId);

  // Recover link metadata from router state (set by Links page navigation)
  const linkRef = useRef<UserLink | null>(null);
  useEffect(() => {
    const state = (window.history.state?.usr ?? {}) as LocationState;
    if (state.link) {
      linkRef.current = state.link;
    }
  }, []);

  const link = linkRef.current;
  const language = link ? getLanguage(link.file_path) : 'html';

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen bg-canvas">
        <div className="text-zinc-400 text-sm">Carregando arquivo...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center h-screen bg-canvas gap-3">
        <div className="text-red-400 text-sm">{error}</div>
        <button
          onClick={() => navigate('/links')}
          className="text-zinc-400 text-xs hover:text-white"
        >
          ← Voltar para links
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-screen bg-canvas">
      {/* Toolbar */}
      <div className="flex items-center justify-between px-4 py-3 bg-surface-1 border-b border-zinc-800 flex-shrink-0">
        <div className="flex items-center gap-3">
          <button
            onClick={() => navigate('/links')}
            className="text-zinc-400 hover:text-white text-sm transition-colors"
          >
            ← Links
          </button>
          {link && (
            <span className="text-white text-sm font-medium">{link.label}</span>
          )}
          {isDirty && (
            <span className="text-xs text-zinc-500 italic">não salvo</span>
          )}
        </div>
        <div className="flex items-center gap-3">
          {link && (
            <a
              href={link.external_url}
              target="_blank"
              rel="noopener noreferrer"
              className="text-zinc-400 hover:text-white text-xs transition-colors"
            >
              Abrir site ↗
            </a>
          )}
          {saveError && (
            <span className="text-red-400 text-xs">{saveError}</span>
          )}
          <button
            onClick={save}
            disabled={saving || !isDirty}
            className="bg-brand hover:bg-brand-hover disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-medium py-1.5 px-4 rounded-lg transition-colors"
          >
            {saving ? 'Salvando...' : 'Salvar'}
          </button>
        </div>
      </div>

      {/* Split pane */}
      <div className="flex flex-1 overflow-hidden">
        {/* Editor */}
        <div className="w-1/2 border-r border-zinc-800">
          <Editor
            height="100%"
            language={language}
            value={content}
            onChange={(value) => setContent(value ?? '')}
            theme="vs-dark"
            options={{
              minimap: { enabled: false },
              fontSize: 13,
              wordWrap: 'on',
              scrollBeyondLastLine: false,
              automaticLayout: true,
            }}
          />
        </div>

        {/* Preview */}
        <div className="w-1/2 bg-white">
          <iframe
            srcDoc={content}
            title="preview"
            className="w-full h-full border-0"
            sandbox="allow-scripts allow-same-origin"
          />
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/pages/LinkEditor.tsx
git commit -m "feat: add LinkEditor page with Monaco editor and srcdoc preview"
```

---

## Task 11: Frontend — Admin Management Components

**Files:**
- Create: `src/components/admin/AaPanelConfigManager.tsx`
- Create: `src/components/admin/UserLinkManager.tsx`

- [ ] **Step 1: Create `src/components/admin/AaPanelConfigManager.tsx`**

```typescript
import { useEffect, useState } from 'react';
import {
  AdminAaPanelConfig,
  AdminUser,
  CreateAaPanelConfigPayload,
  UpdateAaPanelConfigPayload,
  api,
} from '../../api/client';

export default function AaPanelConfigManager() {
  const [configs, setConfigs] = useState<AdminAaPanelConfig[]>([]);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<AdminAaPanelConfig | null>(null);
  const [form, setForm] = useState({ user_id: '', label: '', panel_url: '', api_key: '' });
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    try {
      const [configsRes, usersRes] = await Promise.all([
        api.adminAaPanelConfigs(),
        api.adminUsers(1),
      ]);
      setConfigs(configsRes.data);
      setUsers(usersRes.data);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  function openCreate() {
    setEditing(null);
    setForm({ user_id: '', label: '', panel_url: '', api_key: '' });
    setError(null);
    setShowForm(true);
  }

  function openEdit(config: AdminAaPanelConfig) {
    setEditing(config);
    setForm({ user_id: String(config.user_id), label: config.label, panel_url: config.panel_url, api_key: '' });
    setError(null);
    setShowForm(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      if (editing) {
        const payload: UpdateAaPanelConfigPayload = {
          label: form.label,
          panel_url: form.panel_url,
          ...(form.api_key ? { api_key: form.api_key } : {}),
        };
        await api.adminUpdateAaPanelConfig(editing.id, payload);
      } else {
        const payload: CreateAaPanelConfigPayload = {
          user_id: Number(form.user_id),
          label: form.label,
          panel_url: form.panel_url,
          api_key: form.api_key,
        };
        await api.adminCreateAaPanelConfig(payload);
      }
      setShowForm(false);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erro ao salvar');
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Remover esta configuração? Os links associados também serão removidos.')) return;
    await api.adminDeleteAaPanelConfig(id);
    load();
  }

  if (loading) return <div className="text-zinc-400 text-sm py-4">Carregando...</div>;

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h3 className="text-white font-medium text-sm">Servidores aaPanel</h3>
        <button onClick={openCreate} className="bg-brand hover:bg-brand-hover text-white text-xs py-1.5 px-3 rounded-lg">
          + Adicionar
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-surface-2 rounded-xl p-4 space-y-3 border border-zinc-800">
          <div className="text-sm text-white font-medium">{editing ? 'Editar servidor' : 'Novo servidor'}</div>
          {!editing && (
            <select
              value={form.user_id}
              onChange={(e) => setForm({ ...form, user_id: e.target.value })}
              required
              className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
            >
              <option value="">Selecionar usuário...</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>{u.email}</option>
              ))}
            </select>
          )}
          <input
            value={form.label}
            onChange={(e) => setForm({ ...form, label: e.target.value })}
            placeholder="Label (ex: Servidor Principal)"
            required
            className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
          />
          <input
            value={form.panel_url}
            onChange={(e) => setForm({ ...form, panel_url: e.target.value })}
            placeholder="URL do painel (ex: https://panel.meusite.com:7800)"
            required
            className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
          />
          <input
            value={form.api_key}
            onChange={(e) => setForm({ ...form, api_key: e.target.value })}
            placeholder={editing ? 'Nova API key (deixe em branco para manter)' : 'API key'}
            required={!editing}
            type="password"
            className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
          />
          {error && <div className="text-red-400 text-xs">{error}</div>}
          <div className="flex gap-2">
            <button type="submit" className="bg-brand hover:bg-brand-hover text-white text-xs py-1.5 px-4 rounded-lg">
              Salvar
            </button>
            <button type="button" onClick={() => setShowForm(false)} className="text-zinc-400 text-xs py-1.5 px-4 rounded-lg hover:text-white">
              Cancelar
            </button>
          </div>
        </form>
      )}

      <div className="space-y-2">
        {configs.map((config) => (
          <div key={config.id} className="bg-surface-2 rounded-xl p-4 flex items-center justify-between border border-zinc-800">
            <div className="space-y-0.5">
              <div className="text-white text-sm font-medium">{config.label}</div>
              <div className="text-zinc-400 text-xs">{config.user_email} · {config.panel_url}</div>
              <div className="text-zinc-600 text-xs font-mono">{config.api_key_masked}</div>
            </div>
            <div className="flex gap-2">
              <button onClick={() => openEdit(config)} className="text-zinc-400 hover:text-white text-xs">Editar</button>
              <button onClick={() => handleDelete(config.id)} className="text-red-400 hover:text-red-300 text-xs">Remover</button>
            </div>
          </div>
        ))}
        {configs.length === 0 && (
          <div className="text-zinc-500 text-sm text-center py-6">Nenhum servidor configurado</div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Create `src/components/admin/UserLinkManager.tsx`**

```typescript
import { useEffect, useState } from 'react';
import {
  AdminAaPanelConfig,
  AdminUser,
  AdminUserLink,
  CreateUserLinkPayload,
  UpdateUserLinkPayload,
  api,
} from '../../api/client';

export default function UserLinkManager() {
  const [links, setLinks] = useState<AdminUserLink[]>([]);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [configs, setConfigs] = useState<AdminAaPanelConfig[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<AdminUserLink | null>(null);
  const [form, setForm] = useState({ user_id: '', aapanel_config_id: '', label: '', external_url: '', file_path: '' });
  const [error, setError] = useState<string | null>(null);

  async function load() {
    setLoading(true);
    try {
      const [linksRes, usersRes, configsRes] = await Promise.all([
        api.adminUserLinks(),
        api.adminUsers(1),
        api.adminAaPanelConfigs(),
      ]);
      setLinks(linksRes.data);
      setUsers(usersRes.data);
      setConfigs(configsRes.data);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => { load(); }, []);

  // Filter configs by selected user
  const filteredConfigs = configs.filter(
    (c) => !form.user_id || c.user_id === Number(form.user_id)
  );

  function openCreate() {
    setEditing(null);
    setForm({ user_id: '', aapanel_config_id: '', label: '', external_url: '', file_path: '' });
    setError(null);
    setShowForm(true);
  }

  function openEdit(link: AdminUserLink) {
    setEditing(link);
    setForm({
      user_id: String(link.user_id),
      aapanel_config_id: String(link.aapanel_config_id),
      label: link.label,
      external_url: link.external_url,
      file_path: link.file_path,
    });
    setError(null);
    setShowForm(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      if (editing) {
        const payload: UpdateUserLinkPayload = {
          label: form.label,
          external_url: form.external_url,
          file_path: form.file_path,
        };
        await api.adminUpdateUserLink(editing.id, payload);
      } else {
        const payload: CreateUserLinkPayload = {
          user_id: Number(form.user_id),
          aapanel_config_id: Number(form.aapanel_config_id),
          label: form.label,
          external_url: form.external_url,
          file_path: form.file_path,
        };
        await api.adminCreateUserLink(payload);
      }
      setShowForm(false);
      load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erro ao salvar');
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Remover este link?')) return;
    await api.adminDeleteUserLink(id);
    load();
  }

  if (loading) return <div className="text-zinc-400 text-sm py-4">Carregando...</div>;

  return (
    <div className="space-y-4">
      <div className="flex justify-between items-center">
        <h3 className="text-white font-medium text-sm">Links de usuários</h3>
        <button onClick={openCreate} className="bg-brand hover:bg-brand-hover text-white text-xs py-1.5 px-3 rounded-lg">
          + Adicionar
        </button>
      </div>

      {showForm && (
        <form onSubmit={handleSubmit} className="bg-surface-2 rounded-xl p-4 space-y-3 border border-zinc-800">
          <div className="text-sm text-white font-medium">{editing ? 'Editar link' : 'Novo link'}</div>
          {!editing && (
            <>
              <select
                value={form.user_id}
                onChange={(e) => setForm({ ...form, user_id: e.target.value, aapanel_config_id: '' })}
                required
                className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
              >
                <option value="">Selecionar usuário...</option>
                {users.map((u) => (
                  <option key={u.id} value={u.id}>{u.email}</option>
                ))}
              </select>
              <select
                value={form.aapanel_config_id}
                onChange={(e) => setForm({ ...form, aapanel_config_id: e.target.value })}
                required
                disabled={!form.user_id}
                className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2 disabled:opacity-50"
              >
                <option value="">Selecionar servidor aaPanel...</option>
                {filteredConfigs.map((c) => (
                  <option key={c.id} value={c.id}>{c.label} ({c.panel_url})</option>
                ))}
              </select>
            </>
          )}
          <input
            value={form.label}
            onChange={(e) => setForm({ ...form, label: e.target.value })}
            placeholder="Label (ex: Página Principal)"
            required
            className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
          />
          <input
            value={form.external_url}
            onChange={(e) => setForm({ ...form, external_url: e.target.value })}
            placeholder="URL externa (ex: https://meusite.com)"
            required
            className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2"
          />
          <input
            value={form.file_path}
            onChange={(e) => setForm({ ...form, file_path: e.target.value })}
            placeholder="Caminho do arquivo (ex: /www/wwwroot/meusite.com/index.html)"
            required
            className="w-full bg-surface-1 text-white text-sm border border-zinc-700 rounded-lg px-3 py-2 font-mono text-xs"
          />
          {error && <div className="text-red-400 text-xs">{error}</div>}
          <div className="flex gap-2">
            <button type="submit" className="bg-brand hover:bg-brand-hover text-white text-xs py-1.5 px-4 rounded-lg">
              Salvar
            </button>
            <button type="button" onClick={() => setShowForm(false)} className="text-zinc-400 text-xs py-1.5 px-4 rounded-lg hover:text-white">
              Cancelar
            </button>
          </div>
        </form>
      )}

      <div className="space-y-2">
        {links.map((link) => (
          <div key={link.id} className="bg-surface-2 rounded-xl p-4 flex items-center justify-between border border-zinc-800">
            <div className="space-y-0.5 min-w-0">
              <div className="text-white text-sm font-medium">{link.label}</div>
              <div className="text-zinc-400 text-xs">{link.user_email} · {link.aapanel_config_label}</div>
              <div className="text-zinc-500 text-xs font-mono truncate">{link.file_path}</div>
            </div>
            <div className="flex gap-2 flex-shrink-0 ml-4">
              <button onClick={() => openEdit(link)} className="text-zinc-400 hover:text-white text-xs">Editar</button>
              <button onClick={() => handleDelete(link.id)} className="text-red-400 hover:text-red-300 text-xs">Remover</button>
            </div>
          </div>
        ))}
        {links.length === 0 && (
          <div className="text-zinc-500 text-sm text-center py-6">Nenhum link configurado</div>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/components/admin/
git commit -m "feat: add admin AaPanelConfigManager and UserLinkManager components"
```

---

## Task 12: Integration — Routes, Nav, and Settings Tabs

**Files:**
- Modify: `src/App.tsx`
- Modify: `src/components/Layout.tsx`
- Modify: `src/pages/Settings.tsx`

- [ ] **Step 1: Add routes in `src/App.tsx`**

Add imports at top:
```typescript
import Links from './pages/Links';
import LinkEditor from './pages/LinkEditor';
```

Inside the authenticated route group (alongside `/transactions`, `/settings`, etc.), add:
```typescript
<Route path="/links" element={<Links />} />
<Route path="/links/:id/edit" element={<LinkEditor />} />
```

- [ ] **Step 2: Add Links nav item in `src/components/Layout.tsx`**

Find where the nav links are defined (the list with Dashboard, Transactions, etc.) and add a Links entry following the same pattern used for existing items. Example — if items are defined as an array or inline JSX with NavLink:

```typescript
// Add after the Transactions entry, following the same JSX pattern used by sibling items:
<NavLink
  to="/links"
  className={({ isActive }) =>
    `flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors ${
      isActive ? 'bg-surface-2 text-white' : 'text-zinc-400 hover:text-white'
    }`
  }
>
  Links
</NavLink>
```

Match the exact structure of neighboring nav items — do not introduce new patterns.

- [ ] **Step 3: Add admin tabs in `src/pages/Settings.tsx`**

Add imports at top:
```typescript
import AaPanelConfigManager from '../components/admin/AaPanelConfigManager';
import UserLinkManager from '../components/admin/UserLinkManager';
```

In the tabs array or tab definitions (follow the existing pattern using the `Tabs` component and `adminOnly` flag), add two new admin tabs after the existing Users tab:

```typescript
{ id: 'aapanel', label: 'Servidores aaPanel', adminOnly: true },
{ id: 'links-admin', label: 'Links', adminOnly: true },
```

And in the tab content rendering section, add:

```typescript
{activeTab === 'aapanel' && <AaPanelConfigManager />}
{activeTab === 'links-admin' && <UserLinkManager />}
```

- [ ] **Step 4: Verify TypeScript compiles**

```bash
npx tsc --noEmit
```

Expected: no errors.

- [ ] **Step 5: Build to verify no runtime errors**

```bash
npm run build
```

Expected: build completes with no errors.

- [ ] **Step 6: Commit**

```bash
git add src/App.tsx src/components/Layout.tsx src/pages/Settings.tsx
git commit -m "feat: integrate links routes, nav item, and admin settings tabs"
```

---

## Self-Review

### Spec Coverage

| Requirement | Task |
|-------------|------|
| Per-user links with label + external URL | Tasks 1, 2, 5 |
| aaPanel config per user (API key encrypted) | Tasks 1, 2, 4 |
| Admin defines file paths, creates links | Tasks 4, 5, 11 |
| User can view their links | Tasks 6, 9 |
| User can edit file content | Tasks 3, 6, 10 |
| Real-time HTML preview (srcdoc) | Task 10 |
| Monaco editor with language detection | Task 10 |
| Save reflects on aaPanel | Tasks 3, 6 |
| Admin can edit on behalf of user | Task 6 (`authorizeLink` allows admin) |
| API key never exposed to frontend | Tasks 4, 6 (masked in admin, never sent to user) |

### Type Consistency Check

- `UserLink` interface defined in Task 7, used in Tasks 8, 9, 10 ✓
- `AdminAaPanelConfig` defined in Task 7, used in Task 11 ✓
- `AdminUserLink` defined in Task 7, used in Task 11 ✓
- `api.links()` returns `UserLinksResponse`, `useLinks` calls `res.data` ✓
- `api.getLinkContent(id)` returns `{ content: string }`, `useLinkEditor` reads `res.content` ✓
- `UserAapanelConfig` model uses `'api_key' => 'encrypted'` cast — accessed as plain string in `AaPanelService` constructor ✓
- Route model binding uses `{link}` → `UserLink $link` and `{aapanelConfig}` → `UserAapanelConfig $aapanelConfig` ✓
- `UserLinkFactory` creates `aapanel_config_id` FK; tests use `.for($config, 'aapanelConfig')` ✓
