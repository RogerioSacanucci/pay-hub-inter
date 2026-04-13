# Checkout Preview & Change Requests — Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add checkout preview page (user views HTML preview, submits change requests) and admin change requests management page to the React dashboard.

**Architecture:** Follows existing patterns — `api` object in `client.ts` for all API calls, `@tanstack/react-query` (`useQuery`/`useMutation`) for data fetching, design system tokens (`surface-1`, `surface-2`, `brand`) for styling, admin pages under `src/pages/admin/`.

**Tech Stack:** React 18, TypeScript, Vite, Tailwind CSS 3, React Router 6, @tanstack/react-query

**Prerequisite:** Backend plan (`2026-04-13-checkout-preview-backend.md`) must be deployed and accessible at `VITE_HUB_URL`.

---

## File Map

**Create:**
- `src/pages/CheckoutPreview.tsx`
- `src/pages/admin/CheckoutChangeRequests.tsx`

**Modify:**
- `src/api/client.ts` — add 4 interfaces + 7 api methods
- `src/App.tsx` — add 2 new routes
- `src/components/Layout.tsx` — add 2 nav items
- `src/components/UserFormModal.tsx` — add checkout preview upload section

---

## Task 1: API Client — Types and Methods

**Files:**
- Modify: `src/api/client.ts`

The existing `client.ts` exports a single `api` object. All new methods go on that object. New interfaces are declared at module level before `export const api = { ... }`.

- [ ] **Step 1: Add interfaces before `export const api`**

Find the line `export const api = {` in `src/api/client.ts` and insert these interfaces immediately before it:

```ts
// Checkout Preview
export interface CheckoutPreviewToken {
  has_preview: boolean;
  url?: string;
}

export interface CheckoutPreviewStatus {
  has_preview: boolean;
}

export interface CheckoutChangeRequest {
  id: number;
  message: string;
  status: 'pending' | 'done';
  created_at: string;
}

export interface AdminCheckoutChangeRequest {
  id: number;
  user_id: number;
  user_email: string;
  message: string;
  status: 'pending' | 'done';
  created_at: string;
}

export interface CheckoutChangeRequestsResponse {
  data: CheckoutChangeRequest[];
  meta: { total: number; page: number; per_page: number; pages: number };
}

export interface AdminCheckoutChangeRequestsResponse {
  data: AdminCheckoutChangeRequest[];
  meta: { total: number; page: number; per_page: number; pages: number };
}
```

- [ ] **Step 2: Add methods to the `api` object**

At the end of the `api` object (before the closing `};`), add:

```ts
  // Checkout Preview — user
  checkoutPreviewToken: () =>
    request<CheckoutPreviewToken>('/api/checkout-preview/token'),

  checkoutChangeRequests: (page = 1) =>
    request<CheckoutChangeRequestsResponse>(`/api/checkout-change-requests?page=${page}`),

  submitCheckoutChangeRequest: (message: string) =>
    request<{ data: CheckoutChangeRequest }>('/api/checkout-change-requests', {
      method: 'POST',
      body: JSON.stringify({ message }),
    }),

  // Checkout Preview — admin
  adminCheckoutPreviewStatus: (userId: number) =>
    request<CheckoutPreviewStatus>(`/api/admin/users/${userId}/checkout-preview`),

  adminUploadCheckoutPreview: (userId: number, file: File): Promise<{ message: string }> => {
    const form = new FormData();
    form.append('file', file);
    const token = localStorage.getItem('token');
    return fetch(`${HUB_URL}/api/admin/users/${userId}/checkout-preview`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      body: form,
    }).then(async (res) => {
      const data = await res.json();
      if (!res.ok) { throw new Error(data.message ?? data.error ?? 'Upload failed'); }
      return data;
    });
  },

  adminDeleteCheckoutPreview: (userId: number) =>
    request<{ message: string }>(`/api/admin/users/${userId}/checkout-preview`, {
      method: 'DELETE',
    }),

  adminCheckoutChangeRequests: (page = 1) =>
    request<AdminCheckoutChangeRequestsResponse>(`/api/admin/checkout-change-requests?page=${page}`),

  adminUpdateCheckoutChangeRequest: (id: number, status: 'pending' | 'done') =>
    request<{ data: { id: number; status: 'pending' | 'done' } }>(`/api/admin/checkout-change-requests/${id}`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    }),
```

> **Note on `adminUploadCheckoutPreview`:** Uses `fetch` directly (not the `request` helper) because `FormData` upload must NOT have `Content-Type: application/json`. The browser sets `multipart/form-data` with the correct boundary automatically. `HUB_URL` is already defined at the top of the file — no need to redefine it.

