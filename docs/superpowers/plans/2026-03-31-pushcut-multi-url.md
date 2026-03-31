# Pushcut Multi-URL + Cartpanda Status Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single `pushcut_url`/`pushcut_notify` columns on `users` with a `user_pushcut_urls` table (each URL has its own `notify` preference), update all notification logic, fix the Cartpanda `order.created` status mapping, and add a CRUD UI in the dashboard.

**Architecture:** One migration creates the new table, migrates existing data, and drops the old columns. A new `PushcutUrlController` exposes REST CRUD. The three controllers that send Pushcut notifications (`PaymentController`, `WebhookController`, `CartpandaWebhookController`) are updated to iterate `$user->pushcutUrls`. The `Settings.tsx` notifications tab is replaced with a two-card layout (add form + list).

**Tech Stack:** Laravel 13, PHP 8.4, Sanctum auth, React 18, TypeScript, Tailwind CSS 3

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `hub-laravel/database/migrations/2026_03_31_000000_create_user_pushcut_urls_table.php` | Create table + data migration + drop old columns |
| Create | `hub-laravel/app/Models/UserPushcutUrl.php` | Eloquent model |
| Create | `hub-laravel/database/factories/UserPushcutUrlFactory.php` | Test factory with state helpers |
| Modify | `hub-laravel/app/Models/User.php` | Remove pushcut fillable fields, add `pushcutUrls()` relation |
| Create | `hub-laravel/app/Http/Controllers/PushcutUrlController.php` | CRUD controller |
| Modify | `hub-laravel/routes/api.php` | Add `pushcut-urls` resource route, remove `auth/update` route |
| Modify | `hub-laravel/app/Http/Controllers/PaymentController.php` | Iterate `pushcutUrls` relation |
| Modify | `hub-laravel/app/Http/Controllers/WebhookController.php` | Iterate `pushcutUrls` relation |
| Modify | `hub-laravel/app/Http/Controllers/CartpandaWebhookController.php` | Fix STATUS_MAP + iterate `pushcutUrls` |
| Modify | `hub-laravel/app/Http/Controllers/AdminUserController.php` | Remove pushcut fields from responses/validation |
| Modify | `hub-laravel/app/Http/Controllers/AuthController.php` | Remove pushcut from register/formatUser, delete `update()` |
| Modify | `hub-laravel/database/factories/CartpandaOrderFactory.php` | Fix `pending()` state event to `order.created` |
| Create | `hub-laravel/tests/Feature/PushcutUrl/PushcutUrlTest.php` | CRUD + auth tests |
| Modify | `hub-laravel/tests/Feature/Cartpanda/CartpandaWebhookTest.php` | Fix pushcut factory calls, rename/add order.created tests |
| Modify | `hub-laravel/tests/Feature/Auth/AuthTest.php` | Remove pushcut settings test |
| Modify | `dashboard/src/api/client.ts` | Remove old pushcut types/method, add new CRUD types/methods |
| Modify | `dashboard/src/pages/Settings.tsx` | Replace notifications tab |

---

## Task 1: Migration — create table, migrate data, drop old columns

**Files:**
- Create: `hub-laravel/database/migrations/2026_03_31_000000_create_user_pushcut_urls_table.php`

> No test needed for the migration itself — the running tests use `RefreshDatabase` which runs migrations automatically. Run the migration manually to verify.

- [ ] **Step 1: Create the migration file**

```bash
cd hub-laravel
php artisan make:migration create_user_pushcut_urls_table --no-interaction
```

Rename the generated file to `2026_03_31_000000_create_user_pushcut_urls_table.php`, then replace its contents entirely:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_pushcut_urls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('url', 500);
            $table->enum('notify', ['all', 'created', 'paid'])->default('all');
            $table->string('label', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('users')
            ->whereNotNull('pushcut_url')
            ->where('pushcut_url', '!=', '')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('user_pushcut_urls')->insert([
                    'user_id'    => $user->id,
                    'url'        => $user->pushcut_url,
                    'notify'     => $user->pushcut_notify,
                    'created_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['pushcut_url', 'pushcut_notify']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pushcut_url', 500)->default('')->after('failed_url');
            $table->enum('pushcut_notify', ['all', 'created', 'paid'])->default('all')->after('pushcut_url');
        });

        DB::table('user_pushcut_urls')->orderBy('id')->each(function ($dest) {
            DB::table('users')->where('id', $dest->user_id)->update([
                'pushcut_url'    => $dest->url,
                'pushcut_notify' => $dest->notify,
            ]);
        });

        Schema::dropIfExists('user_pushcut_urls');
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected output: `Migrating: 2026_03_31_000000_create_user_pushcut_urls_table` followed by `Migrated`.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_03_31_000000_create_user_pushcut_urls_table.php
git commit -m "feat: add user_pushcut_urls migration with data migration"
```

---

## Task 2: Model, Factory, and User relation

**Files:**
- Create: `hub-laravel/app/Models/UserPushcutUrl.php`
- Create: `hub-laravel/database/factories/UserPushcutUrlFactory.php`
- Modify: `hub-laravel/app/Models/User.php`

- [ ] **Step 1: Create the model**

`hub-laravel/app/Models/UserPushcutUrl.php`:

```php
<?php

