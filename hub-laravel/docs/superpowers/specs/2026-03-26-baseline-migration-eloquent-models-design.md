# FRA-6: Baseline Migration + Eloquent Models

## Goal

Replace default Laravel migrations with a baseline that matches the production schema exactly. Create User and Transaction Eloquent models with proper casts, relationships, and auth support.

## Files to Create/Modify

| Action | File |
|--------|------|
| Delete | `database/migrations/0001_01_01_000000_create_users_table.php` |
| Delete | `database/migrations/0001_01_01_000001_create_cache_table.php` |
| Delete | `database/migrations/0001_01_01_000002_create_jobs_table.php` |
| Delete | `database/migrations/2026_03_26_131549_create_personal_access_tokens_table.php` |
| Create | `database/migrations/2026_01_01_000000_create_baseline_schema.php` |
| Create | `database/migrations/2026_01_01_000001_create_personal_access_tokens.php` |
| Modify | `app/Models/User.php` |
| Create | `app/Models/Transaction.php` |
| Create | `tests/Feature/Models/UserModelTest.php` |
| Modify | `database/factories/UserFactory.php` |

## Schema

### Baseline Migration (`2026_01_01_000000`)

**users table:**

| Column | Type | Constraints |
|--------|------|-------------|
| id | integer (auto-increment) | PK |
| email | varchar(255) | unique, not null |
| password_hash | varchar(255) | not null |
| payer_email | varchar(255) | not null |
| payer_name | varchar(255) | not null, default '' |
| success_url | varchar(500) | not null, default '' |
| failed_url | varchar(500) | not null, default '' |
| pushcut_url | varchar(500) | not null, default '' |
| role | enum('user','admin') | default 'user' |
| pushcut_notify | enum('all','created','paid') | default 'all' |
| created_at | timestamp | not null, default current |

**transactions table:**

| Column | Type | Constraints |
|--------|------|-------------|
| id | integer (auto-increment) | PK |
| transaction_id | varchar(255) | unique, not null |
| user_id | integer | FK -> users(id), not null |
| amount | decimal(10,2) | not null |
| currency | varchar(3) | default 'EUR' |
| method | enum('mbway','multibanco') | not null |
| status | enum('PENDING','COMPLETED','FAILED','EXPIRED','DECLINED') | not null, default 'PENDING' |
| payer_email | varchar(255) | nullable |
| payer_name | varchar(255) | nullable |
| payer_document | varchar(50) | nullable |
| payer_phone | varchar(20) | nullable |
| reference_entity | varchar(20) | nullable |
| reference_number | varchar(50) | nullable |
| reference_expires_at | varchar(50) | nullable |
| callback_data | json | nullable |
| created_at | timestamp | not null |
| updated_at | timestamp | not null |

### Personal Access Tokens Migration (`2026_01_01_000001`)

Standard Sanctum `personal_access_tokens` table. Separated so it can run independently on existing production DBs where the baseline is marked as already executed.

## Models

### User.php

- Traits: `HasApiTokens`, `HasFactory`
- Auth column: `password_hash` (override `getAuthPassword()` to return `$this->password_hash`)
- Timestamps: only `created_at` (set `UPDATED_AT = null`)
- Fillable: `email`, `password_hash`, `payer_email`, `payer_name`, `success_url`, `failed_url`, `pushcut_url`, `role`, `pushcut_notify`
- Hidden: `password_hash`
- Casts: `created_at` as datetime
- Relationship: `hasMany(Transaction::class)`

### Transaction.php

- No auth traits
- Traits: `HasFactory`
- Constant: `TERMINAL_STATUSES = ['COMPLETED', 'FAILED', 'EXPIRED', 'DECLINED']`
- Fillable: all columns except `id`
- Casts: `amount` as decimal:2, `callback_data` as array
- Relationship: `belongsTo(User::class)`

### UserFactory.php

Update to match new schema — generate `password_hash`, `payer_email`, `payer_name`, and other required fields.

## Tests

### `tests/Feature/Models/UserModelTest.php`

Two tests:
1. **can create user via factory** — creates user, asserts it exists in DB with correct attributes
2. **can create API token** — creates user, calls `createToken()`, asserts token exists

## Production Deploy Strategy

On existing production DBs: insert the baseline migration name into the `migrations` table without running it (the tables already exist). Only `personal_access_tokens` runs.
