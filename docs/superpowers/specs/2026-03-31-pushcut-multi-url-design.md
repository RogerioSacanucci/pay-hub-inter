# Spec: Multiple Pushcut URLs per User + Cartpanda Status Fix

**Date:** 2026-03-31

---

## Overview

Two related changes:

1. **Multiple Pushcut URLs** — replace the single `pushcut_url` + `pushcut_notify` columns on `users` with a dedicated `user_pushcut_urls` table. Each URL has its own notification preference. All three notification points (PaymentController, WebhookController, CartpandaWebhookController) iterate over the user's URLs. A CRUD API and updated Settings UI are included.

2. **Cartpanda status fix** — the `STATUS_MAP` in `CartpandaWebhookController` maps `order.pending` → `PENDING`, but Cartpanda sends `order.created` for new orders, not `order.pending`. Replace the mapping accordingly.

---

## 1. Database

### New table: `user_pushcut_urls`

```sql
id            bigint PK auto_increment
user_id       bigint FK → users (cascade delete)
url           varchar(500) not null
notify        enum('all', 'created', 'paid') default 'all'
label         varchar(100) nullable
created_at    timestamp useCurrent()
```

No `updated_at` — consistent with the `users` table convention (`User::UPDATED_AT = null`).

### Migration strategy (single migration file)

The migration does three things in order:

1. **Create** the `user_pushcut_urls` table.
2. **Migrate data**: for every user where `pushcut_url` is not empty, insert one row into `user_pushcut_urls` preserving the `pushcut_notify` value as `notify`.
3. **Drop columns** `pushcut_url` and `pushcut_notify` from `users`.

```php
// Inside up():
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
```

---

## 2. Backend

### Model: `UserPushcutUrl`

File: `app/Models/UserPushcutUrl.php`

- PHP 8 attribute `#[Fillable(['user_id', 'url', 'notify', 'label'])]`
- `UPDATED_AT = null` (no updated_at column)
- `belongsTo(User::class)`
- Factory: `UserPushcutUrlFactory` with `fake()->url()`, `fake()->randomElement(['all','created','paid'])`, nullable label

### Model: `User` — changes

- Remove `pushcut_url` and `pushcut_notify` from `#[Fillable]`
- Add relation: `public function pushcutUrls(): HasMany { return $this->hasMany(UserPushcutUrl::class); }`

### Controller: `PushcutUrlController`

File: `app/Http/Controllers/PushcutUrlController.php`

Authenticated routes. Admin can pass `?user_id=X` to manage any user's URLs (consistent with existing admin pattern in `StatsController`, `TransactionController`).

```
GET    /api/pushcut-urls          index   — list authenticated user's URLs
POST   /api/pushcut-urls          store   — create new URL
PUT    /api/pushcut-urls/{id}     update  — update url/notify/label
DELETE /api/pushcut-urls/{id}     destroy — delete
```

**Authorization**: each action checks `$pushcutUrl->user_id === $user->id` (or admin). Return 403 otherwise.

**Validation** (store + update):
```php
'url'    => ['required', 'url', 'max:500']
'notify' => ['required', 'in:all,created,paid']
'label'  => ['nullable', 'string', 'max:100']
```

**Response shapes:**
- `index` → `{ "data": [ { "id": 1, "url": "...", "notify": "all", "label": "iPhone", "created_at": "..." } ] }`
- `store` → `{ "data": { single created item } }`, HTTP 201
- `update` → `{ "data": { single updated item } }`, HTTP 200
- `destroy` → `{ "ok": true }`, HTTP 200 (consistent with other endpoints in this codebase)

Frontend always re-fetches the list after any mutation.

### Routes: `routes/api.php`

Add inside the `auth:sanctum` middleware group:
```php
Route::apiResource('pushcut-urls', PushcutUrlController::class)->except(['show']);
```

### Notification logic — 3 controllers updated

Replace the current single-URL check in each controller with an iteration over the relation:

```php
// Pattern used in all three:
$user->pushcutUrls
    ->filter(fn($dest) => match ($status) {
        'COMPLETED' => in_array($dest->notify, ['all', 'paid']),
        'PENDING'   => in_array($dest->notify, ['all', 'created']),
        default     => false,
    })
    ->each(fn($dest) => $this->pushcut->send($dest->url, $title, $data));
```

**PaymentController** (`app/Http/Controllers/PaymentController.php`):
- Status is always `PENDING` on create → filter `notify` in `['all', 'created']`
- Eager-load `pushcutUrls` is not needed here (single user already resolved)

**WebhookController** (`app/Http/Controllers/WebhookController.php`):
- Only fires on `COMPLETED`
- Load relation via `$user->pushcutUrls`

**CartpandaWebhookController** (`app/Http/Controllers/CartpandaWebhookController.php`):
- Replace `$user->pushcut_url` / `$user->pushcut_notify` with the pattern above
- Also fix `STATUS_MAP`: replace `'order.pending' => 'PENDING'` with `'order.created' => 'PENDING'`

### AdminUserController — changes

- Remove `pushcut_url` and `pushcut_notify` from `index()` response map
- Remove from `store()` validation and User::create payload
- Remove from `update()` validation and `$user->update($data)` payload

### AuthController — changes

- Remove `pushcut_url` from `register()` validation and User::create payload
- Remove `pushcut_url` / `pushcut_notify` from `update()` validation
- Remove from `formatUser()` return shape and PHPDoc

---

## 3. Frontend

### `src/api/client.ts`