namespace App\Models;

use Database\Factories\UserPushcutUrlFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'url', 'notify', 'label'])]
class UserPushcutUrl extends Model
{
    /** @use HasFactory<UserPushcutUrlFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 2: Create the factory**

`hub-laravel/database/factories/UserPushcutUrlFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPushcutUrl;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPushcutUrl>
 */
class UserPushcutUrlFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'url'     => fake()->url(),
            'notify'  => fake()->randomElement(['all', 'created', 'paid']),
            'label'   => fake()->optional()->word(),
        ];
    }

    public function notifyAll(): static
    {
        return $this->state(fn (array $attributes) => ['notify' => 'all']);
    }

    public function notifyPaid(): static
    {
        return $this->state(fn (array $attributes) => ['notify' => 'paid']);
    }

    public function notifyCreated(): static
    {
        return $this->state(fn (array $attributes) => ['notify' => 'created']);
    }
}
```

- [ ] **Step 3: Update `User` model**

In `hub-laravel/app/Models/User.php`:

Replace the `#[Fillable([...])]` attribute — remove `'pushcut_url'` and `'pushcut_notify'`:

```php
#[Fillable([
    'email',
    'password_hash',
    'payer_email',
    'payer_name',
    'success_url',
    'failed_url',
    'role',
    'cartpanda_param',
    'active',
])]
```

Add the import and the relation method (place after `balance()`):

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
// (HasMany is likely already imported — check the existing imports first)

