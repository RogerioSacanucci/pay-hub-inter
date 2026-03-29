# Cartpanda Stats Balance Totals Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `GET /api/cartpanda-stats` to include `balance_pending` and `balance_released` fields in the overview response.

**Architecture:** Add balance lookup logic to `CartpandaStatsController::index()` using the existing `UserBalance` model. Admin without `user_id` sees global sums; admin with `user_id` sees that user's balance; regular user sees own balance. New test file covers all scenarios.

**Tech Stack:** Laravel 13, PHPUnit 12, UserBalance model, Sanctum auth

---

## File Structure

- **Modify:** `app/Http/Controllers/CartpandaStatsController.php` — add balance query logic and response fields
- **Create:** `tests/Feature/Balance/CartpandaStatsBalanceTest.php` — test all balance scenarios in stats endpoint

---

### Task 1: Write the failing tests

**Files:**
- Create: `tests/Feature/Balance/CartpandaStatsBalanceTest.php`

- [ ] **Step 1: Create test file**

```bash
cd hub-laravel && php artisan make:test --phpunit --no-interaction Feature/Balance/CartpandaStatsBalanceTest
```

- [ ] **Step 2: Write all test cases**

Replace the generated file content with:

```php
<?php

namespace Tests\Feature\Balance;

use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartpandaStatsBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_global_balance_sums(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        UserBalance::factory()->create(['balance_pending' => '100.000000', 'balance_released' => '50.000000']);
        UserBalance::factory()->create(['balance_pending' => '200.000000', 'balance_released' => '75.000000']);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonPath('overview.balance_pending', '300.000000')
            ->assertJsonPath('overview.balance_released', '125.000000');
    }

    public function test_admin_with_user_id_sees_that_users_balance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => '150.000000',
            'balance_released' => '80.000000',
        ]);
        UserBalance::factory()->create(['balance_pending' => '999.000000', 'balance_released' => '999.000000']);
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/cartpanda-stats?period=30d&user_id={$user->id}");

        $response->assertOk()
            ->assertJsonPath('overview.balance_pending', '150.000000')
            ->assertJsonPath('overview.balance_released', '80.000000');
    }

    public function test_regular_user_sees_own_balance(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->create([
            'user_id' => $user->id,
            'balance_pending' => '250.000000',
            'balance_released' => '100.000000',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonPath('overview.balance_pending', '250.000000')
            ->assertJsonPath('overview.balance_released', '100.000000');
    }

    public function test_user_without_balance_returns_zeros(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonPath('overview.balance_pending', '0.000000')
            ->assertJsonPath('overview.balance_released', '0.000000');
    }

    public function test_overview_structure_includes_balance_fields(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/cartpanda-stats?period=30d');

        $response->assertOk()
            ->assertJsonStructure([
                'overview' => [
                    'total_orders',
                    'completed',
                    'pending',
                    'failed',
                    'declined',
                    'refunded',
                    'total_volume',
                    'balance_pending',
                    'balance_released',
                ],
            ]);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
cd hub-laravel && php artisan test --compact tests/Feature/Balance/CartpandaStatsBalanceTest.php
```

Expected: All 5 tests FAIL because `balance_pending` and `balance_released` are not in the response yet.

- [ ] **Step 4: Commit failing tests**

```bash
cd hub-laravel && git add tests/Feature/Balance/CartpandaStatsBalanceTest.php && git commit -m "test: add failing tests for balance fields in cartpanda-stats"
```

---

### Task 2: Implement balance logic in CartpandaStatsController

**Files:**
- Modify: `app/Http/Controllers/CartpandaStatsController.php:1-70`

- [ ] **Step 1: Add UserBalance import**

Add to the imports at the top of `CartpandaStatsController.php` (after the existing `use` statements):

```php
use App\Models\UserBalance;
```

- [ ] **Step 2: Add balance query logic before the return statement**

Insert the following block at line 52 (just before the `return response()->json(...)` block):

```php
        if ($user->isAdmin() && ! $request->has('user_id')) {
            $balancePending  = UserBalance::sum('balance_pending');
            $balanceReleased = UserBalance::sum('balance_released');
        } elseif ($user->isAdmin() && $request->has('user_id')) {
            $targetBalance   = UserBalance::where('user_id', (int) $request->query('user_id'))->first();
            $balancePending  = $targetBalance?->balance_pending ?? '0.000000';
            $balanceReleased = $targetBalance?->balance_released ?? '0.000000';
        } else {
            $userBalance     = $user->balance;
            $balancePending  = $userBalance?->balance_pending ?? '0.000000';
            $balanceReleased = $userBalance?->balance_released ?? '0.000000';
        }
```

- [ ] **Step 3: Add balance fields to the overview response**

In the `return response()->json(...)` block, add two new keys to the `'overview'` array, after `'total_volume'`:

```php
                'balance_pending'  => (string) $balancePending,
                'balance_released' => (string) $balanceReleased,
```

- [ ] **Step 4: Run balance tests to verify they pass**

```bash
cd hub-laravel && php artisan test --compact tests/Feature/Balance/CartpandaStatsBalanceTest.php
```

Expected: All 5 tests PASS.

- [ ] **Step 5: Run existing CartpandaStats tests to verify no regression**

```bash
cd hub-laravel && php artisan test --compact tests/Feature/Stats/CartpandaStatsTest.php
```

Expected: All 8 existing tests PASS.

- [ ] **Step 6: Run Pint formatter**

```bash
cd hub-laravel && vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit implementation**

```bash
cd hub-laravel && git add app/Http/Controllers/CartpandaStatsController.php && git commit -m "feat: add balance fields to GET /cartpanda-stats endpoint (#FRA-65)"
```

---

### Task 3: Final verification

- [ ] **Step 1: Run full test suite**

```bash
cd hub-laravel && php artisan test --compact
```

Expected: All tests pass, no regressions.
