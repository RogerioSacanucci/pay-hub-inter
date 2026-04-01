# Timezone Filter (utc_offset) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `utc_offset` query parameter support to all `parsePeriod()` methods so date filters reflect the user's local timezone.

**Architecture:** Three controllers each have a `parsePeriod()` method that computes `$from`/`$to` date boundaries in UTC. We add an integer `utc_offset` param (e.g. `-3`, `0`, `5`) and shift boundaries accordingly: for presets, shift `$now` before `startOfDay()`/`endOfDay()`; for custom dates, parse then `subHours($offset)`.

**Tech Stack:** Laravel 13, Carbon, PHPUnit

---

### Task 1: Add utc_offset support to StatsController::parsePeriod()

**Files:**
- Modify: `app/Http/Controllers/StatsController.php:108-142`

- [ ] **Step 1: Write the failing test**

Create test file `tests/Feature/Stats/StatsUtcOffsetTest.php`:

```php
<?php

namespace Tests\Feature\Stats;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatsUtcOffsetTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_with_negative_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        // 01:00 UTC on Mar 31 = still Mar 30 in UTC-3 → should be excluded from "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC on Mar 31 = Mar 31 01:00 in UTC-3 → should be included in "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_today_with_positive_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        // 20:00 UTC on Mar 30 = Mar 31 01:00 in UTC+5 → should be included in "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 75,
            'created_at' => '2026-03-30 20:00:00',
        ]);
        // 19:00 UTC on Mar 31 = Mar 32 00:00 in UTC+5 → should be excluded from "today"
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 200,
            'created_at' => '2026-03-31 19:30:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today&utc_offset=5');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(75.0, $response->json('overview.total_volume'));
    }

    public function test_custom_period_with_offset_shifts_boundaries(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        // 02:00 UTC = still Mar 30 in UTC-3 → excluded
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 50,
            'created_at' => '2026-03-31 02:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=custom&date_from=2026-03-31&date_to=2026-03-31&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_no_utc_offset_defaults_to_zero(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->create();
        Transaction::factory()->completed()->create([
            'user_id' => $user->id,
            'amount' => 100,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/stats?period=today');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_transactions'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=StatsUtcOffsetTest`
Expected: FAIL — the offset transactions are not filtered correctly because `parsePeriod` ignores `utc_offset`.

- [ ] **Step 3: Implement utc_offset in StatsController::parsePeriod()**

Replace the `parsePeriod` method in `app/Http/Controllers/StatsController.php`:

```php
/**
 * @return array{0: string, 1: string, 2: bool}
 */
private function parsePeriod(string $period, Request $request): array
{
    $offset = (int) $request->query('utc_offset', 0);
    $now = now()->addHours($offset);
    $hourly = false;

    switch ($period) {
        case 'today':
            $from = $now->copy()->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
            $hourly = true;
            break;
        case 'yesterday':
            $from = $now->copy()->subDay()->startOfDay()->subHours($offset);
            $to = $now->copy()->subDay()->endOfDay()->subHours($offset);
            $hourly = true;
            break;
        case '7d':
            $from = $now->copy()->subDays(7)->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
            break;
        case 'custom':
            $request->validate([
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d'],
            ]);
            $from = Carbon::parse($request->query('date_from').' 00:00:00')->subHours($offset);
            $to = Carbon::parse($request->query('date_to').' 23:59:59')->subHours($offset);
            break;
        default: // 30d
            $from = $now->copy()->subDays(30)->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
    }

    return [$from, $to, $hourly];
}
```

Also add the Carbon import at the top of `StatsController.php`:

```php
use Illuminate\Support\Carbon;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=StatsUtcOffsetTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Verify existing tests still pass**

Run: `php artisan test --compact --filter=StatsTest`
Expected: PASS (6 tests, no regressions)

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/StatsController.php tests/Feature/Stats/StatsUtcOffsetTest.php
git commit -m "feat: add utc_offset support to StatsController::parsePeriod()"
```

---

### Task 2: Add utc_offset support to CartpandaStatsController::parsePeriod()