- [ ] **Step 3: Verify TypeScript compiles**

```bash
npm run build 2>&1 | head -30
```

Expected: no type errors related to the new code.

- [ ] **Step 4: Commit**

```bash
git add src/api/client.ts
git commit -m "feat: checkout preview api client types and methods"
```

---

## Task 2: User Checkout Preview Page

**Files:**
- Create: `src/pages/CheckoutPreview.tsx`

- [ ] **Step 1: Create the page**

Create `src/pages/CheckoutPreview.tsx`:

```tsx
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, CheckoutChangeRequest } from '../api/client';
import { EmptyState, EmptyIcons } from '../components/ui/EmptyState';

const textareaClass =
  'w-full bg-surface-2 border border-white/[0.08] rounded-xl px-4 py-3 text-sm text-white placeholder:text-white/20 outline-none focus:border-brand/50 focus:ring-1 focus:ring-brand/30 transition-colors resize-none';

export default function CheckoutPreview() {
  const queryClient = useQueryClient();
  const [message, setMessage] = useState('');
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitted, setSubmitted] = useState(false);

  const { data: tokenData, isLoading: tokenLoading } = useQuery({
    queryKey: ['checkout-preview-token'],
    queryFn: () => api.checkoutPreviewToken(),
  });

  const { data: requestsData, isLoading: requestsLoading } = useQuery({
    queryKey: ['checkout-change-requests'],
    queryFn: () => api.checkoutChangeRequests(),
  });

  const mutation = useMutation({
    mutationFn: (msg: string) => api.submitCheckoutChangeRequest(msg),
    onSuccess: () => {
      setMessage('');
      setSubmitted(true);
      setTimeout(() => setSubmitted(false), 3000);
      queryClient.invalidateQueries({ queryKey: ['checkout-change-requests'] });
    },
    onError: (err: Error) => {
      setSubmitError(err.message);
    },
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitError(null);
    mutation.mutate(message);
  }

  if (tokenLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-zinc-400 text-sm">A carregar...</div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-8 max-w-2xl">
      <div>
        <h1 className="text-xl font-bold text-white mb-1">Checkout Preview</h1>
        <p className="text-sm text-white/40">Visualiza o teu checkout e solicita alterações.</p>
      </div>

      {/* Preview section */}
      <div className="bg-surface-1 rounded-xl border border-zinc-800 p-5 flex flex-col gap-3">
        <h2 className="text-xs font-semibold text-white/40 uppercase tracking-widest">Preview</h2>
        {tokenData?.has_preview ? (
          <div>
            <a
              href={tokenData.url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 bg-brand hover:bg-brand-hover text-white text-sm font-semibold py-2.5 px-5 rounded-xl transition-colors"
            >
              Ver Preview
            </a>
            <p className="text-xs text-white/30 mt-2">O link expira em 1 hora. Recarrega a página para obter um novo.</p>
          </div>
        ) : (
          <EmptyState
            icon={EmptyIcons.link}
            message="Sem preview configurado"
            hint="Aguarda que o administrador faça o upload do teu checkout."
          />
        )}
      </div>

      {/* Change request form */}
      <div className="bg-surface-1 rounded-xl border border-zinc-800 p-5 flex flex-col gap-4">
        <h2 className="text-xs font-semibold text-white/40 uppercase tracking-widest">Solicitar Alteração</h2>
        <form onSubmit={handleSubmit} className="flex flex-col gap-3">
          <textarea
            value={message}
            onChange={(e) => {
              setMessage(e.target.value);
              setSubmitError(null);
            }}
            maxLength={2000}
            rows={4}
            placeholder="Descreve as alterações que pretendes..."
            className={textareaClass}
          />
          <div className="flex items-center justify-between gap-3">
            <span className="text-xs text-white/25">{message.length}/2000</span>
            <button
              type="submit"
              disabled={mutation.isPending || !message.trim()}
              className="px-5 py-2.5 bg-brand hover:bg-brand-hover disabled:opacity-50 text-white text-sm font-semibold rounded-xl transition-colors"
            >
              {mutation.isPending ? 'A enviar...' : 'Enviar pedido'}
            </button>
          </div>
          {submitError && (
            <p className="text-sm text-red-400">{submitError}</p>
          )}
          {submitted && (
            <p className="text-sm text-emerald-400">Pedido enviado com sucesso!</p>
          )}
        </form>
      </div>

      {/* Request history */}
      <div className="flex flex-col gap-3">
        <h2 className="text-xs font-semibold text-white/40 uppercase tracking-widest">Histórico de pedidos</h2>

        {requestsLoading && (
          <div className="text-sm text-white/40">A carregar...</div>
        )}

        {!requestsLoading && (requestsData?.data?.length ?? 0) === 0 && (
          <p className="text-sm text-white/30">Nenhum pedido submetido ainda.</p>
        )}

        <div className="flex flex-col gap-3">
          {requestsData?.data?.map((req: CheckoutChangeRequest) => (
            <div
              key={req.id}
              className="bg-surface-1 rounded-xl border border-zinc-800 p-4 flex flex-col gap-2"
            >
              <div className="flex items-center justify-between">
                <span
                  className={`text-xs font-medium px-2 py-0.5 rounded-full ${
                    req.status === 'done'
                      ? 'bg-emerald-500/10 text-emerald-400'
                      : 'bg-amber-500/10 text-amber-400'
                  }`}
                >
                  {req.status === 'done' ? 'Concluído' : 'Pendente'}
                </span>
                <span className="text-xs text-white/30">
                  {new Date(req.created_at).toLocaleDateString('pt-PT')}
                </span>
              </div>
              <p className="text-sm text-white/70 whitespace-pre-wrap">{req.message}</p>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
npm run build 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/pages/CheckoutPreview.tsx
git commit -m "feat: checkout preview user page"
```

