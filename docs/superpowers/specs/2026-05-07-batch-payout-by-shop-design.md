# Spec: Saque em Lote por Loja

**Data:** 2026-05-07
**Status:** Aprovado

---

## Objetivo

Hoje admin paga 1 usuário por vez (`AdminPayoutController@store`: usuário → saque → valor, com `shop_id` como tag). Novo fluxo inverte o ponto de partida: admin escolhe a **loja**, vê todos os usuários com saldo released > 0 nessa loja, marca quem quer pagar, edita valores e dispara um único request que processa cada linha em best-effort, gravando 1 PayoutLog por usuário com um `batch_id` compartilhado.

---

## Decisões já validadas

| Tópico | Decisão |
|---|---|
| Origem do valor | Saldo released **da loja** (mesma fórmula de `AdminCartpandaShopController@show` e `AdminPayoutController@show`); editável até esse limite |
| Atomicidade | Best-effort — cada linha em try/catch isolado; resposta tem `success[]` e `failures[]` |
| Lista | Apenas users com `balance_released_shop > 0`; default **nenhum selecionado**; admin marca um por um |
| Nota | Nota compartilhada do lote + override opcional por linha |
| UI | Botão "Saque em lote" por linha em `pages/admin/CartpandaShops.tsx` → abre modal |
| Auditoria | `payout_logs.batch_id` (uuid, nullable) + index para listar/filtrar lotes |

---

## Backend

### Migration

`database/migrations/2026_05_07_XXXXXX_add_batch_id_to_payout_logs_table.php`

```php
Schema::table('payout_logs', function (Blueprint $table) {
    $table->uuid('batch_id')->nullable()->after('shop_id');
    $table->index('batch_id', 'idx_payout_logs_batch_id');
});
```

Down: drop índice + coluna.

### Modelo

`PayoutLog`:
- Adicionar `'batch_id'` em `#[Fillable([...])]`.

### Factory

`PayoutLogFactory`:
- Adicionar state `forBatch(string $batchId): static`.

### Service

`BalanceService::payout()` ganha 7º parâmetro opcional:

```php
public function payout(
    User $user,
    User $admin,
    float $amount,
    string $type,
    ?string $note,
    ?int $shopId = null,
    ?string $batchId = null,
): PayoutLog
```

Adiciona `'batch_id' => $batchId` no `PayoutLog::create([...])`. Comportamento de débito inalterado. Backwards compatible com chamadas existentes (`AdminPayoutController@store`, testes).

### Controller novo

`app/Http/Controllers/AdminShopBatchPayoutController.php` — duas responsabilidades distintas mas escopo coeso (mesmo recurso "batch payout de loja"). Aceitável; alternativa seria 2 controllers separados (`...EligibleUsers` e `...BatchPayout`). Decisão: 1 controller com 2 métodos.

#### `GET /api/admin/internacional-shops/{shop}/eligible-users`

```php
public function eligibleUsers(CartpandaShop $shop): JsonResponse
```

Reusa a subquery exata de `AdminCartpandaShopController@show` (linhas 192–226), filtrando `balance_released > 0`:

```sql
SELECT u.id, u.email, u.payer_name,
       COALESCE(orders.released_from_orders, 0) + COALESCE(payouts.total_payouts, 0) AS balance_released_shop
FROM users u
INNER JOIN cartpanda_shop_user su ON su.user_id = u.id AND su.shop_id = ?
LEFT JOIN (
  SELECT user_id,
    SUM(CASE WHEN status='COMPLETED' AND released_at IS NOT NULL THEN amount*0.95 ELSE 0 END)
    - SUM(CASE WHEN status='DECLINED' THEN COALESCE(chargeback_penalty,0) ELSE 0 END) AS released_from_orders
  FROM cartpanda_orders WHERE shop_id=? AND status IN ('COMPLETED','DECLINED') GROUP BY user_id
) orders ON orders.user_id = u.id
LEFT JOIN (
  SELECT user_id, SUM(amount) AS total_payouts
  FROM payout_logs WHERE shop_id=? GROUP BY user_id
) payouts ON payouts.user_id = u.id
HAVING balance_released_shop > 0
ORDER BY balance_released_shop DESC
```