**Files:**
- Modify: `app/Http/Controllers/CartpandaStatsController.php:95-129`

- [ ] **Step 1: Write the failing test**

Create test file `tests/Feature/Stats/CartpandaStatsUtcOffsetTest.php`:

```php
<?php

namespace Tests\Feature\Stats;

use App\Models\CartpandaOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CartpandaStatsUtcOffsetTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_with_negative_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        // 01:00 UTC = still Mar 30 in UTC-3 → excluded
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_today_with_positive_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        // 20:00 UTC on Mar 30 = Mar 31 01:00 in UTC+5 → included
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 75,
            'created_at' => '2026-03-30 20:00:00',
        ]);
        // 19:30 UTC on Mar 31 = Apr 01 00:30 in UTC+5 → excluded
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 200,
            'created_at' => '2026-03-31 19:30:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=today&utc_offset=5');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
        $this->assertEquals(75.0, $response->json('overview.total_volume'));
    }

    public function test_custom_period_with_offset_shifts_boundaries(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        // 02:00 UTC = still Mar 30 in UTC-3 → excluded
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 02:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=custom&date_from=2026-03-31&date_to=2026-03-31&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
        $this->assertEquals(100.0, $response->json('overview.total_volume'));
    }

    public function test_no_utc_offset_defaults_to_zero(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $user = User::factory()->withCartpandaParam('afiliado1')->create();
        CartpandaOrder::factory()->for($user)->create([
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/internacional-stats?period=today');

        $response->assertOk();
        $this->assertEquals(1, $response->json('overview.total_orders'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=CartpandaStatsUtcOffsetTest`
Expected: FAIL

- [ ] **Step 3: Implement utc_offset in CartpandaStatsController::parsePeriod()**

Replace the `parsePeriod` method in `app/Http/Controllers/CartpandaStatsController.php`:

```php
/**
 * @return array{0: string, 1: string, 2: bool}
 */
private function parsePeriod(string $period, Request $request): array
{
    $offset = (int) $request->query('utc_offset', 0);
    $now = now()->addHours($offset);
    $hourly = false;

    switch ($period) {
        case 'today':
            $from = $now->copy()->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
            $hourly = true;
            break;
        case 'yesterday':
            $from = $now->copy()->subDay()->startOfDay()->subHours($offset);
            $to = $now->copy()->subDay()->endOfDay()->subHours($offset);
            $hourly = true;
            break;
        case '7d':
            $from = $now->copy()->subDays(7)->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
            break;
        case 'custom':
            $request->validate([
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d'],
            ]);
            $from = Carbon::parse($request->query('date_from').' 00:00:00')->subHours($offset);
            $to = Carbon::parse($request->query('date_to').' 23:59:59')->subHours($offset);
            break;
        default: // 30d
            $from = $now->copy()->subDays(30)->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
    }

    return [$from, $to, $hourly];
}
```

Also add the Carbon import at the top of `CartpandaStatsController.php`:

```php
use Illuminate\Support\Carbon;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=CartpandaStatsUtcOffsetTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Verify existing tests still pass**

Run: `php artisan test --compact --filter=CartpandaStatsTest`
Expected: PASS (8 tests, no regressions)

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/CartpandaStatsController.php tests/Feature/Stats/CartpandaStatsUtcOffsetTest.php
git commit -m "feat: add utc_offset support to CartpandaStatsController::parsePeriod()"
```

---

### Task 3: Add utc_offset support to AdminCartpandaShopController::parsePeriod()

**Files:**
- Modify: `app/Http/Controllers/AdminCartpandaShopController.php:148-192`

- [ ] **Step 1: Write the failing test**

