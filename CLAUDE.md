# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Layout

```
hub-laravel/          # Laravel 13 payment hub API (the main project — work here)
  app/
    Console/Commands/ # CLI artisan commands
    Http/Controllers/ # 28 API controllers; Admin* prefix for admin-only endpoints
    Http/Middleware/  # AdminMiddleware (checks isAdmin())
    Jobs/             # Background jobs (ReleaseBalanceJob)
    Models/           # 13 Eloquent models
    Services/         # Business logic (WayMbService, BalanceService, etc.)
  database/
    migrations/       # 26 migrations; baseline is 2026_01_01_000000
  routes/api.php      # 3 route groups: public, auth:sanctum, auth:sanctum+admin
  tests/
    Feature/          # Integration tests (preferred)
    Unit/Services/    # Unit tests for services
  CLAUDE.md           # Laravel Boost guidelines + project context — read this too
```

## Commands

All commands run from inside `hub-laravel/`:

```bash
composer run setup   # First-time: install deps, key, migrate, build assets
composer run dev     # Dev server + queue + logs + Vite (concurrently)
composer run test    # Run all tests (clears config cache first)

php artisan test --compact                                    # All tests
php artisan test --compact tests/Feature/Auth/AuthTest.php   # Single file
php artisan test --compact --filter=test_login_returns_token # Single test

vendor/bin/pint --dirty --format agent  # Format changed PHP files (run after edits)
```

## Architecture

**Stack:** PHP 8.4, Laravel 13, Octane (FrankenPHP), Sanctum, SQLite (dev) / MySQL (prod)

**Route groups (`routes/api.php`):**
1. Public — auth, payment creation, status checks, webhooks
2. `auth:sanctum` — user transactions, balance, stats, links
3. `auth:sanctum` + `AdminMiddleware` — user management, payouts, milestones

**Controller pattern:** one responsibility per controller, no resource controllers. Admin controllers are prefixed `Admin*`.

**Services:** registered with `scoped()` (never `singleton()`) in `AppServiceProvider` for Octane safety. Inject via constructor property promotion.

**Models:** use PHP 8 attributes (`#[Fillable]`, `#[Hidden]`) and explicit `casts()` method.

## Schema Conventions (non-standard)

- `users.password_hash` instead of `password` — `User::getAuthPassword()` handles this
- No `updated_at` on users — `User::UPDATED_AT = null`
- No `remember_token`, `email_verified_at`, or `name` on users
- Baseline migration (`2026_01_01_000000`) replaces all default Laravel migrations

## Domain

Payment hub for MBWay/Multibanco payments. Transaction statuses: PENDING → COMPLETED | FAILED | EXPIRED | DECLINED. Terminal statuses (COMPLETED, FAILED, EXPIRED, DECLINED) cannot transition further.