Response:
```json
{
  "data": [
    { "user_id": 1, "name": "...", "email": "...", "balance_released_shop": 1234.56 }
  ]
}
```

Como `AdminCartpandaShopController@show` já considera apenas usuários com `cartpanda_shop_user` (assignments) e essa query também usa INNER JOIN em `cartpanda_shop_user`, o resultado fica consistente com o que o admin vê hoje.

#### `POST /api/admin/internacional-shops/{shop}/batch-payout`

```php
public function batchPayout(Request $request, CartpandaShop $shop): JsonResponse
```

Validação:
```php
$data = $request->validate([
    'note' => ['nullable', 'string', 'max:500'],
    'items' => ['required', 'array', 'min:1', 'max:100'],
    'items.*.user_id' => ['required', 'integer', 'exists:users,id'],
    'items.*.amount' => ['required', 'numeric', 'min:0.01'],
    'items.*.note' => ['nullable', 'string', 'max:500'],
]);
```

Validação extra (não no `validate`, fica no método pra produzir failure por linha):
- Cada `user_id` deve estar atribuído à loja (`cartpanda_shop_user`). Se não, vai pra `failures` com `error: "user_not_assigned_to_shop"`.

Fluxo:
```php
$batchId = (string) Str::uuid();
$success = [];
$failures = [];

foreach ($data['items'] as $item) {
    try {
        $user = User::findOrFail($item['user_id']);
        // valida assignment
        if (! $shop->users()->where('users.id', $user->id)->exists()) {
            throw new \DomainException('user_not_assigned_to_shop');
        }
        $log = $this->balanceService->payout(
            $user,
            $request->user(),
            (float) $item['amount'],
            'withdrawal',
            $item['note'] ?? $data['note'] ?? null,
            $shop->id,
            $batchId,
        );
        $success[] = [
            'user_id' => $user->id,
            'payout_log_id' => $log->id,
            'amount' => (float) $item['amount'],
        ];
    } catch (\Throwable $e) {
        report($e);
        $failures[] = [
            'user_id' => $item['user_id'],
            'error' => $e instanceof \DomainException ? $e->getMessage() : 'unexpected_error',
        ];
    }
}

return response()->json([
    'batch_id' => $batchId,
    'success' => $success,
    'failures' => $failures,
]);
```

Notas:
- **Sem `DB::transaction` global** — best-effort por design. Cada `payout()` faz seus próprios increments/decrements; falha em uma não faz rollback nas outras.
- `report($e)` mantém observabilidade sem expor detalhes ao cliente.
- Tipo é sempre `withdrawal` (lote não é pra adjustment).

### Rotas

`routes/api.php`, dentro do `Route::middleware(AdminMiddleware::class)->group(...)`:

```php
Route::get('admin/internacional-shops/{shop}/eligible-users',
    [AdminShopBatchPayoutController::class, 'eligibleUsers']);
Route::post('admin/internacional-shops/{shop}/batch-payout',
    [AdminShopBatchPayoutController::class, 'batchPayout']);
```

Adicionar logo após a linha `admin/internacional-shops/{shop}/usage`. Nenhum conflito (HTTP method + path único).

### Listagem de payouts (extensão opcional v1)

`AdminPayoutController@index`: incluir `batch_id` no map de cada item da resposta. Permite que o frontend agrupe visualmente futuramente (não obrigatório agora — só expor o campo).

---

## Frontend (`/dashboard`)

### Tipos novos em `src/api/client.ts`

```ts
export interface AdminShopEligibleUser {
  user_id: number;
  name: string | null;
  email: string;
  balance_released_shop: number;
}

export interface AdminShopEligibleUsersResponse {
  data: AdminShopEligibleUser[];
}

export interface BatchPayoutItem {
  user_id: number;
  amount: number;
  note?: string;
}

export interface BatchPayoutPayload {
  note?: string;
  items: BatchPayoutItem[];
}

export interface BatchPayoutResponse {
  batch_id: string;
  success: { user_id: number; payout_log_id: number; amount: number }[];
  failures: { user_id: number; error: string }[];
}
```

### Métodos novos no objeto `api`

