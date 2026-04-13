# Checkout Preview & Change Requests — Design Spec

**Date:** 2026-04-13
**Status:** Approved

---

## Overview

Each user gets a personalized checkout HTML page uploaded by the admin. Users can preview their checkout in a new browser tab and submit change requests to the admin. Admins manage uploads and track change request status.

---

## Scope

- Admin uploads/replaces/removes an HTML file per user (stored inside the Laravel app)
- Authenticated users can open their checkout preview in a new tab
- Users submit free-text change requests; admins manage them with a status (pending/done)
- New admin dashboard page lists all change requests

Out of scope: versioning of uploads, email/Pushcut notifications for requests, user comments on requests.

---

## Database Schema

### `checkout_previews`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK → users | unique (one preview per user) |
| `file_path` | string | relative path inside storage |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `checkout_change_requests`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | bigint FK → users | |
| `message` | text | max 2000 chars |
| `status` | enum: `pending`, `done` | default `pending` |
| `created_at` | timestamp | |

No `updated_at` on `checkout_change_requests` (only status changes matter).

---

## Backend (Laravel 13 — `hub-laravel/hub-laravel/`)

### File Storage

HTML files are stored at:
```
storage/app/private/checkout-previews/{user_id}.html
```
Served via Laravel (never directly accessible via web server). Max size: 2 MB. MIME must be `text/html`.

### Models

**`CheckoutPreview`**
- `#[Fillable(['user_id', 'file_path'])]`
- `belongsTo(User::class)`

**`CheckoutChangeRequest`**
- `#[Fillable(['user_id', 'message', 'status'])]`
- `UPDATED_AT = null`
- `belongsTo(User::class)`
- Status is a plain string column (`pending` | `done`). No PHP enum — project uses string constants following the `Transaction::TERMINAL_STATUSES` pattern:
  ```php
  public const STATUSES = ['pending', 'done'];
  ```

### Routes

```
# Public (no auth)
GET  /checkout-preview/{user}     → serve HTML for user (token-based, see below)

# Authenticated (auth:sanctum)
GET  /checkout-preview/token      → get signed preview URL for own checkout
POST /checkout-change-requests    → submit change request
GET  /checkout-change-requests    → list own requests

# Admin (auth:sanctum + AdminMiddleware)
POST   admin/users/{user}/checkout-preview    → upload HTML
DELETE admin/users/{user}/checkout-preview    → remove HTML
GET    admin/checkout-change-requests         → list all requests (paginated)
PATCH  admin/checkout-change-requests/{id}    → update status
```

> **Preview URL approach:** The preview is served via a signed URL (`URL::temporarySignedRoute`, 1 hour TTL). The user fetches a token from `/checkout-preview/token`, then opens the signed URL in a new tab. This avoids session/cookie issues when opening a new tab and works cleanly without embedding auth headers.

### Controllers

**`CheckoutPreviewController`** (user-facing)
- `token()` — generates and returns a 1-hour signed URL for the authenticated user's preview
- `show(User $user, Request $request)` — validates signature, streams HTML file with `Content-Type: text/html`; returns 404 if no record or file missing

**`AdminCheckoutPreviewController`** (admin)
- `store(Request $request, User $user)` — validates file (mime: `text/html`, max: 2048 KB), stores to `storage/app/private/checkout-previews/{user->id}.html`, upserts `CheckoutPreview`
- `destroy(User $user)` — deletes file from disk + deletes DB record

**`CheckoutChangeRequestController`** (user-facing)
- `index()` — paginated list of authenticated user's own requests
- `store(Request $request)` — validates `message` (required, string, max:2000), creates record

**`AdminCheckoutChangeRequestController`** (admin)
- `index()` — all requests with `user:id,email`, ordered by `created_at desc`. Uses manual offset/limit pagination (matching `AdminUserController` pattern: `$page`, `$perPage = 20`, returns `data` + `meta` array).
- `update(Request $request, CheckoutChangeRequest $changeRequest)` — validates `status` (`in:pending,done`), updates record

### Error Handling

| Scenario | Response |
|---|---|
| No preview uploaded | `token()` returns `{'has_preview': false}` |
| File missing from disk (stale record) | `show()` returns 404 |
| Upload wrong MIME | 422 with validation error |
| Upload too large | 422 with validation error |
| Signed URL expired/invalid | 403 |
| User tries to access another user's preview URL | 403 (signature mismatch) |

---

## Frontend (React — `dashboard/`)

### New Pages

**`src/pages/CheckoutPreview.tsx`** (user route `/checkout`)
- Fetches `GET /checkout-preview/token` on mount
- If `has_preview: false` → shows empty state: "Nenhum checkout configurado. Aguarda que o administrador faça o upload."
- If preview exists → shows "Ver Preview" button (opens signed URL in new tab)
- Below: change request form (textarea, max 2000 chars + submit button)
- Below form: list of own past requests with status badge (pending = amber, done = green) and date

