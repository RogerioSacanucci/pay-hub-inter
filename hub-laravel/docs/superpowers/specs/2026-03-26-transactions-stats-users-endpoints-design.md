# Transactions, Stats & Users Endpoints — Design Spec

**Date:** 2026-03-26
**Linear:** FRA-10
**Branch:** `fabricio1099/fra-10-task-6-transactions-stats-users-endpoints-v1`

## Overview

Three new API endpoints: transaction listing with filters/pagination, analytics stats, and admin-only user listing. Replaces legacy `hub/api/transactions.php`, `hub/api/stats.php`, `hub/api/auth/users.php`.

## Routes

Added to `routes/api.php` inside the existing `auth:sanctum` group:

| Method | Path | Controller | Extra Middleware |
|--------|------|------------|------------------|
| GET | `/api/transactions` | TransactionController@index | — |
| GET | `/api/stats` | StatsController@index | — |
| GET | `/api/auth/users` | UserController@index | AdminMiddleware |

## TransactionController

**File:** `app/Http/Controllers/TransactionController.php`

### `index(Request $request): JsonResponse`

**Authorization:**
- Regular users: scoped to `user_id = auth user`
- Admins: see all transactions; optional `?user_id=X` filter

**Filters (query params):**
- `status` — exact match (uppercased)
- `method` — exact match (lowercased)
- `date_from` — `created_at >= YYYY-MM-DD 00:00:00`
- `date_to` — `created_at <= YYYY-MM-DD 23:59:59`
- `transaction_id` — LIKE search

**Pagination:** 20 per page, `?page=N`

**Response:**
```json
{
  "data": [
    {
      "transaction_id": "uuid",
      "amount": 10.50,
      "currency": "EUR",
      "method": "mbway",
      "status": "COMPLETED",
      "payer_name": "...",
      "payer_email": "...",
      "payer_document": "...",
      "reference_entity": "...",
      "reference_number": "...",
      "reference_expires_at": "...",
      "created_at": "...",
      "updated_at": "..."
    }
  ],
  "meta": {
    "total": 25,
    "page": 1,
    "per_page": 20,
    "pages": 2
  }
}
```

**Order:** `created_at DESC`

## StatsController

**File:** `app/Http/Controllers/StatsController.php`

### `index(Request $request): JsonResponse`

**Authorization:** same as TransactionController (user sees own, admin sees all or filters by `?user_id=X`)

**Period (query param `?period=`):**
- `today` — start/end of today, hourly grouping
- `yesterday` — start/end of yesterday, hourly grouping
- `7d` — last 7 days, daily grouping
- `30d` (default) — last 30 days, daily grouping
- `custom` — requires `?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`, daily grouping

**Query strategy:** Raw `DB::table('transactions')` with `selectRaw` for performance. Three query sections:

1. **Overview** — single row: total_transactions, completed, pending, failed, declined, total_volume, mbway_volume, multibanco_volume, pending_volume, conversion_rate, declined_rate
2. **Chart** — grouped by `DATE(created_at)` or `DATE_FORMAT(created_at, '%Y-%m-%d %H:00')` for hourly
3. **Methods** — grouped by method: count and volume per method

**Response:**
```json
{
  "overview": { "total_transactions": 100, "completed": 80, ... },
  "chart": [{ "date": "2026-03-25", "transactions": 10, "volume": 500.0 }],
  "methods": [{ "method": "mbway", "count": 50, "volume": 2500.0 }],
  "period": "30d",
  "hourly": false
}
```

**Note:** The ticket's dynamic key `$hourly ? 'hour' : 'date' => $r->period_label` is invalid PHP in an arrow function. Fix: use a ternary to build the array with the correct key.

## UserController

**File:** `app/Http/Controllers/UserController.php`

### `index(): JsonResponse`

**Authorization:** AdminMiddleware (403 for non-admins)

**Response:**
```json
{
  "users": [
    {
      "id": 1,
      "email": "...",
      "payer_email": "...",
      "payer_name": "...",
      "role": "user",
      "created_at": "..."
    }
  ]
}
```

**Order:** `created_at DESC`

No pagination (user list expected to be small).

## Tests

### `tests/Feature/Transactions/TransactionsTest.php`
- `test_user_sees_only_own_transactions` — regular user scoped
- `test_admin_sees_all_transactions` — admin sees everything
- `test_transactions_filter_by_status` — status filter works
- `test_transactions_paginate` — 25 records, page 1 returns 20 with correct meta

### `tests/Feature/Stats/StatsTest.php`
- `test_user_sees_own_stats` — regular user stats scoped to own transactions
- `test_admin_sees_all_stats` — admin sees aggregate stats
- `test_stats_period_today_returns_hourly` — hourly flag true for today
- `test_stats_default_period_is_30d` — default period behavior

### `tests/Feature/Auth/UsersTest.php`
- `test_admin_can_list_users` — admin gets user list
- `test_non_admin_cannot_list_users` — regular user gets 403

## Conventions

- Inline validation (no FormRequest)
- PHPUnit with `RefreshDatabase`
- `response()->json([...])` for responses
- PHP 8 type hints on all parameters and return types
- Run `vendor/bin/pint --dirty --format agent` before commit
