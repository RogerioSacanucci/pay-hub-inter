# Admin CRUD /api/admin/milestones Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create `AdminMilestoneController` with full CRUD for managing revenue milestones.

**Architecture:** Simple REST controller with inline validation, route model binding, and cascade delete via existing FK. Follows the same pattern as `AdminUserLinkController` — the closest existing CRUD analog.

**Tech Stack:** Laravel 13, PHPUnit 12, Sanctum v4, PHP 8.4

**Linear:** FRA-124

---

## File Structure

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `app/Http/Controllers/AdminMilestoneController.php` | CRUD: index, store, update, destroy |
| Modify | `routes/api.php` | Register 4 admin milestone routes |
| Create | `tests/Feature/AdminMilestoneControllerTest.php` | Feature tests for all endpoints + auth |

---

### Task 1: Write feature tests

**Files:**
- Create: `tests/Feature/AdminMilestoneControllerTest.php`

- [ ] **Step 1: Create test file via artisan**

Run:
```bash
cd hub-laravel && php artisan make:test AdminMilestoneControllerTest --phpunit --no-interaction
```

- [ ] **Step 2: Write all tests**

Replace the generated file content with:

```php
<?php

namespace Tests\Feature;

use App\Models\RevenueMilestone;
use App\Models\User;
use App\Models\UserMilestoneAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMilestoneControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->adminToken = $admin->createToken('auth')->plainTextToken;
    }

    public function test_admin_lists_milestones_ordered_by_order(): void
    {
        RevenueMilestone::factory()->create(['value' => 5000, 'order' => 2]);
        RevenueMilestone::factory()->create(['value' => 1000, 'order' => 1]);
        RevenueMilestone::factory()->create(['value' => 10000, 'order' => 3]);

        $response = $this->withToken($this->adminToken)
            ->getJson('/api/admin/milestones');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.order', 1)
            ->assertJsonPath('data.1.order', 2)
            ->assertJsonPath('data.2.order', 3);
    }

    public function test_admin_creates_milestone(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', [
                'value' => 5000.00,
                'order' => 1,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('milestone.value', '5000.00')
            ->assertJsonPath('milestone.order', 1);

        $this->assertDatabaseHas('revenue_milestones', ['value' => 5000.00, 'order' => 1]);
    }

    public function test_admin_creates_milestone_without_order_defaults_to_next(): void
    {
        RevenueMilestone::factory()->create(['order' => 3]);

        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', [
                'value' => 7500.00,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('milestone.order', 4);
    }

    public function test_admin_updates_milestone(): void
    {
        $milestone = RevenueMilestone::factory()->create(['value' => 1000, 'order' => 1]);

        $response = $this->withToken($this->adminToken)
            ->putJson("/api/admin/milestones/{$milestone->id}", [
                'value' => 2000.00,
                'order' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('milestone.value', '2000.00')
            ->assertJsonPath('milestone.order', 5);
    }

    public function test_admin_deletes_milestone(): void
    {
        $milestone = RevenueMilestone::factory()->create();

        $response = $this->withToken($this->adminToken)
            ->deleteJson("/api/admin/milestones/{$milestone->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Milestone deleted');

        $this->assertDatabaseMissing('revenue_milestones', ['id' => $milestone->id]);
    }

    public function test_delete_cascades_to_achievements(): void
    {
        $milestone = RevenueMilestone::factory()->create();
        $user = User::factory()->create();
        UserMilestoneAchievement::factory()->create([
            'user_id' => $user->id,
            'milestone_id' => $milestone->id,
        ]);

        $this->withToken($this->adminToken)
            ->deleteJson("/api/admin/milestones/{$milestone->id}")
            ->assertOk();

        $this->assertDatabaseMissing('user_milestone_achievements', ['milestone_id' => $milestone->id]);
    }

    public function test_regular_user_receives_403(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/milestones')->assertForbidden();
        $this->withToken($token)->postJson('/api/admin/milestones', ['value' => 1000])->assertForbidden();
    }

    public function test_unauthenticated_receives_401(): void
    {
        $this->getJson('/api/admin/milestones')->assertUnauthorized();
    }

    public function test_create_requires_value(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_value_must_be_numeric(): void
    {
        $response = $this->withToken($this->adminToken)
            ->postJson('/api/admin/milestones', ['value' => 'not-a-number']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run:
```bash
cd hub-laravel && php artisan test --compact tests/Feature/AdminMilestoneControllerTest.php
```

Expected: All tests FAIL (routes and controller don't exist yet).

- [ ] **Step 4: Commit failing tests**

```bash
cd hub-laravel && git add tests/Feature/AdminMilestoneControllerTest.php && git commit -m "$(cat <<'EOF'
test: add AdminMilestoneController feature tests (FRA-124)
EOF
)"
```

---

### Task 2: Create AdminMilestoneController

**Files:**
- Create: `app/Http/Controllers/AdminMilestoneController.php`

- [ ] **Step 1: Create controller via artisan**

Run:
```bash
cd hub-laravel && php artisan make:controller AdminMilestoneController --no-interaction
```

- [ ] **Step 2: Implement all CRUD actions**

Replace the generated file content with:

```php
<?php