public function pushcutUrls(): HasMany
{
    return $this->hasMany(UserPushcutUrl::class);
}
```

Also add the `UserPushcutUrl` use statement at the top of the file if not already present:
```php
use App\Models\UserPushcutUrl;
```

- [ ] **Step 4: Run pint**

```bash
vendor/bin/pint app/Models/UserPushcutUrl.php database/factories/UserPushcutUrlFactory.php app/Models/User.php --format agent
```

- [ ] **Step 5: Commit**

```bash
git add app/Models/UserPushcutUrl.php database/factories/UserPushcutUrlFactory.php app/Models/User.php
git commit -m "feat: add UserPushcutUrl model, factory, and User relation"
```

---

## Task 3: PushcutUrlController — write tests first, then implement

**Files:**
- Create: `hub-laravel/tests/Feature/PushcutUrl/PushcutUrlTest.php`
- Create: `hub-laravel/app/Http/Controllers/PushcutUrlController.php`
- Modify: `hub-laravel/routes/api.php`

- [ ] **Step 1: Create the test file**

```bash
php artisan make:test PushcutUrl/PushcutUrlTest --phpunit --no-interaction
```

Replace the file contents with:

```php
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
```

- [ ] **Step 2: Run tests — verify they all fail**

```bash
php artisan test --compact tests/Feature/PushcutUrl/PushcutUrlTest.php
```

Expected: all tests fail (route not found / 404).

- [ ] **Step 3: Create the controller**

`hub-laravel/app/Http/Controllers/PushcutUrlController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\UserPushcutUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushcutUrlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $urls = $request->user()->pushcutUrls()->orderBy('created_at')->get();

        return response()->json(['data' => $urls]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url'    => ['required', 'url', 'max:500'],
            'notify' => ['required', 'in:all,created,paid'],
            'label'  => ['nullable', 'string', 'max:100'],
        ]);

        $url = $request->user()->pushcutUrls()->create($data);

        return response()->json(['data' => $url], 201);
    }

    public function update(Request $request, UserPushcutUrl $pushcutUrl): JsonResponse
    {
        if ($pushcutUrl->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'url'    => ['sometimes', 'url', 'max:500'],
            'notify' => ['sometimes', 'in:all,created,paid'],
            'label'  => ['nullable', 'string', 'max:100'],
        ]);

        $pushcutUrl->update($data);

        return response()->json(['data' => $pushcutUrl->fresh()]);
    }

    public function destroy(Request $request, UserPushcutUrl $pushcutUrl): JsonResponse
    {
        if ($pushcutUrl->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $pushcutUrl->delete();

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Register the route**

In `hub-laravel/routes/api.php`, add the import at the top:

```php
use App\Http\Controllers\PushcutUrlController;
```

Inside the `auth:sanctum` middleware group (after the `/auth/me` line), add:

```php
Route::apiResource('pushcut-urls', PushcutUrlController::class)->except(['show']);
```

Also remove the `auth/update` route (it will be deleted from `AuthController` in Task 6):

```php
// Remove this line:
Route::post('/auth/update', [AuthController::class, 'update']);
```

- [ ] **Step 5: Run tests — all should pass**

```bash
php artisan test --compact tests/Feature/PushcutUrl/PushcutUrlTest.php
```

Expected: all 8 tests pass.

- [ ] **Step 6: Run pint**

```bash
vendor/bin/pint app/Http/Controllers/PushcutUrlController.php routes/api.php --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/PushcutUrlController.php routes/api.php tests/Feature/PushcutUrl/PushcutUrlTest.php
git commit -m "feat: add PushcutUrlController with CRUD endpoints"
```

---

## Task 4: Update notification logic — PaymentController and WebhookController

**Files:**
- Modify: `hub-laravel/app/Http/Controllers/PaymentController.php`
- Modify: `hub-laravel/app/Http/Controllers/WebhookController.php`

Both controllers currently read `$user->pushcut_url` and `$user->pushcut_notify` directly. Replace with iteration over the `pushcutUrls` relation.

- [ ] **Step 1: Update PaymentController**

In `hub-laravel/app/Http/Controllers/PaymentController.php`, replace lines 82–88:

```php
// Remove:
if ($user->pushcut_url && in_array($user->pushcut_notify, ['all', 'created'])) {
    $this->pushcut->send($user->pushcut_url, 'New Payment', [
        'amount' => $transaction->amount,
        'method' => $transaction->method,
        'status' => 'PENDING',
    ]);
}

// Replace with:
$user->pushcutUrls
    ->filter(fn ($dest) => in_array($dest->notify, ['all', 'created']))
    ->each(fn ($dest) => $this->pushcut->send($dest->url, 'New Payment', [
        'amount' => $transaction->amount,
        'method' => $transaction->method,
        'status' => 'PENDING',
    ]));
```

- [ ] **Step 2: Update WebhookController**

In `hub-laravel/app/Http/Controllers/WebhookController.php`, replace lines 50–56:

```php
// Remove:
if ($user->pushcut_url && in_array($user->pushcut_notify, ['all', 'paid'])) {
    $this->pushcut->send($user->pushcut_url, 'Payment Completed', [
        'amount' => $transaction->amount,
        'method' => $transaction->method,
        'status' => 'COMPLETED',
    ]);
}

// Replace with:
$user->pushcutUrls
    ->filter(fn ($dest) => in_array($dest->notify, ['all', 'paid']))
    ->each(fn ($dest) => $this->pushcut->send($dest->url, 'Payment Completed', [
        'amount' => $transaction->amount,
        'method' => $transaction->method,
        'status' => 'COMPLETED',
    ]));
```

- [ ] **Step 3: Run pint**

```bash
vendor/bin/pint app/Http/Controllers/PaymentController.php app/Http/Controllers/WebhookController.php --format agent
```

- [ ] **Step 4: Run the full test suite to check for regressions**

```bash
php artisan test --compact
```

Expected: no new failures (existing tests don't cover pushcut in these two controllers directly).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PaymentController.php app/Http/Controllers/WebhookController.php
git commit -m "feat: iterate pushcutUrls relation in PaymentController and WebhookController"
```

---

## Task 5: Fix CartpandaWebhookController — STATUS_MAP + notification

**Files:**
- Modify: `hub-laravel/app/Http/Controllers/CartpandaWebhookController.php`
- Modify: `hub-laravel/database/factories/CartpandaOrderFactory.php`
- Modify: `hub-laravel/tests/Feature/Cartpanda/CartpandaWebhookTest.php`

- [ ] **Step 1: Update CartpandaOrderFactory `pending()` state**

In `hub-laravel/database/factories/CartpandaOrderFactory.php`, update the `pending()` state to use the correct real-world event:

```php
public function pending(): static
{
    return $this->state(fn (array $attributes) => [
        'status' => 'PENDING',
        'event'  => 'order.created',
    ]);
}
```

- [ ] **Step 2: Update CartpandaWebhookTest**

In `hub-laravel/tests/Feature/Cartpanda/CartpandaWebhookTest.php`, make these changes:

**a) Rename `test_order_pending_creates_pending_record` and use `order.created`:**

```php
// Remove:
public function test_order_pending_creates_pending_record(): void
{
    $user = User::factory()->withCartpandaParam('afiliado1')->create();
    $payload = $this->makePayload('order.pending', 'afiliado1', 90002, 10.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

    $this->assertDatabaseHas('cartpanda_orders', [
        'cartpanda_order_id' => '90002',
        'status' => 'PENDING',
    ]);
}

// Add:
public function test_order_created_creates_pending_record(): void
{
    $user = User::factory()->withCartpandaParam('afiliado1')->create();
    $payload = $this->makePayload('order.created', 'afiliado1', 90002, 10.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();

    $this->assertDatabaseHas('cartpanda_orders', [
        'cartpanda_order_id' => '90002',
        'status' => 'PENDING',
    ]);
}

public function test_order_pending_is_ignored(): void
{
    User::factory()->withCartpandaParam('afiliado1')->create();
    $payload = $this->makePayload('order.pending', 'afiliado1', 90002, 10.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk()->assertJson(['ok' => true]);

    $this->assertDatabaseMissing('cartpanda_orders', ['cartpanda_order_id' => '90002']);
}
```

**b) Replace pushcut tests — use `UserPushcutUrl::factory()` instead of user attributes:**

Add `use App\Models\UserPushcutUrl;` to the imports at the top of the test file.

```php
// Remove:
public function test_pushcut_fires_on_completed_when_notify_all(): void
{
    $user = User::factory()->withCartpandaParam('afiliado1')->create([
        'pushcut_url' => 'https://pushcut.example.com/hook',
        'pushcut_notify' => 'all',
    ]);

    $this->mock(PushcutService::class, function (MockInterface $mock) {
        $mock->shouldReceive('send')->once();
    });

    $payload = $this->makePayload('order.paid', 'afiliado1', 90012, 30.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
}

// Add:
public function test_pushcut_fires_on_completed_when_notify_all(): void
{
    $user = User::factory()->withCartpandaParam('afiliado1')->create();
    UserPushcutUrl::factory()->for($user)->notifyAll()->create([
        'url' => 'https://pushcut.example.com/hook',
    ]);

    $this->mock(PushcutService::class, function (MockInterface $mock) {
        $mock->shouldReceive('send')->once();
    });

    $payload = $this->makePayload('order.paid', 'afiliado1', 90012, 30.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
}
```

```php
// Remove:
public function test_pushcut_does_not_fire_on_pending_when_notify_paid(): void
{
    $user = User::factory()->withCartpandaParam('afiliado1')->create([
        'pushcut_url' => 'https://pushcut.example.com/hook',
        'pushcut_notify' => 'paid',
    ]);

    $this->mock(PushcutService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('send');
    });

    $payload = $this->makePayload('order.pending', 'afiliado1', 90013, 10.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
}

// Add:
public function test_pushcut_does_not_fire_on_created_when_notify_paid(): void
{
    $user = User::factory()->withCartpandaParam('afiliado1')->create();
    UserPushcutUrl::factory()->for($user)->notifyPaid()->create([
        'url' => 'https://pushcut.example.com/hook',
    ]);

    $this->mock(PushcutService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('send');
    });

    $payload = $this->makePayload('order.created', 'afiliado1', 90013, 10.00);

    $this->postJson('/api/cartpanda-webhook', $payload)->assertOk();
}
```

- [ ] **Step 3: Run the webhook tests — verify which ones fail**

```bash
php artisan test --compact tests/Feature/Cartpanda/CartpandaWebhookTest.php
```

Expected: `test_order_created_creates_pending_record`, `test_order_pending_is_ignored`, `test_pushcut_fires_on_completed_when_notify_all`, `test_pushcut_does_not_fire_on_created_when_notify_paid` fail. Others pass.

- [ ] **Step 4: Update CartpandaWebhookController**

In `hub-laravel/app/Http/Controllers/CartpandaWebhookController.php`:

**a) Fix `STATUS_MAP`** — replace `'order.pending'` with `'order.created'`:

```php
private const STATUS_MAP = [
    'order.paid'       => 'COMPLETED',
    'order.created'    => 'PENDING',
    'order.cancelled'  => 'FAILED',
    'order.chargeback' => 'DECLINED',
    'order.refunded'   => 'REFUNDED',
];
```

**b) Replace `maybeNotify` method** — replace the entire method:

```php
private function maybeNotify(User $user, CartpandaOrder $order, string $status): void
{
    $user->pushcutUrls
        ->filter(fn ($dest) => match ($status) {
            'COMPLETED' => in_array($dest->notify, ['all', 'paid'], true),
            'PENDING'   => in_array($dest->notify, ['all', 'created'], true),
            default     => false,
        })
        ->each(fn ($dest) => $this->pushcut->send($dest->url, "Cartpanda Order {$status}", [
            'amount'   => $order->amount,
            'order_id' => $order->cartpanda_order_id,
            'status'   => $status,
        ]));
}
```

- [ ] **Step 5: Run webhook tests — all should pass**

```bash
php artisan test --compact tests/Feature/Cartpanda/CartpandaWebhookTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Run pint**

```bash
vendor/bin/pint app/Http/Controllers/CartpandaWebhookController.php database/factories/CartpandaOrderFactory.php --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/CartpandaWebhookController.php \
        database/factories/CartpandaOrderFactory.php \
        tests/Feature/Cartpanda/CartpandaWebhookTest.php
git commit -m "feat: fix Cartpanda STATUS_MAP and iterate pushcutUrls for notifications"
```

---

## Task 6: Clean up AdminUserController + AuthController

**Files:**
- Modify: `hub-laravel/app/Http/Controllers/AdminUserController.php`
- Modify: `hub-laravel/app/Http/Controllers/AuthController.php`
- Modify: `hub-laravel/tests/Feature/Auth/AuthTest.php`

- [ ] **Step 1: Update AdminUserController `index()` response**

In `hub-laravel/app/Http/Controllers/AdminUserController.php`, remove `pushcut_url` and `pushcut_notify` from the `index()` response map (lines 31–32). The map should now be:

```php
return response()->json([
    'data' => $users->map(fn (User $u) => [
        'id'               => $u->id,
        'email'            => $u->email,
        'payer_email'      => $u->payer_email,
        'payer_name'       => $u->payer_name,
        'success_url'      => $u->success_url,
        'failed_url'       => $u->failed_url,
        'cartpanda_param'  => $u->cartpanda_param,
        'role'             => $u->role,
        'active'           => $u->active,
        'created_at'       => $u->created_at,
        'balance_pending'  => $u->balance?->balance_pending ?? '0.000000',
        'balance_released' => $u->balance?->balance_released ?? '0.000000',
        'shops' => $u->shops->map(fn ($s) => [
            'id'        => $s->id,
            'shop_slug' => $s->shop_slug,
            'name'      => $s->name,
        ]),
    ]),
    'meta' => [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int) ceil($total / $perPage),
    ],
]);
```

- [ ] **Step 2: Update AdminUserController `store()` and `update()`**

In `store()`, remove the pushcut validation rules and the pushcut fields from `User::create()`:

```php
// Validation — remove these two lines:
'pushcut_url'    => ['nullable', 'url'],
'pushcut_notify' => ['nullable', 'in:all,created,paid'],

// User::create payload — remove these two lines:
'pushcut_url'    => $data['pushcut_url'] ?? '',
'pushcut_notify' => $data['pushcut_notify'] ?? 'all',
```

In `update()`, remove the pushcut validation rules:

```php
// Validation — remove these two lines:
'pushcut_url'    => ['nullable', 'url'],
'pushcut_notify' => ['sometimes', 'in:all,created,paid'],
```

- [ ] **Step 3: Update AuthController**

In `hub-laravel/app/Http/Controllers/AuthController.php`:

**a) `register()` — remove pushcut validation and payload fields:**

```php
// Validation — remove:
'pushcut_url' => ['nullable', 'url'],

// User::create payload — remove:
'pushcut_url' => $data['pushcut_url'] ?? '',
```

**b) Delete the entire `update()` method** (lines 78–88 approximately):

```php
// Remove entirely:
public function update(Request $request): JsonResponse
{
    $data = $request->validate([
        'pushcut_url' => ['nullable', 'string'],
        'pushcut_notify' => ['required', 'in:all,created,paid'],
    ]);

    $user = $request->user();
    $user->update($data);

    return response()->json(['user' => $this->formatUser($user->fresh())]);
}
```

**c) Update `formatUser()` — remove pushcut fields from return array and PHPDoc:**

```php
/**
 * @return array{id: int, email: string, payer_email: string, payer_name: string, cartpanda_param: string|null, role: string}
 */
private function formatUser(User $user): array
{
    return [
        'id'             => $user->id,
        'email'          => $user->email,
        'payer_email'    => $user->payer_email,
        'payer_name'     => $user->payer_name,
        'cartpanda_param' => $user->cartpanda_param,
        'role'           => $user->role,
    ];
}
```

- [ ] **Step 4: Delete the pushcut settings test**

In `hub-laravel/tests/Feature/Auth/AuthTest.php`, remove the entire `test_update_settings_saves_pushcut_config` method (lines 101–113).

- [ ] **Step 5: Run the auth tests**

```bash
php artisan test --compact tests/Feature/Auth/AuthTest.php
```

Expected: all remaining tests pass.

- [ ] **Step 6: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 7: Run pint**

```bash
vendor/bin/pint app/Http/Controllers/AdminUserController.php app/Http/Controllers/AuthController.php --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/AdminUserController.php \
        app/Http/Controllers/AuthController.php \
        tests/Feature/Auth/AuthTest.php
git commit -m "feat: remove pushcut_url/pushcut_notify from admin and auth controllers"
```

---

## Task 7: Frontend — update api/client.ts

**Files:**
- Modify: `dashboard/src/api/client.ts`

- [ ] **Step 1: Remove old pushcut types**

In `dashboard/src/api/client.ts`:

Remove `pushcut_url` and `pushcut_notify` from `LoginResponse.user` (lines 28–29):

```ts
export interface LoginResponse {
  token: string;
  user: {
    id: number;
    email: string;
    payer_email: string;
    role?: string;
    cartpanda_param?: string | null;
  };
}
```

Remove `pushcut_url` and `pushcut_notify` from `CreateUserPayload` (lines 98–99):

```ts
export interface CreateUserPayload {
  email: string;
  password: string;
  payer_email?: string;
  payer_name?: string;
  role: string;
  cartpanda_param?: string | null;
  success_url?: string;
  failed_url?: string;
}
```

(`UpdateUserPayload` is derived via `Partial<Omit<CreateUserPayload, 'password'> & { active: boolean }>` so it updates automatically.)

- [ ] **Step 2: Add new UserPushcutUrl types**

Add after the `UpdateUserPayload` line:

```ts
export interface UserPushcutUrl {
  id: number;
  url: string;
  notify: 'all' | 'created' | 'paid';
  label: string | null;
  created_at: string;
}
export interface UserPushcutUrlsResponse { data: UserPushcutUrl[]; }
export interface CreatePushcutUrlPayload {
  url: string;
  notify: 'all' | 'created' | 'paid';
  label?: string;
}
export type UpdatePushcutUrlPayload = Partial<CreatePushcutUrlPayload>;
```

- [ ] **Step 3: Replace `updateSettings` with pushcut CRUD methods**

Remove the `updateSettings` method (lines 339–346):

```ts
// Remove:
updateSettings: (data: {
  pushcut_url: string;
  pushcut_notify: "all" | "created" | "paid";
}) =>
  request<{ user: LoginResponse["user"] }>("/api/auth/update", {
    method: "POST",
    body: JSON.stringify(data),
  }),
```

Add the new pushcut CRUD methods in the same location:

```ts
// Pushcut URLs
pushcutUrls: () =>
  request<UserPushcutUrlsResponse>('/api/pushcut-urls'),

createPushcutUrl: (data: CreatePushcutUrlPayload) =>
  request<{ data: UserPushcutUrl }>('/api/pushcut-urls', {
    method: 'POST',
    body: JSON.stringify(data),
  }),

updatePushcutUrl: (id: number, data: UpdatePushcutUrlPayload) =>
  request<{ data: UserPushcutUrl }>(`/api/pushcut-urls/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  }),

deletePushcutUrl: (id: number) =>
  request<{ ok: boolean }>(`/api/pushcut-urls/${id}`, {
    method: 'DELETE',
  }),
```

- [ ] **Step 4: Build and verify no TypeScript errors**

```bash
cd dashboard
npm run build
```

Expected: build succeeds with no TypeScript errors. If `updateSettings` was referenced elsewhere, the compiler will flag it — fix any such references (Settings.tsx is handled next).

- [ ] **Step 5: Commit**

```bash
cd dashboard
git add src/api/client.ts
git commit -m "feat: add UserPushcutUrl CRUD types and api methods"
```

---

## Task 8: Frontend — replace Settings.tsx notifications tab

**Files:**
- Modify: `dashboard/src/pages/Settings.tsx`

The current `notifications` tab is a single form with `pushcutUrl` + `pushcutNotify` state. Replace it entirely with a two-card layout: an "add URL" form on the left and a URL list card on the right.

- [ ] **Step 1: Replace the Settings.tsx file content**

`dashboard/src/pages/Settings.tsx`:

```tsx
// src/pages/Settings.tsx
import { useState, useEffect, FormEvent } from 'react';
import { api, UserPushcutUrl } from '../api/client';
import { useAuth } from '../hooks/useAuth';
import Tabs, { Tab } from '../components/Tabs';
import UserManagement from '../components/UserManagement';
import AaPanelConfigManager from '../components/admin/AaPanelConfigManager';
import UserLinkManager from '../components/admin/UserLinkManager';

const TABS: Tab[] = [
  { key: 'notifications', label: 'Notificações' },
  { key: 'users', label: 'Usuários', adminOnly: true },
  { key: 'aapanel', label: 'Servidores aaPanel', adminOnly: true },
  { key: 'links-admin', label: 'Links', adminOnly: true },
];

const NOTIFY_OPTIONS: { value: 'all' | 'created' | 'paid'; label: string; description: string }[] = [
  { value: 'all',     label: 'Ambas',   description: 'Gerado e pago'    },
  { value: 'created', label: 'Gerado',  description: 'Só ao criar'      },
  { value: 'paid',    label: 'Pago',    description: 'Só ao confirmar'  },
];

const inputClass =
  'w-full bg-surface-2 border border-white/[0.08] rounded-xl px-4 py-3 text-sm text-white placeholder:text-white/20 outline-none focus:border-brand/50 focus:ring-1 focus:ring-brand/30 transition-colors';

export default function Settings() {
  const { user } = useAuth();
  const isAdmin = user?.role === 'admin';
  const [activeTab, setActiveTab] = useState('notifications');

  // --- list state ---
  const [urls, setUrls]         = useState<UserPushcutUrl[]>([]);
  const [listLoading, setListLoading] = useState(true);

  // --- add form state ---
  const [newUrl, setNewUrl]       = useState('');
  const [newLabel, setNewLabel]   = useState('');
  const [newNotify, setNewNotify] = useState<'all' | 'created' | 'paid'>('all');
  const [adding, setAdding]       = useState(false);
  const [addError, setAddError]   = useState<string | null>(null);

  useEffect(() => {
    document.title = 'Configurações';
  }, []);

  useEffect(() => {
    api.pushcutUrls()
      .then(({ data }) => setUrls(data))
      .catch(() => {/* silent — list stays empty */})
      .finally(() => setListLoading(false));
  }, []);

  async function handleAdd(e: FormEvent) {
    e.preventDefault();
    setAdding(true);
    setAddError(null);

    try {
      const { data } = await api.createPushcutUrl({
        url: newUrl,
        notify: newNotify,
        label: newLabel.trim() || undefined,
      });
      setUrls((prev) => [...prev, data]);
      setNewUrl('');
      setNewLabel('');
      setNewNotify('all');
    } catch (err) {
      setAddError(err instanceof Error ? err.message : 'Erro ao adicionar URL.');
    } finally {
      setAdding(false);
    }
  }

  async function handleDelete(id: number) {
    try {
      await api.deletePushcutUrl(id);
      setUrls((prev) => prev.filter((u) => u.id !== id));
    } catch {
      // silent
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-bold text-white">Configurações</h1>
        <p className="text-sm text-white/40 mt-0.5">Gerencie as suas preferências</p>
      </div>

      <Tabs tabs={TABS} active={activeTab} onChange={setActiveTab} isAdmin={isAdmin} />

      {activeTab === 'notifications' && (
        <div className="flex gap-4 flex-wrap">
          {/* Add URL card */}
          <div className="bg-surface-1 rounded-2xl border border-white/[0.06] p-6 w-full max-w-sm">
            <h2 className="font-semibold text-white mb-1">Pushcut</h2>
            <p className="text-sm text-white/40 mb-6">
              Adicione URLs para receber notificações de pagamentos criados ou confirmados.
            </p>

            <form onSubmit={handleAdd} className="flex flex-col gap-5">
              {addError && (
                <div className="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3 text-sm text-red-400">
                  {addError}
                </div>
              )}

              <div className="flex flex-col gap-1.5">
                <label className="text-xs font-semibold text-white/40 uppercase tracking-widest" htmlFor="pc-url">
                  URL do Pushcut
                </label>
                <input
                  id="pc-url"
                  type="url"
                  required
                  value={newUrl}
                  onChange={(e) => setNewUrl(e.target.value)}
                  placeholder="https://api.pushcut.io/SEU_TOKEN/notifications/NOME"
                  className={inputClass}
                />
              </div>

              <div className="flex flex-col gap-1.5">
                <label className="text-xs font-semibold text-white/40 uppercase tracking-widest" htmlFor="pc-label">
                  Label <span className="normal-case font-normal">(opcional)</span>
                </label>
                <input
                  id="pc-label"
                  type="text"
                  value={newLabel}
                  onChange={(e) => setNewLabel(e.target.value)}
                  placeholder="iPhone"
                  className={inputClass}
                />
              </div>

              <div className="flex flex-col gap-1.5">
                <p className="text-xs font-semibold text-white/40 uppercase tracking-widest">
                  Notificar quando
                </p>
                <div className="flex bg-surface-2 border border-white/[0.08] rounded-xl p-1 gap-1">
                  {NOTIFY_OPTIONS.map((opt) => (
                    <button
                      key={opt.value}
                      type="button"
                      onClick={() => setNewNotify(opt.value)}
                      className={`flex-1 flex flex-col items-center py-2.5 px-3 rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-0 ${
                        newNotify === opt.value
                          ? 'bg-surface-1 text-white shadow-sm border border-white/[0.08]'
                          : 'text-white/40 hover:text-white/70'
                      }`}
                    >
                      <span>{opt.label}</span>
                      <span className="text-[11px] text-white/30 mt-0.5">{opt.description}</span>
                    </button>
                  ))}
                </div>
              </div>

              <button
                type="submit"
                disabled={adding}
                className="px-5 py-2.5 bg-brand hover:bg-brand-hover disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-surface-1"
              >
                {adding ? 'Adicionando...' : 'Adicionar'}
              </button>
            </form>
          </div>

          {/* URL list card */}
          <div className="bg-surface-1 rounded-2xl border border-white/[0.06] p-6 flex-1 min-w-[260px]">
            <h2 className="font-semibold text-white mb-1">URLs cadastradas</h2>
            <p className="text-sm text-white/40 mb-6">
              Cada URL recebe notificações de acordo com a sua preferência.
            </p>

            {listLoading ? (
              <div className="text-sm text-white/20">Carregando...</div>
            ) : urls.length === 0 ? (
              <div className="text-sm text-white/20">Nenhuma URL cadastrada.</div>
            ) : (
              <div className="flex flex-col gap-2">
                {urls.map((dest) => (
                  <div
                    key={dest.id}
                    className="flex items-center gap-3 bg-surface-2 border border-white/[0.06] rounded-xl px-4 py-3"
                  >
                    <div className="flex-1 min-w-0">
                      {dest.label && (
                        <p className="text-sm font-medium text-white truncate">{dest.label}</p>
                      )}
                      <p className={`text-xs truncate ${dest.label ? 'text-white/40' : 'text-white/70'}`}>
                        {dest.url}
                      </p>
                    </div>
                    <span className="shrink-0 bg-surface-1 border border-white/[0.08] text-white/50 text-[11px] rounded px-1.5 py-0.5">
                      {dest.notify}
                    </span>
                    <button
                      type="button"
                      onClick={() => handleDelete(dest.id)}
                      aria-label={`Remover ${dest.label ?? dest.url}`}
                      className="shrink-0 text-white/30 hover:text-white/70 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/20 rounded"
                    >
                      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                      </svg>
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {activeTab === 'users' && <UserManagement />}
      {activeTab === 'aapanel' && <AaPanelConfigManager />}
      {activeTab === 'links-admin' && <UserLinkManager />}
    </div>
  );
}
```

- [ ] **Step 2: Build to verify no TypeScript errors**

```bash
cd dashboard
npm run build
```

Expected: build succeeds with no errors.

- [ ] **Step 3: Commit**

```bash
git add src/pages/Settings.tsx
git commit -m "feat: replace Settings notifications tab with multi-URL pushcut manager"
```

---

## Task 9: Final verification

- [ ] **Step 1: Run the full backend test suite**

```bash
cd hub-laravel
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 2: Run pint on all modified PHP files**

```bash
vendor/bin/pint --dirty --format agent
```

Fix any reported issues and amend or commit as needed.

- [ ] **Step 3: Final commit if pint made changes**

```bash
git add -p
git commit -m "style: apply pint formatting"
```
