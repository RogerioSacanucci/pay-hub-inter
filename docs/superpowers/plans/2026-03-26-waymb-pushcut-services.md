# WayMb + Pushcut Services Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement WayMbService and PushcutService for payment API integration and fire-and-forget notifications.

**Architecture:** Two service classes under `app/Services/`. WayMbService is bound as scoped in the container with config from `config/services.php`. PushcutService is a standalone class with no container binding. TDD approach — tests first, then implementation.

**Tech Stack:** Laravel 13, PHP 8.4, Http client, PHPUnit 12

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `hub-laravel/app/Services/WayMbService.php` | HTTP client for WayMB payment API |
| Create | `hub-laravel/app/Services/PushcutService.php` | Fire-and-forget notification sender |
| Modify | `hub-laravel/config/services.php` | Add `waymb` config block |
| Modify | `hub-laravel/app/Providers/AppServiceProvider.php` | Scoped binding for WayMbService |
| Create | `hub-laravel/tests/Feature/Services/WayMbServiceTest.php` | Tests for WayMbService |
| Create | `hub-laravel/tests/Feature/Services/PushcutServiceTest.php` | Tests for PushcutService |

---

### Task 1: WayMbService — Write Failing Tests

**Files:**
- Create: `hub-laravel/tests/Feature/Services/WayMbServiceTest.php`

- [ ] **Step 1: Create the test file**

Run: `cd hub-laravel && php artisan make:test --phpunit --no-interaction Services/WayMbServiceTest`

Then replace the contents with:

```php
<?php

namespace Tests\Feature\Services;

use App\Services\WayMbService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WayMbServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_create_transaction_sends_correct_post_request(): void
    {
        Http::fake([
            'https://api.waymb.test/api/transactions' => Http::response([
                'transaction_id' => 'txn-123',
                'status' => 'PENDING',
            ], 200),
        ]);

        $service = new WayMbService(
            url: 'https://api.waymb.test',
            accountEmail: 'merchant@example.com',
        );

        $result = $service->createTransaction([
            'amount' => 10.50,
            'currency' => 'EUR',
            'method' => 'mbway',
            'payer_phone' => '912345678',
        ]);

        $this->assertEquals('txn-123', $result['transaction_id']);
        $this->assertEquals('PENDING', $result['status']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.waymb.test/api/transactions'
                && $request->method() === 'POST'
                && $request['amount'] === 10.50
                && $request['currency'] === 'EUR'
                && $request['method'] === 'mbway'
                && $request['payer_phone'] === '912345678'
                && $request['account_email'] === 'merchant@example.com';
        });
    }

    public function test_get_transaction_info_sends_correct_get_request(): void
    {
        Http::fake([
            'https://api.waymb.test/api/transactions/txn-456' => Http::response([
                'transaction_id' => 'txn-456',
                'status' => 'COMPLETED',
                'amount' => 25.00,
            ], 200),
        ]);

        $service = new WayMbService(
            url: 'https://api.waymb.test',
            accountEmail: 'merchant@example.com',
        );

        $result = $service->getTransactionInfo('txn-456');

        $this->assertEquals('txn-456', $result['transaction_id']);
        $this->assertEquals('COMPLETED', $result['status']);
        $this->assertEquals(25.00, $result['amount']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.waymb.test/api/transactions/txn-456'
                && $request->method() === 'GET';
        });
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd hub-laravel && php artisan test --compact --filter=WayMbServiceTest`

Expected: FAIL — `Class "App\Services\WayMbService" not found`

---

### Task 2: WayMbService — Implement and Pass Tests

**Files:**
- Create: `hub-laravel/app/Services/WayMbService.php`

- [ ] **Step 1: Create the service class**

Run: `cd hub-laravel && php artisan make:class --no-interaction Services/WayMbService`

