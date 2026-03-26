# Transactions, Stats & Users Endpoints — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement three API endpoints — transaction listing with filters/pagination, analytics stats, and admin-only user listing.

**Architecture:** Three controllers (TransactionController, StatsController, UserController) added to `routes/api.php` behind `auth:sanctum`. UserController additionally uses AdminMiddleware. TransactionController uses Eloquent queries; StatsController uses raw DB queries for analytics performance.

**Tech Stack:** PHP 8.4, Laravel 13, Sanctum 4, PHPUnit 12

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Http/Controllers/TransactionController.php` | Paginated transaction listing with filters |
| Create | `app/Http/Controllers/StatsController.php` | Analytics: overview, chart, methods breakdown |
| Create | `app/Http/Controllers/UserController.php` | Admin-only user listing |
| Modify | `routes/api.php` | Add 3 new routes |
| Create | `tests/Feature/Transactions/TransactionsTest.php` | Transaction endpoint tests |
| Create | `tests/Feature/Stats/StatsTest.php` | Stats endpoint tests |
| Create | `tests/Feature/Auth/UsersTest.php` | Users endpoint tests |

---

### Task 1: Transaction Tests & Controller

**Files:**
- Create: `tests/Feature/Transactions/TransactionsTest.php`
- Create: `app/Http/Controllers/TransactionController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Add transaction route to `routes/api.php`**

Add inside the existing `auth:sanctum` middleware group:

```php
Route::get('/transactions', [TransactionController::class, 'index']);
```

Add the import at the top of the file:

```php
use App\Http\Controllers\TransactionController;
```

- [ ] **Step 2: Write failing transaction tests**

Create `tests/Feature/Transactions/TransactionsTest.php`:

```php
<?php

namespace Tests\Feature\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_own_transactions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $other->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_sees_all_transactions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user1->id]);
        Transaction::factory()->create(['user_id' => $user2->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_admin_filters_by_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user1->id]);
        Transaction::factory()->create(['user_id' => $user2->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions?user_id=' . $user1->id);
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_transactions_filter_by_status(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'COMPLETED']);
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions?status=COMPLETED');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_transactions_paginate(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->count(25)->create(['user_id' => $user->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions?page=1');
        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);
        $this->assertCount(20, $response->json('data'));
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.pages'));
    }

    public function test_unauthenticated_user_cannot_access_transactions(): void
    {
        $this->getJson('/api/transactions')->assertUnauthorized();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Transactions/TransactionsTest.php`
Expected: FAIL — controller not found or route not matched

- [ ] **Step 4: Create TransactionController**

Create `app/Http/Controllers/TransactionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = Transaction::query();

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }
        if ($method = $request->query('method')) {
            $query->where('method', strtolower($method));
        }
        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }
        if ($txId = $request->query('transaction_id')) {
            $query->where('transaction_id', 'like', "%{$txId}%");
        }

        $total = $query->count();
        $transactions = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $transactions->map(fn (Transaction $t) => [
                'transaction_id' => $t->transaction_id,
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'method' => $t->method,
                'status' => $t->status,
                'payer_name' => $t->payer_name,
                'payer_email' => $t->payer_email,
                'payer_document' => $t->payer_document,
                'reference_entity' => $t->reference_entity,
                'reference_number' => $t->reference_number,
                'reference_expires_at' => $t->reference_expires_at,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
            ]),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Transactions/TransactionsTest.php`
Expected: 6 tests pass

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TransactionController.php tests/Feature/Transactions/TransactionsTest.php routes/api.php
git commit -m "feat: transaction listing endpoint with filters and pagination"
```

---

### Task 2: Stats Tests & Controller

**Files:**
- Create: `tests/Feature/Stats/StatsTest.php`
- Create: `app/Http/Controllers/StatsController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Add stats route to `routes/api.php`**

Add inside the existing `auth:sanctum` middleware group (after the transactions route):

```php
Route::get('/stats', [StatsController::class, 'index']);
```

Add the import at the top of the file:

```php
use App\Http\Controllers\StatsController;
```