---

## Task 3: Admin Change Requests Page

**Files:**
- Create: `src/pages/admin/CheckoutChangeRequests.tsx`

- [ ] **Step 1: Create the page**

Create `src/pages/admin/CheckoutChangeRequests.tsx`:

```tsx
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api, AdminCheckoutChangeRequest } from '../../api/client';

function StatusBadge({ status }: { status: 'pending' | 'done' }) {
  return (
    <span
      className={`text-xs font-medium px-2 py-0.5 rounded-full ${
        status === 'done'
          ? 'bg-emerald-500/10 text-emerald-400'
          : 'bg-amber-500/10 text-amber-400'
      }`}
    >
      {status === 'done' ? 'Concluído' : 'Pendente'}
    </span>
  );
}

function ExpandableMessage({ message, id }: { message: string; id: number }) {
  const [expanded, setExpanded] = useState(false);
  const isLong = message.length > 80;

  return (
    <div className="text-sm text-white/70 max-w-xs">
      <span className={!expanded && isLong ? 'line-clamp-2' : ''}>{message}</span>
      {isLong && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          className="block text-xs text-brand hover:text-brand-hover mt-1"
        >
          {expanded ? 'Ver menos' : 'Ver mais'}
        </button>
      )}
    </div>
  );
}

export default function CheckoutChangeRequests() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-checkout-change-requests', page],
    queryFn: () => api.adminCheckoutChangeRequests(page),
  });

  const mutation = useMutation({
    mutationFn: ({ id, status }: { id: number; status: 'pending' | 'done' }) =>
      api.adminUpdateCheckoutChangeRequest(id, status),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-checkout-change-requests'] });
    },
  });

  const requests = data?.data ?? [];
  const meta = data?.meta;

  return (
    <div className="p-6 space-y-6">
      <h1 className="text-xl font-bold text-white">Pedidos de Alteração</h1>

      {isLoading && (
        <div className="text-sm text-white/40">A carregar...</div>
      )}

      {!isLoading && requests.length === 0 && (
        <p className="text-sm text-white/30">Nenhum pedido submetido ainda.</p>
      )}

      {requests.length > 0 && (
        <div className="bg-surface-1 rounded-xl border border-zinc-800 overflow-hidden">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-zinc-800">
                <th className="text-left px-4 py-3 text-xs font-semibold text-white/40 uppercase tracking-widest">
                  Utilizador
                </th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-white/40 uppercase tracking-widest">
                  Mensagem
                </th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-white/40 uppercase tracking-widest">
                  Data
                </th>
                <th className="text-left px-4 py-3 text-xs font-semibold text-white/40 uppercase tracking-widest">
                  Estado
                </th>
              </tr>
            </thead>
            <tbody>
              {requests.map((req: AdminCheckoutChangeRequest) => (
                <tr key={req.id} className="border-b border-zinc-800/50 last:border-0">
                  <td className="px-4 py-3 text-white/70 text-xs">{req.user_email}</td>
                  <td className="px-4 py-3">
                    <ExpandableMessage message={req.message} id={req.id} />
                  </td>
                  <td className="px-4 py-3 text-white/40 whitespace-nowrap text-xs">
                    {new Date(req.created_at).toLocaleDateString('pt-PT')}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2 flex-wrap">
                      <StatusBadge status={req.status} />
                      <button
                        type="button"
                        disabled={mutation.isPending}
                        onClick={() =>
                          mutation.mutate({
                            id: req.id,
                            status: req.status === 'done' ? 'pending' : 'done',
                          })
                        }
                        className="text-xs text-white/40 hover:text-white/70 transition-colors disabled:opacity-50 underline underline-offset-2"
                      >
                        {req.status === 'done' ? 'Reabrir' : 'Marcar como feito'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.pages > 1 && (
        <div className="flex items-center gap-3">
          <button
            type="button"
            disabled={page === 1}
            onClick={() => setPage((p) => p - 1)}
            className="px-3 py-1.5 text-xs bg-surface-2 border border-zinc-800 rounded-lg text-white/60 hover:text-white disabled:opacity-30 transition-colors"
          >
            Anterior
          </button>
          <span className="text-xs text-white/40">
            {page} / {meta.pages}
          </span>
          <button
            type="button"
            disabled={page === meta.pages}
            onClick={() => setPage((p) => p + 1)}
            className="px-3 py-1.5 text-xs bg-surface-2 border border-zinc-800 rounded-lg text-white/60 hover:text-white disabled:opacity-30 transition-colors"
          >
            Próximo
          </button>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
npm run build 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add src/pages/admin/CheckoutChangeRequests.tsx
git commit -m "feat: admin checkout change requests page"
```