namespace App\Http\Controllers;

use App\Models\RevenueMilestone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMilestoneController extends Controller
{
    public function index(): JsonResponse
    {
        $milestones = RevenueMilestone::orderBy('order')->get();

        return response()->json([
            'data' => $milestones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'value' => ['required', 'numeric', 'min:0'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (! isset($data['order'])) {
            $data['order'] = (RevenueMilestone::max('order') ?? 0) + 1;
        }

        $milestone = RevenueMilestone::create($data);

        return response()->json(['milestone' => $milestone], 201);
    }

    public function update(Request $request, RevenueMilestone $milestone): JsonResponse
    {
        $data = $request->validate([
            'value' => ['sometimes', 'numeric', 'min:0'],
            'order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $milestone->update($data);

        return response()->json(['milestone' => $milestone]);
    }

    public function destroy(RevenueMilestone $milestone): JsonResponse
    {
        $milestone->delete();

        return response()->json(['message' => 'Milestone deleted']);
    }
}
```

- [ ] **Step 3: Commit controller**

```bash
cd hub-laravel && git add app/Http/Controllers/AdminMilestoneController.php && git commit -m "$(cat <<'EOF'
feat: add AdminMilestoneController with CRUD actions (FRA-124)
EOF
)"
```

---

### Task 3: Register routes

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 1: Add import and routes**

At the top of `routes/api.php`, add the import:

```php
use App\Http\Controllers\AdminMilestoneController;
```

Inside the `AdminMiddleware` group, add these 4 routes:

```php
Route::get('admin/milestones', [AdminMilestoneController::class, 'index']);
Route::post('admin/milestones', [AdminMilestoneController::class, 'store']);
Route::put('admin/milestones/{milestone}', [AdminMilestoneController::class, 'update']);
Route::delete('admin/milestones/{milestone}', [AdminMilestoneController::class, 'destroy']);
```

Note: `{milestone}` uses implicit route model binding. Laravel resolves this to `RevenueMilestone` because the controller type-hints `RevenueMilestone $milestone`. However, since the model name doesn't match the parameter name, you need to add explicit binding in the route or use `Route::model()`. The simplest approach: use `Route::bind` or pass the model class in the route definition.

Actually, Laravel's implicit binding matches the **parameter name** to the **variable name** in the controller method. Since the parameter is `{milestone}` and the controller type-hints `RevenueMilestone $milestone`, Laravel will try to resolve `RevenueMilestone` by its primary key — this works correctly because the variable name matches the route parameter name.

- [ ] **Step 2: Run Pint to format**

Run:
```bash
cd hub-laravel && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Run all tests to verify they pass**

Run:
```bash
cd hub-laravel && php artisan test --compact tests/Feature/AdminMilestoneControllerTest.php
```

Expected: All 10 tests PASS.

- [ ] **Step 4: Commit routes**

```bash
cd hub-laravel && git add routes/api.php && git commit -m "$(cat <<'EOF'
feat: register admin milestone routes (FRA-124)
EOF
)"
```

---

### Task 4: Final verification

- [ ] **Step 1: Run the full test suite**

Run:
```bash
cd hub-laravel && php artisan test --compact
```

Expected: All tests pass, no regressions.

- [ ] **Step 2: Run Pint on all modified files**

Run:
```bash
cd hub-laravel && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Commit any formatting fixes (if needed)**

```bash
cd hub-laravel && git add -A && git commit -m "style: apply pint formatting"
```

Only run if Pint made changes.
