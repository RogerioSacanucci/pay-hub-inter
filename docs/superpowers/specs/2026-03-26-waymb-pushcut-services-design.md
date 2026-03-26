# FRA-7: WayMb + Pushcut Services Design

## Overview

Implement two service classes for the payment hub: `WayMbService` for payment API integration and `PushcutService` for fire-and-forget notifications.

## WayMbService

### Config (`config/services.php`)

```php
'waymb' => [
    'url' => env('WAYMB_URL'),
    'account_email' => env('WAYMB_ACCOUNT_EMAIL'),
],
```

### Class: `app/Services/WayMbService.php`

- Constructor takes `string $url` and `string $accountEmail` via DI
- Bound as `scoped` in `AppServiceProvider` (Octane-safe)

**Methods:**

- `createTransaction(array $data): array` — POSTs to `{url}/api/transactions` with amount, currency, method, payer fields + account_email. Uses `Http::timeout(10)->throw()`. Returns decoded response array.
- `getTransactionInfo(string $transactionId): array` — GETs `{url}/api/transactions/{transactionId}`. Same timeout/throw pattern. Returns decoded response array.

**Error handling:** Throws exceptions via `Http::throw()` — callers decide how to handle.

### Service Binding (`AppServiceProvider`)

```php
$this->app->scoped(WayMbService::class, fn () => new WayMbService(
    url: config('services.waymb.url'),
    accountEmail: config('services.waymb.account_email'),
));
```

### Tests: `tests/Feature/Services/WayMbServiceTest.php`

- Uses `Http::fake()` + `Http::preventStrayRequests()`
- Test `createTransaction` sends correct POST with expected payload
- Test `getTransactionInfo` sends correct GET and returns parsed response

## PushcutService

### Class: `app/Services/PushcutService.php`

- No config dependencies — URL passed as parameter from User model's `pushcut_url`
- No service container binding needed — simple class

**Method:**

- `send(string $url, string $title, ?array $data = null): void` — POSTs JSON to the given URL. Fire-and-forget: wraps in try/catch, logs failures via `Log::warning()`, never throws. Uses `Http::timeout(5)`.

### Tests: `tests/Feature/Services/PushcutServiceTest.php`

- Uses `Http::fake()`
- Test that `send` posts correct payload to the given URL
- Test that exceptions are caught, logged, and not rethrown