```ts
adminShopEligibleUsers: (shopId: number) =>
  request<AdminShopEligibleUsersResponse>(
    `/api/admin/internacional-shops/${shopId}/eligible-users`
  ),

adminBatchPayout: (shopId: number, payload: BatchPayoutPayload) =>
  request<BatchPayoutResponse>(
    `/api/admin/internacional-shops/${shopId}/batch-payout`,
    { method: 'POST', body: JSON.stringify(payload) }
  ),
```

### Componente novo: `src/components/BatchPayoutModal.tsx`

Segue padrão visual de `PayoutModal.tsx`:
- Overlay: `fixed inset-0 z-50 ... bg-black/60 backdrop-blur-sm p-4`
- Container: `bg-surface-1 border border-white/[0.08] rounded-2xl w-full max-w-3xl shadow-2xl`
- Header: nome da loja + contagem de elegíveis + botão fechar (×)
- Inputs: `inputCls` exato do `PayoutModal`
- Labels: `labelCls` exato

Props:
```ts
interface Props {
  shop: AdminCartpandaShop;
  onClose: () => void;
  onSuccess: (response: BatchPayoutResponse) => void;
}
```

Estado interno:
```ts
const [rows, setRows] = useState<Map<number, { selected: boolean; amount: string; note: string }>>(new Map());
const [batchNote, setBatchNote] = useState('');
const [result, setResult] = useState<BatchPayoutResponse | null>(null);
```

Carregamento:
```ts
const { data, isLoading } = useQuery({
  queryKey: ['shop-eligible-users', shop.id],
  queryFn: () => api.adminShopEligibleUsers(shop.id),
});
```

Quando `data` chega, hidratar `rows` com:
- `selected: false`
- `amount: balance_released_shop.toFixed(2)`
- `note: ''`

Tabela:
| col | conteúdo |
|---|---|
| 1 | checkbox (selected) |
| 2 | nome + email (dois textos empilhados) |
| 3 | saldo da loja (`tabular-nums`, formato igual `fmtBalance`) |
| 4 | input de valor (`type="number" step="0.01"`) |
| 5 | input de nota |

Footer sticky:
- Esquerda: input `batchNote` (label "Nota do lote (opcional)")
- Direita: total acumulado dos selecionados + botão "Confirmar lote"

Validação client-side (desabilita botão):
- Pelo menos 1 selecionado
- Para cada selecionado: `amount > 0` e `amount <= balance_released_shop`

Mutation:
```ts
const mut = useMutation({
  mutationFn: (payload: BatchPayoutPayload) => api.adminBatchPayout(shop.id, payload),
  onSuccess: (resp) => {
    setResult(resp);
    qc.invalidateQueries({ queryKey: ['shop-eligible-users', shop.id] });
    qc.invalidateQueries({ queryKey: ['cartpanda-shops'] });
    qc.invalidateQueries({ queryKey: ['admin-payouts'] }); // se existir
    onSuccess(resp);
  },
});
```

Após resposta, **não fechar o modal**: trocar conteúdo principal por painel resultado:
- Sucessos: bloco verde (`bg-emerald-500/10 border border-emerald-500/20`) listando user/amount/payout_log_id.
- Falhas: bloco vermelho (`bg-red-500/10 border border-red-500/20`) listando user/error com sugestão "tentar novamente" (botão que filtra `rows` para apenas falhas e volta pro modo edição).
- Botão "Fechar" libera modal.

### Modificação em `src/pages/admin/CartpandaShops.tsx`

Adicionar:
```tsx
const [batchShop, setBatchShop] = useState<AdminCartpandaShop | null>(null);
```

No `ShopConfigRow`, adicionar botão pequeno (ícone + label "Saque em lote") que chama `onOpenBatch(shop)`. Botão fica visível sempre (não condicionado a `dirty`).

Layout sugerido — atualmente `grid-cols-12`: `col-span-3` (nome) + `col-span-5` (input url) + `col-span-2` (input cap) + `col-span-2` (botões save/cancel). O botão de lote pode entrar:
- **Opção preferida:** reduzir col da URL pra `col-span-4` e adicionar `col-span-1` com botão ícone-only (`BanknoteIcon` lucide ou similar) com tooltip. Mantém densidade.
- **Alternativa:** adicionar uma row de actions abaixo da row principal quando hover/expand.

Decisão durante implementação após ver o componente vivo.