---

## Task 4: Routing and Navigation

**Files:**
- Modify: `src/App.tsx`
- Modify: `src/components/Layout.tsx`

- [ ] **Step 1: Add routes to `src/App.tsx`**

Add the import at the top alongside existing page imports:

```ts
import CheckoutPreview from './pages/CheckoutPreview';
import CheckoutChangeRequests from './pages/admin/CheckoutChangeRequests';
```

Inside the `<Route path="/" element={...}>` block (alongside existing routes like `<Route path="links" ...>`), add:

```tsx
<Route path="checkout" element={<CheckoutPreview />} />
<Route path="admin/checkout-requests" element={<AdminGuard><CheckoutChangeRequests /></AdminGuard>} />
```

- [ ] **Step 2: Add nav items to `src/components/Layout.tsx`**

The Layout uses `LordIcon`/`Player` icons from imported JSON files. Use existing `scrollTextIcon` for change requests (admin) and `shoppingCartIcon` for checkout (user).

In the `Ferramentas` section (where `Links` lives), add the checkout item for all users after the Links nav item:

```tsx
<NavItem to="/checkout" label="Checkout" end={false} icon={shoppingCartIcon} onClick={closeSidebar} />
```

In the admin section (inside `{isAdmin && (...)}` within the Internacional block, or create a new admin block), add:

```tsx
{isAdmin && (
  <NavItem to="/admin/checkout-requests" label="Pedidos de Alteração" end={false} icon={scrollTextIcon} onClick={closeSidebar} />
)}
```

> **Where to add the admin item:** The admin checkout-requests item belongs near other admin-only items. Add it inside the Internacional block where `Webhook Logs` and `E-mail Service` live, or create a new section if it feels cleaner. Follow the existing grouping logic.

- [ ] **Step 3: Verify TypeScript compiles**

```bash
npm run build 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/App.tsx src/components/Layout.tsx
git commit -m "feat: add checkout routes and nav items"
```

---

## Task 5: Admin User Modal — Checkout Upload Section

**Files:**
- Modify: `src/components/UserFormModal.tsx`

This task adds a "Checkout Preview" section to the existing user edit modal. It should only appear when editing an existing user (`isEdit === true`), not when creating a new one (no `user.id` yet).

- [ ] **Step 1: Add state and query for checkout preview status**

In `UserFormModal.tsx`, add the following state variables alongside existing state at the top of the component:

```ts
const [checkoutFile, setCheckoutFile] = useState<File | null>(null);
const [checkoutUploading, setCheckoutUploading] = useState(false);
const [checkoutError, setCheckoutError] = useState<string | null>(null);
const [checkoutSuccess, setCheckoutSuccess] = useState(false);
```

Add a query to fetch preview status (only when editing):

```ts
const { data: previewStatus, refetch: refetchPreviewStatus } = useQuery({
  queryKey: ['admin-checkout-preview-status', user?.id],
  queryFn: () => api.adminCheckoutPreviewStatus(user!.id),
  enabled: isEdit && user !== null,
});

const hasPreview = previewStatus?.has_preview ?? false;
```

- [ ] **Step 2: Add upload handler**