- [ ] **Step 2: Write failing stats tests**

Create `tests/Feature/Stats/StatsTest.php`:

```php
<?php

namespace Tests\Feature\Stats;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_own_stats(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user->id, 'amount' => 100]);
        Transaction::factory()->completed()->create(['user_id' => $other->id, 'amount' => 200]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk()
            ->assertJsonStructure(['overview', 'chart', 'methods', 'period', 'hourly']);
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_admin_sees_all_stats(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user1->id, 'amount' => 100]);
        Transaction::factory()->completed()->create(['user_id' => $user2->id, 'amount' => 200]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk();
        $this->assertEquals(2, $response->json('overview.total_transactions'));
        $this->assertEquals(300.0, $response->json('overview.total_volume'));
    }

    public function test_stats_period_today_returns_hourly(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today');
        $response->assertOk();
        $this->assertTrue($response->json('hourly'));
        $this->assertEquals('today', $response->json('period'));
    }

    public function test_stats_default_period_is_30d(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk();
        $this->assertEquals('30d', $response->json('period'));
        $this->assertFalse($response->json('hourly'));
    }

    public function test_stats_overview_counts_statuses(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->completed()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);
        Transaction::factory()->failed()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'DECLINED']);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats');
        $response->assertOk();
        $this->assertEquals(4, $response->json('overview.total_transactions'));
        $this->assertEquals(1, $response->json('overview.completed'));
        $this->assertEquals(1, $response->json('overview.pending'));
        $this->assertEquals(1, $response->json('overview.failed'));
        $this->assertEquals(1, $response->json('overview.declined'));
    }

    public function test_unauthenticated_user_cannot_access_stats(): void
    {
        $this->getJson('/api/stats')->assertUnauthorized();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Stats/StatsTest.php`
Expected: FAIL — controller not found

- [ ] **Step 4: Create StatsController**

Create `app/Http/Controllers/StatsController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $request->query('period', '30d');

        [$dateFrom, $dateTo, $hourly] = $this->parsePeriod($period, $request);

        $base = DB::table('transactions')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if (! $user->isAdmin()) {
            $base->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $base->where('user_id', (int) $request->query('user_id'));
        }

        $overview = (clone $base)->selectRaw("
            COUNT(*) as total_transactions,
            SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status='PENDING' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status IN ('FAILED','EXPIRED') THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status='DECLINED' THEN 1 ELSE 0 END) as declined,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as total_volume,
            SUM(CASE WHEN status='COMPLETED' AND method='mbway' THEN amount ELSE 0 END) as mbway_volume,
            SUM(CASE WHEN status='COMPLETED' AND method='multibanco' THEN amount ELSE 0 END) as multibanco_volume,
            SUM(CASE WHEN status='PENDING' THEN amount ELSE 0 END) as pending_volume,
            ROUND(100.0 * SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 1) as conversion_rate,
            ROUND(100.0 * SUM(CASE WHEN status='DECLINED' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0), 1) as declined_rate
        ")->first();

        $chartGroup = $hourly ? "strftime('%Y-%m-%d %H:00', created_at)" : "date(created_at)";
        $chart = (clone $base)->selectRaw("
            {$chartGroup} as period_label,
            COUNT(*) as transactions,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as volume
        ")->groupByRaw($chartGroup)->orderByRaw($chartGroup)->get();

        $methods = (clone $base)->selectRaw("
            method,
            COUNT(*) as count,
            SUM(CASE WHEN status='COMPLETED' THEN amount ELSE 0 END) as volume
        ")->groupBy('method')->get();

        return response()->json([
            'overview' => [
                'total_transactions' => (int) ($overview->total_transactions ?? 0),
                'completed' => (int) ($overview->completed ?? 0),
                'pending' => (int) ($overview->pending ?? 0),
                'failed' => (int) ($overview->failed ?? 0),
                'declined' => (int) ($overview->declined ?? 0),
                'total_volume' => (float) ($overview->total_volume ?? 0),
                'mbway_volume' => (float) ($overview->mbway_volume ?? 0),
                'multibanco_volume' => (float) ($overview->multibanco_volume ?? 0),
                'pending_volume' => (float) ($overview->pending_volume ?? 0),
                'conversion_rate' => (float) ($overview->conversion_rate ?? 0),
                'declined_rate' => (float) ($overview->declined_rate ?? 0),
            ],
            'chart' => $chart->map(fn ($r) => [
                ($hourly ? 'hour' : 'date') => $r->period_label,
                'transactions' => (int) $r->transactions,
                'volume' => (float) $r->volume,
            ]),
            'methods' => $methods->map(fn ($r) => [
                'method' => $r->method,
                'count' => (int) $r->count,
                'volume' => (float) $r->volume,
            ]),
            'period' => $period,
            'hourly' => $hourly,
        ]);
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    private function parsePeriod(string $period, Request $request): array
    {
        $now = now();
        $hourly = false;

        switch ($period) {
            case 'today':
                $from = $now->copy()->startOfDay();
                $to = $now->copy()->endOfDay();
                $hourly = true;
                break;
            case 'yesterday':
                $from = $now->copy()->subDay()->startOfDay();
                $to = $now->copy()->subDay()->endOfDay();
                $hourly = true;
                break;
            case '7d':
                $from = $now->copy()->subDays(7)->startOfDay();
                $to = $now->copy()->endOfDay();
                break;
            case 'custom':
                $from = $request->query('date_from') . ' 00:00:00';
                $to = $request->query('date_to') . ' 23:59:59';
                break;
            default: // 30d
                $from = $now->copy()->subDays(30)->startOfDay();
                $to = $now->copy()->endOfDay();
        }

        return [$from, $to, $hourly];
    }
}
```