**`src/pages/admin/CheckoutChangeRequests.tsx`** (admin route `/admin/checkout-requests`)
- Table: columns = User email, Message (truncated to 80 chars, expandable), Status, Date
- Status toggle button: "Marcar como feito" / "Reabrir" — calls `PATCH` inline
- Paginated

### API client additions (`src/api/client.ts`)

All methods are added to the exported `api` object. Interfaces are declared at module level before `export const api = { ... }`.

```ts
// Interfaces (module level)
export interface CheckoutPreviewToken {
  has_preview: boolean;
  url?: string;
}
export interface CheckoutChangeRequest {
  id: number;
  message: string;
  status: 'pending' | 'done';
  created_at: string;
}
export interface AdminCheckoutChangeRequest extends CheckoutChangeRequest {
  user_id: number;
  user_email: string;
}
export interface CheckoutChangeRequestsResponse {
  data: CheckoutChangeRequest[];
  meta: { total: number; page: number; per_page: number; pages: number };
}

// Methods on `api` object
checkoutPreviewToken: () =>
  request<CheckoutPreviewToken>('/api/checkout-preview/token'),

checkoutChangeRequests: (page = 1) =>
  request<CheckoutChangeRequestsResponse>(`/api/checkout-change-requests?page=${page}`),

submitCheckoutChangeRequest: (message: string) =>
  request<{ message: string }>('/api/checkout-change-requests', {
    method: 'POST',
    body: JSON.stringify({ message }),
  }),

// Admin — file upload uses FormData; must NOT set Content-Type (browser sets multipart boundary)
adminUploadCheckoutPreview: (userId: number, file: File) => {
  const form = new FormData();
  form.append('file', file);
  const token = localStorage.getItem('token');
  return fetch(`${HUB_URL}/api/admin/users/${userId}/checkout-preview`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    body: form,
  }).then(async (res) => {
    const data = await res.json();
    if (!res.ok) throw new Error(data.error ?? 'Upload failed');
    return data;
  });
},

adminDeleteCheckoutPreview: (userId: number) =>
  request<{ message: string }>(`/api/admin/users/${userId}/checkout-preview`, {
    method: 'DELETE',
  }),

adminCheckoutChangeRequests: (page = 1) =>
  request<{ data: AdminCheckoutChangeRequest[]; meta: { total: number; page: number; per_page: number; pages: number } }>(
    `/api/admin/checkout-change-requests?page=${page}`
  ),

adminUpdateCheckoutChangeRequest: (id: number, status: 'pending' | 'done') =>
  request<{ message: string }>(`/api/admin/checkout-change-requests/${id}`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  }),
```

> **Note on file upload:** `HUB_URL` is already defined at the top of `client.ts` — reuse it in `adminUploadCheckoutPreview`. Do not set `Content-Type` manually; `FormData` requires the browser to set it with the multipart boundary.

### State management

Pages use `@tanstack/react-query` (`useQuery`, `useMutation`) — matching the existing pattern in `WebhookLogs.tsx` and other pages. Do not use raw `useState/useEffect` for data fetching.

### Dashboard Integration

- **Sidebar** (`Layout.tsx`): add "Checkout" nav item for users
- **Admin sidebar**: add "Pedidos de Alteração" nav item
- **Admin User Modal** (`UserFormModal.tsx`): add "Checkout Preview" section with file input and upload/remove buttons

### Design System

Follows existing tokens: `surface-1`, `surface-2`, `brand`, `zinc-800`. Status badges reuse existing pill patterns. Empty states use `EmptyState` component.

---

## Testing

### Backend (Feature tests)

- Admin can upload HTML → file stored + DB record created/updated
- Admin can delete → file + record removed
- Authenticated user can get signed URL (has_preview true/false)
- Signed URL serves correct HTML with `Content-Type: text/html`
- Expired/invalid signed URL returns 403
- User can submit change request
- User cannot see another user's requests
- Admin can list all change requests
- Admin can update change request status

### Frontend

- Manual: upload → preview opens in new tab with correct HTML
- Manual: submit request → appears in list with pending status
- Manual: admin toggles status → badge updates

---

## File Map

### Backend files to create
```
hub-laravel/hub-laravel/
  app/
    Models/CheckoutPreview.php
    Models/CheckoutChangeRequest.php
    Http/Controllers/CheckoutPreviewController.php
    Http/Controllers/AdminCheckoutPreviewController.php
    Http/Controllers/CheckoutChangeRequestController.php
    Http/Controllers/AdminCheckoutChangeRequestController.php
  database/migrations/
    2026_04_13_000001_create_checkout_previews_table.php
    2026_04_13_000002_create_checkout_change_requests_table.php
  tests/Feature/
    CheckoutPreviewTest.php
    CheckoutChangeRequestTest.php
```

### Frontend files to create/modify
```
dashboard/src/
  pages/CheckoutPreview.tsx               [new]
  pages/admin/CheckoutChangeRequests.tsx  [new]
  api/client.ts                           [modify — add API methods + interfaces]
  components/Layout.tsx                   [modify — add nav items]
  components/UserFormModal.tsx            [modify — add upload section]
  App.tsx                                 [modify — add routes]
```