Add this function in the component body (alongside `handleAttachShop`):

```ts
async function handleUploadPreview() {
  if (!checkoutFile || !user) return;
  setCheckoutUploading(true);
  setCheckoutError(null);
  try {
    await api.adminUploadCheckoutPreview(user.id, checkoutFile);
    setCheckoutFile(null);
    setCheckoutSuccess(true);
    setTimeout(() => setCheckoutSuccess(false), 3000);
    refetchPreviewStatus();
  } catch (err) {
    setCheckoutError(err instanceof Error ? err.message : 'Erro ao fazer upload.');
  } finally {
    setCheckoutUploading(false);
  }
}

async function handleDeletePreview() {
  if (!user) return;
  setCheckoutUploading(true);
  setCheckoutError(null);
  try {
    await api.adminDeleteCheckoutPreview(user.id);
    refetchPreviewStatus();
  } catch (err) {
    setCheckoutError(err instanceof Error ? err.message : 'Erro ao remover preview.');
  } finally {
    setCheckoutUploading(false);
  }
}
```

- [ ] **Step 3: Add the UI section to the form**

Inside the `<form>` in the JSX, after the "Lojas Internacional" section (the block that ends with `</div>` around the shop select), add:

```tsx
{isEdit && (
  <div className="border-t border-white/[0.06] pt-4 flex flex-col gap-3">
    <label className="text-xs font-semibold text-white/40 uppercase tracking-widest">
      Checkout Preview
    </label>

    {hasPreview && (
      <div className="flex items-center justify-between bg-surface-2 border border-white/[0.06] rounded-xl px-4 py-2.5">
        <span className="text-xs text-emerald-400 font-medium">Preview configurado</span>
        <button
          type="button"
          disabled={checkoutUploading}
          onClick={handleDeletePreview}
          className="text-xs text-red-400 hover:text-red-300 transition-colors disabled:opacity-50"
        >
          Remover
        </button>
      </div>
    )}

    <div className="flex gap-2">
      <input
        type="file"
        accept=".html,.htm"
        onChange={(e) => {
          setCheckoutFile(e.target.files?.[0] ?? null);
          setCheckoutError(null);
        }}
        className="flex-1 text-xs text-white/60 bg-surface-2 border border-white/[0.08] rounded-xl px-4 py-2.5 file:mr-3 file:text-xs file:font-medium file:bg-brand file:text-white file:border-0 file:rounded-lg file:px-3 file:py-1 file:cursor-pointer cursor-pointer"
      />
      <button
        type="button"
        disabled={!checkoutFile || checkoutUploading}
        onClick={handleUploadPreview}
        className="px-4 py-2.5 bg-brand hover:bg-brand-hover disabled:opacity-50 text-white text-xs font-semibold rounded-xl transition-colors whitespace-nowrap"
      >
        {checkoutUploading ? 'A enviar...' : hasPreview ? 'Substituir' : 'Upload'}
      </button>
    </div>

    {checkoutError && (
      <p className="text-xs text-red-400">{checkoutError}</p>
    )}
    {checkoutSuccess && (
      <p className="text-xs text-emerald-400">Upload realizado com sucesso!</p>
    )}
  </div>
)}
```

- [ ] **Step 4: Verify TypeScript compiles**

```bash
npm run build 2>&1 | head -30
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/components/UserFormModal.tsx
git commit -m "feat: admin user modal checkout preview upload section"
```

---

## Task 6: Manual Verification

- [ ] **Step 1: Start the dev server**

```bash
npm run dev
```

- [ ] **Step 2: Test user checkout preview flow**

1. Log in as an admin → open user modal for a test user → upload an HTML file in the "Checkout Preview" section → confirm "Preview configurado" badge appears.
2. Log in as that test user → navigate to `/checkout` → confirm "Ver Preview" button appears.
3. Click "Ver Preview" → new tab opens with the HTML content rendered correctly.
4. Submit a change request → appears in history with "Pendente" badge.

- [ ] **Step 3: Test admin change requests flow**

1. Log in as admin → navigate to `/admin/checkout-requests`.
2. Confirm the request submitted by the user appears with their email.
3. Click "Marcar como feito" → badge changes to "Concluído".
4. Click "Reabrir" → badge reverts to "Pendente".

- [ ] **Step 4: Test empty states**

1. Log in as a user with no preview uploaded → `/checkout` shows the empty state message.
2. Admin change requests page with no requests shows "Nenhum pedido submetido ainda."

- [ ] **Step 5: Build for production**

```bash
npm run build
```

Expected: clean build, no TypeScript errors.