**Types — remove:**
- `pushcut_url` and `pushcut_notify` from `LoginResponse.user`
- `pushcut_url` and `pushcut_notify` from `CreateUserPayload`
- Regenerate `UpdateUserPayload` (it's a `Partial<Omit<CreateUserPayload,...>>` so it follows automatically)

**Add new type:**
```ts
export interface UserPushcutUrl {
  id: number;
  url: string;
  notify: 'all' | 'created' | 'paid';
  label: string | null;
  created_at: string;
}
export interface UserPushcutUrlsResponse { data: UserPushcutUrl[]; }
export interface CreatePushcutUrlPayload { url: string; notify: 'all' | 'created' | 'paid'; label?: string; }
export type UpdatePushcutUrlPayload = Partial<CreatePushcutUrlPayload>;
```

**Add new api methods:**
```ts
pushcutUrls: () => request<UserPushcutUrlsResponse>('/api/pushcut-urls'),
createPushcutUrl: (data: CreatePushcutUrlPayload) =>
  request<{ data: UserPushcutUrl }>('/api/pushcut-urls', { method: 'POST', body: JSON.stringify(data) }),
updatePushcutUrl: (id: number, data: UpdatePushcutUrlPayload) =>
  request<{ data: UserPushcutUrl }>(`/api/pushcut-urls/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
deletePushcutUrl: (id: number) =>
  request<void>(`/api/pushcut-urls/${id}`, { method: 'DELETE' }),
```

**Remove:**
- `updateSettings` method — `AuthController.update()` will have no remaining fields after the pushcut removal, so both the API method and the backend endpoint (`AuthController::update`) are deleted entirely

### `src/pages/Settings.tsx`

The `notifications` tab is fully replaced. The new layout uses the existing design tokens and patterns.

**Layout** — two cards side by side (`flex gap-4`) at medium+ screens, stacked on mobile:

**Card 1 — "Adicionar URL"** (left, `max-w-sm`):
- Input: URL (`type="url"`, placeholder `https://api.pushcut.io/...`)
- Input: Label (`type="text"`, placeholder `iPhone`, opcional)
- Toggle: Notify — same 3-option button group from the current design (`all`/`created`/`paid`)
- Button: "Adicionar" (brand color, `bg-brand`)
- On submit: calls `api.createPushcutUrl()`, refreshes list

**Card 2 — "URLs cadastradas"** (right, `flex-1`):
- Renders list of `UserPushcutUrl` items
- Each item shows:
  - Label (or URL truncada com `truncate`) em `text-white`
  - URL abaixo em `text-xs text-white/40 truncate`
  - Badge notify: `bg-surface-2 text-white/60 text-xs rounded px-1.5 py-0.5`
  - Botão remover (ícone ×, `text-white/30 hover:text-white/60`)
- Estado vazio: `text-sm text-white/20 "Nenhuma URL cadastrada."`
- Loading state coerente com padrão existente

**State management** — `useState<UserPushcutUrl[]>`, loaded via `api.pushcutUrls()` no `useEffect`. Optimistic updates não são necessários — re-fetch após mutação.

**Remove** the old single-form state (`pushcutUrl`, `pushcutNotify`, `handleSubmit` + `api.updateSettings` call).

---

## 4. Tests

### `tests/Feature/Cartpanda/CartpandaWebhookTest.php`

- Replace factory calls `create(['pushcut_url' => ..., 'pushcut_notify' => ...])` with `UserPushcutUrl::factory()->for($user)->create(['url' => ..., 'notify' => ...])`
- Add test: `test_order_created_creates_pending_record` — mirrors the removed `test_order_pending_creates_pending_record` but uses event `order.created`
- Update `test_order_pending_creates_pending_record` — either rename to use `order.created`, or keep and assert `order.pending` returns `ok: true` without creating a record (since it's no longer in STATUS_MAP)

### `tests/Feature/Auth/AuthTest.php`

- Remove assertions on `pushcut_url` / `pushcut_notify` from the `update` test
- Add new feature test file `tests/Feature/PushcutUrl/PushcutUrlTest.php` covering:
  - CRUD happy paths (index, store, update, destroy)
  - Authorization: user cannot delete another user's URL (403)
  - Validation errors on store/update

---

## 5. Side-effect checklist

All files touched, in implementation order:

| # | File | Change |
|---|------|--------|
| 1 | `database/migrations/YYYY_MM_DD_create_user_pushcut_urls_table.php` | New migration (create + data migrate + dropColumn) |
| 2 | `app/Models/UserPushcutUrl.php` | New model |
| 3 | `database/factories/UserPushcutUrlFactory.php` | New factory |
| 4 | `app/Models/User.php` | Remove fillable fields, add relation |
| 5 | `app/Http/Controllers/PushcutUrlController.php` | New controller |
| 6 | `routes/api.php` | Add resource route |
| 7 | `app/Http/Controllers/PaymentController.php` | Replace pushcut notify logic |
| 8 | `app/Http/Controllers/WebhookController.php` | Replace pushcut notify logic |
| 9 | `app/Http/Controllers/CartpandaWebhookController.php` | Replace pushcut + fix STATUS_MAP |
| 10 | `app/Http/Controllers/AdminUserController.php` | Remove pushcut fields |
| 11 | `app/Http/Controllers/AuthController.php` | Remove pushcut fields; delete `update()` method (no remaining fields) |
| 11b | `routes/api.php` | Remove the route pointing to `AuthController::update` |
| 12 | `tests/Feature/Cartpanda/CartpandaWebhookTest.php` | Update pushcut factory calls + order.created test |
| 13 | `tests/Feature/Auth/AuthTest.php` | Remove pushcut assertions |
| 14 | `tests/Feature/PushcutUrl/PushcutUrlTest.php` | New CRUD tests |
| 15 | `src/api/client.ts` | Remove old types/methods, add new |
| 16 | `src/pages/Settings.tsx` | Replace notifications tab |