Render condicional do modal:
```tsx
{batchShop && (
  <BatchPayoutModal
    shop={batchShop}
    onClose={() => setBatchShop(null)}
    onSuccess={() => { /* nada extra: invalidate já no modal */ }}
  />
)}
```

### Sem alterações em

- `Layout.tsx`, `App.tsx` — sem nova rota nem item de sidebar (acesso via página de lojas).
- `PayoutModal.tsx`, `Settings.tsx`, `Payouts.tsx` — fluxo individual permanece.
- `routes/api.php` — apenas adições (sem reordenação).

---

## Testes

### Backend (`tests/Feature/Admin/`)

Arquivo novo `BatchPayoutTest.php`:

1. `test_admin_can_batch_payout_users_for_shop`
   - Cria shop + 3 users com cartpanda_orders released. Garante balance_released_shop > 0.
   - POST com items dos 3. Espera 200, `success.length == 3`, `failures == []`, `batch_id` UUID válido.
   - `assertDatabaseCount('payout_logs', 3)`, todos com mesmo `batch_id`, `shop_id`, `type='withdrawal'`.
   - Confere `user_balances.balance_released` decrementado por cada user.

2. `test_returns_failures_for_user_not_assigned_to_shop`
   - 2 users assigned + 1 não-assigned.
   - Espera `success.length == 2`, `failures[0].error == 'user_not_assigned_to_shop'`.
   - Balance dos 2 sucessos decrementado; do 3º intacto.

3. `test_returns_failures_when_one_payout_throws_unexpected`
   - Mock/stub `BalanceService` pra lançar exceção em 1 user.
   - Outros sucedem; `failures[0].error == 'unexpected_error'`.

4. `test_eligible_users_excludes_users_without_shop_balance`
   - 2 users assigned: 1 com order released > 0, 1 sem orders.
   - GET retorna apenas o primeiro.

5. `test_eligible_users_excludes_users_not_assigned_to_shop`
   - User com orders na loja mas sem `cartpanda_shop_user` → não aparece.

6. `test_per_row_note_overrides_batch_note`
   - Item com `note: "X"` + batch `note: "Y"` → PayoutLog grava "X". Item sem note → grava "Y".

7. `test_only_admin_can_call_endpoints`
   - User comum → 403 em ambos endpoints.

8. `test_validation_rejects_empty_or_oversized_items`
   - `items: []` → 422. `items` com 101 entradas → 422. `amount: 0` → 422.

### Backend (`tests/Feature/Balance/`)

Adicionar caso em `BalanceServiceTest`:
- `test_payout_persists_batch_id_when_provided`
   - `service->payout(..., shopId: $shop->id, batchId: 'abc-...')` grava `batch_id`.

### Frontend

Sem testes E2E novos (projeto não tem suite frontend automatizada — verificação manual durante implementação).

---

## Sem efeitos colaterais

| Componente | Risco | Mitigação |
|---|---|---|
| `payout_logs.batch_id` migration | Alterar tabela em produção | Coluna nullable + index simples; `ALTER` online em MySQL 8 |
| `BalanceService::payout` assinatura | Quebrar callers existentes | Param novo é opcional posicional, default null; testes existentes não tocam |
| `PayoutLog` fillable | Mass assignment | Apenas `batch_id` adicionado; preenchido só via service |
| Cálculo de balance per-shop | Divergir do que admin vê hoje | Reuso literal da subquery de `AdminCartpandaShopController@show` |
| Listagem `AdminPayoutController@index` | Quebrar response shape | Adição de campo (`batch_id`) é não-quebrante; frontend ignora |
| `RecalculateOrderAmounts` | Sum de payout_logs.amount | Independente de `batch_id` — sum global continua igual |
| Rotas | Colisão | Paths únicos; HTTP methods distintos |
| Frontend `CartpandaShops` grid | Layout quebrar | Mudança de col-span localizada; testar visualmente |
| Octane | Estado em singleton | Service registrado com `scoped()`; controller stateless |

---

## Pontos abertos pra implementação

- Posicionamento exato do botão "Saque em lote" no `ShopConfigRow` (decidido visualmente).
- Texto/ícone do botão (default: ícone `Banknote` lucide ou lord-icon equivalente já usado).
- Mensagens de erro user-friendly em `failures[].error` (mapear `user_not_assigned_to_shop` → "Usuário não está atribuído a essa loja").