Create test file `tests/Feature/Admin/AdminCartpandaShopsUtcOffsetTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminCartpandaShopsUtcOffsetTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_index_with_negative_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // 01:00 UTC = still Mar 30 in UTC-3 → excluded from "today"
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops?period=today&utc_offset=-3');

        $response->assertOk();
        $data = $response->json('data.0');
        $this->assertEquals(1, $data['orders_count']);
        $this->assertEquals(100.0, $data['total_volume']);
    }

    public function test_shop_show_with_negative_offset_shifts_date_range(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // 01:00 UTC = still Mar 30 in UTC-3 → excluded
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=today&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('aggregate.total_orders'));
        $this->assertEquals(100.0, $response->json('aggregate.total_volume'));
    }

    public function test_shop_show_custom_period_with_offset(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        // 02:00 UTC = still Mar 30 in UTC-3 → excluded
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 50,
            'created_at' => '2026-03-31 02:00:00',
        ]);
        // 04:00 UTC = Mar 31 01:00 in UTC-3 → included
        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 04:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=custom&date_from=2026-03-31&date_to=2026-03-31&utc_offset=-3');

        $response->assertOk();
        $this->assertEquals(1, $response->json('aggregate.total_orders'));
        $this->assertEquals(100.0, $response->json('aggregate.total_volume'));
    }

    public function test_no_offset_preserves_existing_behavior(): void
    {
        Carbon::setTestNow('2026-03-31 12:00:00');

        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $shop->users()->attach($user->id);

        CartpandaOrder::factory()->create([
            'shop_id' => $shop->id,
            'user_id' => $user->id,
            'status' => 'COMPLETED',
            'amount' => 100,
            'created_at' => '2026-03-31 01:00:00',
        ]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/admin/internacional-shops/'.$shop->id.'?period=today');

        $response->assertOk();
        $this->assertEquals(1, $response->json('aggregate.total_orders'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AdminCartpandaShopsUtcOffsetTest`
Expected: FAIL

- [ ] **Step 3: Implement utc_offset in AdminCartpandaShopController::parsePeriod()**

Replace the `parsePeriod` method in `app/Http/Controllers/AdminCartpandaShopController.php`:

```php
/**
 * @return array{0: string, 1: string, 2: bool}
 */
private function parsePeriod(string $period, Request $request): array
{
    $offset = (int) $request->query('utc_offset', 0);
    $now = now()->addHours($offset);
    $hourly = false;

    switch ($period) {
        case 'today':
            $from = $now->copy()->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
            $hourly = true;
            break;
        case 'yesterday':
            $from = $now->copy()->subDay()->startOfDay()->subHours($offset);
            $to = $now->copy()->subDay()->endOfDay()->subHours($offset);
            $hourly = true;
            break;
        case '7d':
            $from = $now->copy()->subDays(7)->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
            break;
        case 'custom':
            $request->validate([
                'date_from' => ['required', 'date_format:Y-m-d'],
                'date_to' => ['required', 'date_format:Y-m-d'],
            ]);
            $from = Carbon::parse($request->query('date_from').' 00:00:00')->subHours($offset);
            $to = Carbon::parse($request->query('date_to').' 23:59:59')->subHours($offset);
            break;
        default: // 30d
            $from = $now->copy()->subDays(30)->startOfDay()->subHours($offset);
            $to = $now->copy()->endOfDay()->subHours($offset);
    }

    return [$from, $to, $hourly];
}
```

Also add the Carbon import at the top of `AdminCartpandaShopController.php`:

```php
use Illuminate\Support\Carbon;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=AdminCartpandaShopsUtcOffsetTest`
Expected: PASS (4 tests)

- [ ] **Step 5: Verify existing tests still pass**

Run: `php artisan test --compact --filter=AdminCartpandaShopsTest`
Expected: PASS (9 tests, no regressions)

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AdminCartpandaShopController.php tests/Feature/Admin/AdminCartpandaShopsUtcOffsetTest.php
git commit -m "feat: add utc_offset support to AdminCartpandaShopController::parsePeriod()"
```

---

### Task 4: Final verification — full test suite

- [ ] **Step 1: Run all stats-related tests together**

Run: `php artisan test --compact --filter="Stats|AdminCartpandaShops"`
Expected: All tests PASS (no regressions)

- [ ] **Step 2: Run pint on all modified files**

Run: `vendor/bin/pint --dirty --format agent`
Expected: No formatting issues

- [ ] **Step 3: Ask user if they want to run the full test suite**