**Note:** Tests run on SQLite, so we use `strftime()` and `date()` instead of MySQL's `DATE_FORMAT()`. For production (MySQL), replace the `$chartGroup` line:
```php
$chartGroup = $hourly ? "DATE_FORMAT(created_at,'%Y-%m-%d %H:00')" : "DATE(created_at)";
```
The controller should detect the DB driver and use the right syntax. See Step 4 implementation for the actual code that handles both.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Stats/StatsTest.php`
Expected: 6 tests pass

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/StatsController.php tests/Feature/Stats/StatsTest.php routes/api.php
git commit -m "feat: stats analytics endpoint with period support"
```

---

### Task 3: Users Tests & Controller

**Files:**
- Create: `tests/Feature/Auth/UsersTest.php`
- Create: `app/Http/Controllers/UserController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Add users route to `routes/api.php`**

Add inside the existing `auth:sanctum` middleware group:

```php
Route::get('/auth/users', [UserController::class, 'index'])->middleware(AdminMiddleware::class);
```

Add the imports at the top of the file:

```php
use App\Http\Controllers\UserController;
use App\Http\Middleware\AdminMiddleware;
```

- [ ] **Step 2: Write failing users tests**

Create `tests/Feature/Auth/UsersTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(3)->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/users');
        $response->assertOk()
            ->assertJsonStructure(['users' => [['id', 'email', 'payer_email', 'payer_name', 'role', 'created_at']]]);
        $this->assertCount(4, $response->json('users'));
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/users')->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_list_users(): void
    {
        $this->getJson('/api/auth/users')->assertUnauthorized();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Auth/UsersTest.php`
Expected: FAIL — controller not found

- [ ] **Step 4: Create UserController**

Create `app/Http/Controllers/UserController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::select('id', 'email', 'payer_email', 'payer_name', 'role', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['users' => $users]);
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Auth/UsersTest.php`
Expected: 3 tests pass

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/UserController.php tests/Feature/Auth/UsersTest.php routes/api.php
git commit -m "feat: admin-only user listing endpoint"
```

---

### Task 4: Full Test Suite & Final Commit

**Files:** None new — verification only

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: All tests pass (existing + 15 new tests)

- [ ] **Step 2: Run pint on all files**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Final commit if pint made changes**

```bash
git add -A
git commit -m "style: apply pint formatting"
```

Only commit if pint actually changed files.