Then replace the contents with:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WayMbService
{
    public function __construct(
        public readonly string $url,
        public readonly string $accountEmail,
    ) {}

    /**
     * @param  array{amount: float, currency: string, method: string, payer_phone?: string, payer_email?: string, payer_name?: string, payer_document?: string}  $data
     * @return array<string, mixed>
     */
    public function createTransaction(array $data): array
    {
        $response = Http::timeout(10)
            ->throw()
            ->post("{$this->url}/api/transactions", [
                ...$data,
                'account_email' => $this->accountEmail,
            ]);

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionInfo(string $transactionId): array
    {
        $response = Http::timeout(10)
            ->throw()
            ->get("{$this->url}/api/transactions/{$transactionId}");

        return $response->json();
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `cd hub-laravel && php artisan test --compact --filter=WayMbServiceTest`

Expected: 2 tests, 2 passed

- [ ] **Step 3: Run pint**

Run: `cd hub-laravel && vendor/bin/pint --dirty --format agent`

- [ ] **Step 4: Commit**

```bash
git add hub-laravel/app/Services/WayMbService.php hub-laravel/tests/Feature/Services/WayMbServiceTest.php
git commit -m "feat: add WayMbService with createTransaction and getTransactionInfo"
```

---

### Task 3: WayMbService — Config and Service Binding

**Files:**
- Modify: `hub-laravel/config/services.php`
- Modify: `hub-laravel/app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Add waymb config to services.php**

Add the following entry at the end of the array in `hub-laravel/config/services.php`, before the closing `];`:

```php
    'waymb' => [
        'url' => env('WAYMB_URL'),
        'account_email' => env('WAYMB_ACCOUNT_EMAIL'),
    ],
```

- [ ] **Step 2: Register scoped binding in AppServiceProvider**

Update `hub-laravel/app/Providers/AppServiceProvider.php` register method:

```php
<?php

namespace App\Providers;

use App\Services\WayMbService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WayMbService::class, fn () => new WayMbService(
            url: config('services.waymb.url'),
            accountEmail: config('services.waymb.account_email'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
```

- [ ] **Step 3: Run all tests to verify nothing broke**

Run: `cd hub-laravel && php artisan test --compact`

Expected: All tests pass

- [ ] **Step 4: Run pint**

Run: `cd hub-laravel && vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add hub-laravel/config/services.php hub-laravel/app/Providers/AppServiceProvider.php
git commit -m "feat: add waymb config and scoped service binding"
```

---

### Task 4: PushcutService — Write Failing Tests

**Files:**
- Create: `hub-laravel/tests/Feature/Services/PushcutServiceTest.php`

- [ ] **Step 1: Create the test file**

Run: `cd hub-laravel && php artisan make:test --phpunit --no-interaction Services/PushcutServiceTest`

Then replace the contents with:

```php
<?php

namespace Tests\Feature\Services;

use App\Services\PushcutService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PushcutServiceTest extends TestCase
{
    public function test_send_posts_correct_payload(): void
    {
        Http::fake([
            'https://pushcut.example.com/notify' => Http::response([], 200),
        ]);

        $service = new PushcutService;

        $service->send(
            url: 'https://pushcut.example.com/notify',
            title: 'Payment received',
            data: ['amount' => 10.50, 'status' => 'COMPLETED'],
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pushcut.example.com/notify'
                && $request->method() === 'POST'
                && $request['title'] === 'Payment received'
                && $request['data']['amount'] === 10.50
                && $request['data']['status'] === 'COMPLETED';
        });
    }

    public function test_send_without_data_omits_data_field(): void
    {
        Http::fake([
            'https://pushcut.example.com/notify' => Http::response([], 200),
        ]);

        $service = new PushcutService;

        $service->send(
            url: 'https://pushcut.example.com/notify',
            title: 'Simple notification',
        );

        Http::assertSent(function ($request) {
            return $request->url() === 'https://pushcut.example.com/notify'
                && $request['title'] === 'Simple notification'
                && ! isset($request['data']);
        });
    }

    public function test_send_logs_warning_on_failure_and_does_not_throw(): void
    {
        Http::fake([
            'https://pushcut.example.com/notify' => Http::response([], 500),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Pushcut notification failed')
                    && $context['url'] === 'https://pushcut.example.com/notify';
            });

        $service = new PushcutService;

        // Should not throw — fire and forget
        $service->send(
            url: 'https://pushcut.example.com/notify',
            title: 'Payment received',
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd hub-laravel && php artisan test --compact --filter=PushcutServiceTest`

Expected: FAIL — `Class "App\Services\PushcutService" not found`

---

### Task 5: PushcutService — Implement and Pass Tests

**Files:**
- Create: `hub-laravel/app/Services/PushcutService.php`

- [ ] **Step 1: Create the service class**

Run: `cd hub-laravel && php artisan make:class --no-interaction Services/PushcutService`

Then replace the contents with:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushcutService
{
    /**
     * Send a fire-and-forget Pushcut notification.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function send(string $url, string $title, ?array $data = null): void
    {
        try {
            $payload = ['title' => $title];

            if ($data !== null) {
                $payload['data'] = $data;
            }

            Http::timeout(5)
                ->throw()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('Pushcut notification failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `cd hub-laravel && php artisan test --compact --filter=PushcutServiceTest`

Expected: 3 tests, 3 passed

- [ ] **Step 3: Run pint**

Run: `cd hub-laravel && vendor/bin/pint --dirty --format agent`

- [ ] **Step 4: Run full test suite**

Run: `cd hub-laravel && php artisan test --compact`

Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add hub-laravel/app/Services/PushcutService.php hub-laravel/tests/Feature/Services/PushcutServiceTest.php
git commit -m "feat: add PushcutService with fire-and-forget notifications"
```
