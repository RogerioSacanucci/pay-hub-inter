# Saque em Lote por Loja — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que admin escolha uma loja, veja todos os usuários com saldo released > 0 nessa loja e dispare múltiplos saques (1 PayoutLog por usuário) num único request, com batch_id compartilhado e processamento best-effort (sucessos/falhas reportados por linha).

**Architecture:** Adiciona coluna `batch_id` em `payout_logs`, novo controller admin com 2 endpoints (`eligible-users` GET, `batch-payout` POST), reusa subquery existente de `AdminCartpandaShopController@show` para o cálculo de saldo por loja×usuário, reusa `BalanceService::payout()` (com novo param opcional `$batchId`) por linha em try/catch isolado. Frontend adiciona modal `BatchPayoutModal` aberto via botão na linha de cada loja em `pages/admin/CartpandaShops.tsx`.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL/SQLite, PHPUnit, React 18 + TypeScript + React Query 5 + Tailwind, lucide-react.

**Spec:** `docs/superpowers/specs/2026-05-07-batch-payout-by-shop-design.md`

---

## File Structure

| Ação | Caminho | Responsabilidade |
|---|---|---|
| Create | `hub-laravel/database/migrations/2026_05_07_XXXXXX_add_batch_id_to_payout_logs_table.php` | Adicionar coluna `batch_id` (uuid nullable) + índice |
| Modify | `hub-laravel/app/Models/PayoutLog.php` | Incluir `batch_id` em `#[Fillable]` |
| Modify | `hub-laravel/database/factories/PayoutLogFactory.php` | State `forBatch(string $id)` |
| Modify | `hub-laravel/app/Services/BalanceService.php` | 7º parâmetro opcional `?string $batchId` em `payout()` |
| Modify | `hub-laravel/tests/Feature/Balance/BalanceServiceTest.php` | Teste de persistência de `batch_id` |
| Create | `hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php` | Endpoints `eligibleUsers()` + `batchPayout()` |
| Modify | `hub-laravel/routes/api.php` | Registrar 2 rotas no grupo admin |
| Modify | `hub-laravel/app/Http/Controllers/AdminPayoutController.php` | Expor `batch_id` no map do `index()` |
| Create | `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php` | Feature tests do fluxo de lote |
| Modify | `dashboard/src/api/client.ts` | Tipos + métodos `adminShopEligibleUsers`/`adminBatchPayout` |
| Create | `dashboard/src/components/BatchPayoutModal.tsx` | Modal com tabela + envio do lote |
| Modify | `dashboard/src/pages/admin/CartpandaShops.tsx` | Botão por linha + estado do modal |

Working directory backend: `hub-laravel/hub-laravel/`. Working directory frontend: `dashboard/`. Repo raiz: `/Users/fabriciojuliano/Documents/ll`.

---

## Task 1: Migration — add `batch_id` to `payout_logs`

**Files:**
- Create: `hub-laravel/database/migrations/2026_05_07_XXXXXX_add_batch_id_to_payout_logs_table.php`

- [ ] **Step 1: Gerar migration**

```bash
cd hub-laravel
php artisan make:migration add_batch_id_to_payout_logs_table --table=payout_logs --no-interaction
```

Anota o nome do arquivo gerado (timestamp real). Substitui o placeholder `XXXXXX` em referências subsequentes.

- [ ] **Step 2: Escrever conteúdo**

Substitui o conteúdo do arquivo gerado por:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->uuid('batch_id')->nullable()->after('shop_id');
            $table->index('batch_id', 'idx_payout_logs_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('payout_logs', function (Blueprint $table) {
            $table->dropIndex('idx_payout_logs_batch_id');
            $table->dropColumn('batch_id');
        });
    }
};
```

- [ ] **Step 3: Rodar migration e validar**

```bash
cd hub-laravel
php artisan migrate
```

Expected: linha `INFO  Running migrations.` + `... add_batch_id_to_payout_logs_table .... DONE`.

- [ ] **Step 4: Commit**

```bash
git add hub-laravel/database/migrations/2026_05_07_*_add_batch_id_to_payout_logs_table.php
git commit -m "feat: add batch_id column to payout_logs"
```

---

## Task 2: PayoutLog model + factory aceitam `batch_id`

**Files:**
- Modify: `hub-laravel/app/Models/PayoutLog.php`
- Modify: `hub-laravel/database/factories/PayoutLogFactory.php`
- Test: `hub-laravel/tests/Feature/Balance/BalanceServiceTest.php` (adicionar 1 teste)

- [ ] **Step 1: Escrever teste falho** (arquivo já existe; appendar antes do `}` final da classe)

Em `hub-laravel/tests/Feature/Balance/BalanceServiceTest.php`, adiciona ao final da classe:

```php
public function test_payout_persists_batch_id_when_provided(): void
{
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();
    UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 500]);

    $batchId = '11111111-1111-1111-1111-111111111111';
    $log = $this->service->payout($user, $admin, 100, 'withdrawal', null, null, $batchId);

    $this->assertSame($batchId, $log->batch_id);
    $this->assertDatabaseHas('payout_logs', [
        'id' => $log->id,
        'batch_id' => $batchId,
    ]);
}
```

- [ ] **Step 2: Rodar e confirmar falha**

```bash
cd hub-laravel
php artisan test --compact --filter=test_payout_persists_batch_id_when_provided
```

Expected: FAIL com erro de "Too few arguments" ou coluna inexistente — confirma que ainda não funciona.

- [ ] **Step 3: Atualizar `PayoutLog` model**

Em `hub-laravel/app/Models/PayoutLog.php`, troca o atributo `Fillable`:

De:
```php
#[Fillable(['user_id', 'admin_user_id', 'shop_id', 'amount', 'type', 'note'])]
```

Para:
```php
#[Fillable(['user_id', 'admin_user_id', 'shop_id', 'batch_id', 'amount', 'type', 'note'])]
```

- [ ] **Step 4: Atualizar factory**

Em `hub-laravel/database/factories/PayoutLogFactory.php`, adiciona ao final da classe (antes do `}` que fecha a classe):

```php
public function forBatch(string $batchId): static
{
    return $this->state(fn () => ['batch_id' => $batchId]);
}
```

- [ ] **Step 5: Atualizar `BalanceService::payout()`**

Em `hub-laravel/app/Services/BalanceService.php`, atualiza a assinatura e o `PayoutLog::create([...])`:

De:
```php
public function payout(User $user, User $admin, float $amount, string $type, ?string $note, ?int $shopId = null): PayoutLog
{
    $this->ensureBalanceExists($user);

    $logAmount = $type === 'withdrawal' ? -abs($amount) : $amount;

    if ($logAmount < 0) {
        UserBalance::where('user_id', $user->id)
            ->decrement('balance_released', abs($logAmount), ['updated_at' => now()]);
    } else {
        UserBalance::where('user_id', $user->id)
            ->increment('balance_released', $logAmount, ['updated_at' => now()]);
    }

    return PayoutLog::create([
        'user_id' => $user->id,
        'admin_user_id' => $admin->id,
        'shop_id' => $shopId,
        'amount' => $logAmount,
        'type' => $type,
        'note' => $note,
    ]);
}
```

Para:
```php
public function payout(User $user, User $admin, float $amount, string $type, ?string $note, ?int $shopId = null, ?string $batchId = null): PayoutLog
{
    $this->ensureBalanceExists($user);

    $logAmount = $type === 'withdrawal' ? -abs($amount) : $amount;

    if ($logAmount < 0) {
        UserBalance::where('user_id', $user->id)
            ->decrement('balance_released', abs($logAmount), ['updated_at' => now()]);
    } else {
        UserBalance::where('user_id', $user->id)
            ->increment('balance_released', $logAmount, ['updated_at' => now()]);
    }

    return PayoutLog::create([
        'user_id' => $user->id,
        'admin_user_id' => $admin->id,
        'shop_id' => $shopId,
        'batch_id' => $batchId,
        'amount' => $logAmount,
        'type' => $type,
        'note' => $note,
    ]);
}
```

Atualiza também o PHPDoc imediatamente acima do método para refletir o novo parâmetro:

```php
/**
 * Record a payout (withdrawal or adjustment).
 * Withdrawal: logAmount = -abs(amount) (always debit).
 * Adjustment: logAmount = amount (positive = credit, negative = debit).
 * $batchId, when provided, agrupa esta linha num lote (saque em lote por loja).
 */
```

- [ ] **Step 6: Rodar teste novo + suite do BalanceService**

```bash
cd hub-laravel
php artisan test --compact --filter=test_payout_persists_batch_id_when_provided
php artisan test --compact tests/Feature/Balance/BalanceServiceTest.php
```

Expected: ambos PASS. A suite confirma que o param novo opcional não quebrou os 5 outros testes existentes do `payout()`.

- [ ] **Step 7: Commit**

```bash
git add hub-laravel/app/Models/PayoutLog.php hub-laravel/app/Services/BalanceService.php hub-laravel/database/factories/PayoutLogFactory.php hub-laravel/tests/Feature/Balance/BalanceServiceTest.php
git commit -m "feat: persist batch_id on PayoutLog via BalanceService"
```

---

## Task 3: Endpoint GET `eligible-users` (TDD)

**Files:**
- Create: `hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php`
- Modify: `hub-laravel/routes/api.php`
- Create: `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php`

- [ ] **Step 1: Escrever testes falhos**

Cria `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_users_returns_only_users_with_released_balance_for_shop(): void
    {
        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();

        $userWithBalance = User::factory()->create(['payer_name' => 'Alice']);
        $userWithBalance->shops()->attach($shop->id);
        UserBalance::factory()->for($userWithBalance)->create(['balance_released' => 0, 'balance_pending' => 0]);
        CartpandaOrder::factory()->create([
            'user_id' => $userWithBalance->id,
            'shop_id' => $shop->id,
            'status' => 'COMPLETED',
            'amount' => 200.0,
            'released_at' => now()->subDay(),
        ]);

        $userZeroBalance = User::factory()->create(['payer_name' => 'Bob']);
        $userZeroBalance->shops()->attach($shop->id);

        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/admin/internacional-shops/{$shop->id}/eligible-users");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $userWithBalance->id)
            ->assertJsonPath('data.0.name', 'Alice')
            ->assertJsonPath('data.0.email', $userWithBalance->email);

        $balance = (float) $response->json('data.0.balance_released_shop');
        $this->assertEqualsWithDelta(190.0, $balance, 0.01); // 200 * 0.95
    }

    public function test_eligible_users_excludes_users_not_assigned_to_shop(): void
    {
        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();

        $unassigned = User::factory()->create();
        CartpandaOrder::factory()->create([
            'user_id' => $unassigned->id,
            'shop_id' => $shop->id,
            'status' => 'COMPLETED',
            'amount' => 200.0,
            'released_at' => now()->subDay(),
        ]);

        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/admin/internacional-shops/{$shop->id}/eligible-users")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_eligible_users_subtracts_existing_payouts_from_shop(): void
    {
        $admin = User::factory()->admin()->create();
        $shop = CartpandaShop::factory()->create();
        $user = User::factory()->create();
        $user->shops()->attach($shop->id);

        CartpandaOrder::factory()->create([
            'user_id' => $user->id,
            'shop_id' => $shop->id,
            'status' => 'COMPLETED',
            'amount' => 200.0,
            'released_at' => now()->subDay(),
        ]);
        // Saque prévio na mesma loja: -100
        PayoutLog::factory()->for($user)->forShop($shop)->create([
            'admin_user_id' => $admin->id,
            'amount' => -100.0,
            'type' => 'withdrawal',
        ]);

        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/admin/internacional-shops/{$shop->id}/eligible-users")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $balance = (float) $response->json('data.0.balance_released_shop');
        $this->assertEqualsWithDelta(90.0, $balance, 0.01); // 190 - 100
    }

    public function test_eligible_users_requires_admin(): void
    {
        $user = User::factory()->create();
        $shop = CartpandaShop::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/admin/internacional-shops/{$shop->id}/eligible-users")
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Rodar e confirmar falha**

```bash
cd hub-laravel
php artisan test --compact tests/Feature/Admin/BatchPayoutTest.php
```

Expected: 4 testes FAIL com 404 (rota inexistente).

- [ ] **Step 3: Criar controller**

```bash
cd hub-laravel
php artisan make:controller AdminShopBatchPayoutController --no-interaction
```

- [ ] **Step 4: Escrever conteúdo do controller**

Substitui `hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php` por:

```php
<?php

namespace App\Http\Controllers;

use App\Models\CartpandaShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminShopBatchPayoutController extends Controller
{
    public function eligibleUsers(CartpandaShop $shop): JsonResponse
    {
        $rows = DB::table('users as u')
            ->join('cartpanda_shop_user as su', function ($join) use ($shop) {
                $join->on('su.user_id', '=', 'u.id')
                    ->where('su.shop_id', '=', $shop->id);
            })
            ->leftJoinSub(
                DB::table('cartpanda_orders')
                    ->where('shop_id', $shop->id)
                    ->whereIn('status', ['COMPLETED', 'DECLINED'])
                    ->groupBy('user_id')
                    ->selectRaw('
                        user_id,
                        SUM(CASE WHEN status = \'COMPLETED\' AND released_at IS NOT NULL THEN amount * 0.95 ELSE 0 END)
                        - SUM(CASE WHEN status = \'DECLINED\' THEN COALESCE(chargeback_penalty, 0) ELSE 0 END) as released_from_orders
                    '),
                'orders',
                'orders.user_id',
                '=',
                'u.id'
            )
            ->leftJoinSub(
                DB::table('payout_logs')
                    ->where('shop_id', $shop->id)
                    ->groupBy('user_id')
                    ->selectRaw('user_id, SUM(amount) as total_payouts'),
                'payouts',
                'payouts.user_id',
                '=',
                'u.id'
            )
            ->selectRaw('
                u.id as user_id,
                u.payer_name as name,
                u.email,
                COALESCE(orders.released_from_orders, 0) + COALESCE(payouts.total_payouts, 0) as balance_released_shop
            ')
            ->havingRaw('balance_released_shop > 0')
            ->orderByDesc('balance_released_shop')
            ->get();

        return response()->json([
            'data' => $rows->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'email' => $r->email,
                'balance_released_shop' => round((float) $r->balance_released_shop, 2),
            ]),
        ]);
    }
}
```

- [ ] **Step 5: Registrar rota**

Em `hub-laravel/routes/api.php`, adiciona o `use` no topo (junto com os outros):

```php
use App\Http\Controllers\AdminShopBatchPayoutController;
```

E adiciona dentro do `Route::middleware(AdminMiddleware::class)->group(function () { ... })`, logo após a linha `Route::get('admin/internacional-shops/{shop}/usage', ...);`:

```php
Route::get('admin/internacional-shops/{shop}/eligible-users', [AdminShopBatchPayoutController::class, 'eligibleUsers']);
```

- [ ] **Step 6: Rodar testes e validar**

```bash
cd hub-laravel
php artisan test --compact tests/Feature/Admin/BatchPayoutTest.php
```

Expected: 4 testes PASS.

- [ ] **Step 7: Commit**

```bash
git add hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php hub-laravel/routes/api.php hub-laravel/tests/Feature/Admin/BatchPayoutTest.php
git commit -m "feat: add admin endpoint listing eligible users for shop payout"
```

---

## Task 4: Endpoint POST `batch-payout` — happy path (TDD)

**Files:**
- Modify: `hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php`
- Modify: `hub-laravel/routes/api.php`
- Modify: `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php`

- [ ] **Step 1: Escrever teste falho**

Em `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php`, adiciona ao final da classe (antes do `}` final):

```php
public function test_batch_payout_creates_payout_log_per_user_with_shared_batch_id(): void
{
    $admin = User::factory()->admin()->create();
    $shop = CartpandaShop::factory()->create();

    $users = User::factory()->count(3)->create();
    foreach ($users as $u) {
        $u->shops()->attach($shop->id);
        UserBalance::factory()->for($u)->create([
            'balance_pending' => 0,
            'balance_released' => 500,
        ]);
    }

    $token = $admin->createToken('auth')->plainTextToken;

    $response = $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        [
            'note' => 'Lote semanal',
            'items' => [
                ['user_id' => $users[0]->id, 'amount' => 100.00],
                ['user_id' => $users[1]->id, 'amount' => 50.00],
                ['user_id' => $users[2]->id, 'amount' => 25.00],
            ],
        ]
    );

    $response->assertOk()
        ->assertJsonStructure(['batch_id', 'success', 'failures'])
        ->assertJsonCount(3, 'success')
        ->assertJsonCount(0, 'failures');

    $batchId = $response->json('batch_id');
    $this->assertMatchesRegularExpression(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $batchId
    );

    $this->assertSame(3, PayoutLog::where('batch_id', $batchId)->count());
    $this->assertSame(3, PayoutLog::where('batch_id', $batchId)->where('shop_id', $shop->id)->count());

    $this->assertEquals(400.0, (float) UserBalance::where('user_id', $users[0]->id)->value('balance_released'));
    $this->assertEquals(450.0, (float) UserBalance::where('user_id', $users[1]->id)->value('balance_released'));
    $this->assertEquals(475.0, (float) UserBalance::where('user_id', $users[2]->id)->value('balance_released'));

    $this->assertDatabaseHas('payout_logs', [
        'batch_id' => $batchId,
        'user_id' => $users[0]->id,
        'amount' => -100.000000,
        'note' => 'Lote semanal',
        'type' => 'withdrawal',
    ]);
}
```

- [ ] **Step 2: Rodar e confirmar falha**

```bash
cd hub-laravel
php artisan test --compact --filter=test_batch_payout_creates_payout_log_per_user_with_shared_batch_id
```

Expected: FAIL com 404 (rota POST inexistente) ou 405.

- [ ] **Step 3: Adicionar rota**

Em `hub-laravel/routes/api.php`, adiciona logo abaixo da rota `eligible-users`:

```php
Route::post('admin/internacional-shops/{shop}/batch-payout', [AdminShopBatchPayoutController::class, 'batchPayout']);
```

- [ ] **Step 4: Adicionar dependência do `BalanceService` ao controller**

Em `hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php`, adiciona o `use` de `BalanceService` e `Illuminate\Http\Request` e `Illuminate\Support\Str` no topo:

```php
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
```

E adiciona o construtor logo abaixo da `class AdminShopBatchPayoutController extends Controller`:

```php
public function __construct(private BalanceService $balanceService) {}
```

- [ ] **Step 5: Implementar método `batchPayout`**

Em `hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php`, adiciona o método dentro da classe (depois de `eligibleUsers`):

```php
public function batchPayout(Request $request, CartpandaShop $shop): JsonResponse
{
    $data = $request->validate([
        'note' => ['nullable', 'string', 'max:500'],
        'items' => ['required', 'array', 'min:1', 'max:100'],
        'items.*.user_id' => ['required', 'integer', 'exists:users,id'],
        'items.*.amount' => ['required', 'numeric', 'min:0.01'],
        'items.*.note' => ['nullable', 'string', 'max:500'],
    ]);

    $batchId = (string) Str::uuid();
    $sharedNote = $data['note'] ?? null;
    $success = [];
    $failures = [];

    foreach ($data['items'] as $item) {
        try {
            $user = User::findOrFail($item['user_id']);

            if (! $shop->users()->whereKey($user->id)->exists()) {
                throw new \DomainException('user_not_assigned_to_shop');
            }

            $log = $this->balanceService->payout(
                $user,
                $request->user(),
                (float) $item['amount'],
                'withdrawal',
                $item['note'] ?? $sharedNote,
                $shop->id,
                $batchId,
            );

            $success[] = [
                'user_id' => $user->id,
                'payout_log_id' => $log->id,
                'amount' => (float) $item['amount'],
            ];
        } catch (\DomainException $e) {
            $failures[] = [
                'user_id' => $item['user_id'],
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            report($e);
            $failures[] = [
                'user_id' => $item['user_id'],
                'error' => 'unexpected_error',
            ];
        }
    }

    return response()->json([
        'batch_id' => $batchId,
        'success' => $success,
        'failures' => $failures,
    ]);
}
```

- [ ] **Step 6: Rodar teste e validar**

```bash
cd hub-laravel
php artisan test --compact --filter=test_batch_payout_creates_payout_log_per_user_with_shared_batch_id
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add hub-laravel/app/Http/Controllers/AdminShopBatchPayoutController.php hub-laravel/routes/api.php hub-laravel/tests/Feature/Admin/BatchPayoutTest.php
git commit -m "feat: implement batch payout endpoint with shared batch_id"
```

---

## Task 5: Casos de falha do batch-payout (TDD)

**Files:**
- Modify: `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php`

- [ ] **Step 1: Escrever testes adicionais**

Em `hub-laravel/tests/Feature/Admin/BatchPayoutTest.php`, adiciona ao final da classe (antes do `}` final):

```php
public function test_batch_payout_returns_failures_for_unassigned_user(): void
{
    $admin = User::factory()->admin()->create();
    $shop = CartpandaShop::factory()->create();

    $assigned = User::factory()->create();
    $assigned->shops()->attach($shop->id);
    UserBalance::factory()->for($assigned)->create(['balance_released' => 500]);

    $unassigned = User::factory()->create();
    UserBalance::factory()->for($unassigned)->create(['balance_released' => 500]);

    $token = $admin->createToken('auth')->plainTextToken;

    $response = $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        ['items' => [
            ['user_id' => $assigned->id, 'amount' => 100],
            ['user_id' => $unassigned->id, 'amount' => 100],
        ]]
    );

    $response->assertOk()
        ->assertJsonCount(1, 'success')
        ->assertJsonCount(1, 'failures')
        ->assertJsonPath('failures.0.user_id', $unassigned->id)
        ->assertJsonPath('failures.0.error', 'user_not_assigned_to_shop');

    $this->assertEquals(400.0, (float) UserBalance::where('user_id', $assigned->id)->value('balance_released'));
    $this->assertEquals(500.0, (float) UserBalance::where('user_id', $unassigned->id)->value('balance_released'));
}

public function test_batch_payout_per_row_note_overrides_batch_note(): void
{
    $admin = User::factory()->admin()->create();
    $shop = CartpandaShop::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $userA->shops()->attach($shop->id);
    $userB->shops()->attach($shop->id);
    UserBalance::factory()->for($userA)->create(['balance_released' => 500]);
    UserBalance::factory()->for($userB)->create(['balance_released' => 500]);

    $token = $admin->createToken('auth')->plainTextToken;

    $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        [
            'note' => 'Lote default',
            'items' => [
                ['user_id' => $userA->id, 'amount' => 50, 'note' => 'Override A'],
                ['user_id' => $userB->id, 'amount' => 50],
            ],
        ]
    )->assertOk();

    $this->assertDatabaseHas('payout_logs', ['user_id' => $userA->id, 'note' => 'Override A']);
    $this->assertDatabaseHas('payout_logs', ['user_id' => $userB->id, 'note' => 'Lote default']);
}

public function test_batch_payout_validates_items_min_and_max(): void
{
    $admin = User::factory()->admin()->create();
    $shop = CartpandaShop::factory()->create();
    $token = $admin->createToken('auth')->plainTextToken;

    $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        ['items' => []]
    )->assertStatus(422);

    $oversized = array_fill(0, 101, ['user_id' => 1, 'amount' => 1]);
    $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        ['items' => $oversized]
    )->assertStatus(422);
}

public function test_batch_payout_validates_positive_amount(): void
{
    $admin = User::factory()->admin()->create();
    $shop = CartpandaShop::factory()->create();
    $user = User::factory()->create();
    $user->shops()->attach($shop->id);
    $token = $admin->createToken('auth')->plainTextToken;

    $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        ['items' => [['user_id' => $user->id, 'amount' => 0]]]
    )->assertStatus(422);
}

public function test_batch_payout_requires_admin(): void
{
    $user = User::factory()->create();
    $shop = CartpandaShop::factory()->create();
    $token = $user->createToken('auth')->plainTextToken;

    $this->withToken($token)->postJson(
        "/api/admin/internacional-shops/{$shop->id}/batch-payout",
        ['items' => [['user_id' => $user->id, 'amount' => 1]]]
    )->assertForbidden();
}
```

- [ ] **Step 2: Rodar a suite e validar**

```bash
cd hub-laravel
php artisan test --compact tests/Feature/Admin/BatchPayoutTest.php
```

Expected: todos PASS (controller já contempla esses casos via validação + try/catch). Se algum FAIL, ajusta o controller correspondente — espera-se passe sem alteração.

- [ ] **Step 3: Commit**

```bash
git add hub-laravel/tests/Feature/Admin/BatchPayoutTest.php
git commit -m "test: cover batch payout failure cases and validation"
```

---

## Task 6: Expor `batch_id` no `AdminPayoutController@index`

**Files:**
- Modify: `hub-laravel/app/Http/Controllers/AdminPayoutController.php`
- Modify: `hub-laravel/tests/Feature/AdminPayoutsIndexTest.php`

- [ ] **Step 1: Escrever teste falho**

Em `hub-laravel/tests/Feature/AdminPayoutsIndexTest.php`, adiciona ao final da classe:

```php
public function test_index_exposes_batch_id_field(): void
{
    $admin = User::factory()->admin()->create();
    $token = $admin->createToken('auth')->plainTextToken;

    $batchId = '22222222-2222-2222-2222-222222222222';
    PayoutLog::factory()->create([
        'batch_id' => $batchId,
        'admin_user_id' => $admin->id,
    ]);

    $response = $this->withToken($token)->getJson('/api/admin/payouts');
    $response->assertOk()->assertJsonPath('data.0.batch_id', $batchId);
}
```

Garante o `use` de `App\Models\PayoutLog` e `App\Models\User` já presente no arquivo.

- [ ] **Step 2: Rodar e confirmar falha**

```bash
cd hub-laravel
php artisan test --compact --filter=test_index_exposes_batch_id_field
```

Expected: FAIL — campo `batch_id` ausente na resposta.

- [ ] **Step 3: Adicionar `batch_id` ao map**

Em `hub-laravel/app/Http/Controllers/AdminPayoutController.php`, dentro do método `index()`, no `data => $logs->map(...)`, adiciona o campo. Localiza o array que mapeia o `PayoutLog` (linhas ~53-66) e adiciona `'batch_id' => $log->batch_id,` logo após `'id' => $log->id,`. O bloco fica:

```php
'data' => $logs->map(fn (PayoutLog $log) => [
    'id' => $log->id,
    'batch_id' => $log->batch_id,
    'amount' => $log->amount,
    'type' => $log->type,
    'note' => $log->note,
    'shop_name' => $log->shop?->name ?? $log->shop?->shop_slug,
    'admin_email' => $log->admin?->email,
    'created_at' => $log->created_at,
    'user' => [
        'id' => $log->user?->id,
        'name' => $log->user?->payer_name,
        'email' => $log->user?->email,
    ],
]),
```

- [ ] **Step 4: Rodar teste e validar suite**

```bash
cd hub-laravel
php artisan test --compact tests/Feature/AdminPayoutsIndexTest.php
```

Expected: todos PASS.

- [ ] **Step 5: Commit**

```bash
git add hub-laravel/app/Http/Controllers/AdminPayoutController.php hub-laravel/tests/Feature/AdminPayoutsIndexTest.php
git commit -m "feat: expose batch_id in admin payouts list response"
```

---

## Task 7: Pint format pass + suite completa backend

**Files:** todos os PHP modificados.

- [ ] **Step 1: Rodar Pint**

```bash
cd hub-laravel
vendor/bin/pint --dirty --format agent
```

Expected: lista de arquivos formatados (ou "Nothing to fix").

- [ ] **Step 2: Rodar suite completa**

```bash
cd hub-laravel
php artisan test --compact
```

Expected: todos PASS. Se algo quebrar, investiga e corrige antes de seguir.

- [ ] **Step 3: Commit (se Pint mexeu em algo)**

```bash
git add -u hub-laravel/
git diff --cached --quiet || git commit -m "style: pint dirty pass"
```

---

## Task 8: Frontend — tipos e métodos da API

**Files:**
- Modify: `dashboard/src/api/client.ts`

- [ ] **Step 1: Adicionar tipos**

Em `dashboard/src/api/client.ts`, na seção de interfaces (próximo das outras interfaces de payout/shop, ex: depois de `AdminCartpandaShopDetailResponse` ou agrupada com payout types), adiciona:

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

export interface BatchPayoutSuccess {
  user_id: number;
  payout_log_id: number;
  amount: number;
}

export interface BatchPayoutFailure {
  user_id: number;
  error: string;
}

export interface BatchPayoutResponse {
  batch_id: string;
  success: BatchPayoutSuccess[];
  failures: BatchPayoutFailure[];
}
```

- [ ] **Step 2: Adicionar métodos no objeto `api`**

No objeto `api` exportado em `dashboard/src/api/client.ts`, junto dos métodos `adminCartpandaShop*` (próximo da linha 990), adiciona:

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

- [ ] **Step 3: Validar TypeScript**

```bash
cd dashboard
npm run build
```

Expected: build completa sem erro de tipo.

- [ ] **Step 4: Commit**

```bash
git add dashboard/src/api/client.ts
git commit -m "feat(dashboard): add types and methods for batch payout API"
```

---

## Task 9: Frontend — `BatchPayoutModal` component

**Files:**
- Create: `dashboard/src/components/BatchPayoutModal.tsx`

- [ ] **Step 1: Criar arquivo**

Cria `dashboard/src/components/BatchPayoutModal.tsx` com:

```tsx
import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  api,
  AdminCartpandaShop,
  AdminShopEligibleUser,
  BatchPayoutItem,
  BatchPayoutResponse,
} from '../api/client';

interface Props {
  shop: AdminCartpandaShop;
  onClose: () => void;
  onSuccess?: (response: BatchPayoutResponse) => void;
}

interface RowState {
  selected: boolean;
  amount: string;
  note: string;
}

function fmt(value: number): string {
  return value.toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export default function BatchPayoutModal({ shop, onClose, onSuccess }: Props) {
  const qc = useQueryClient();
  const [rows, setRows] = useState<Record<number, RowState>>({});
  const [batchNote, setBatchNote] = useState('');
  const [result, setResult] = useState<BatchPayoutResponse | null>(null);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['shop-eligible-users', shop.id],
    queryFn: () => api.adminShopEligibleUsers(shop.id),
  });

  useEffect(() => {
    if (!data) return;
    const initial: Record<number, RowState> = {};
    for (const u of data.data) {
      initial[u.user_id] = {
        selected: false,
        amount: u.balance_released_shop.toFixed(2),
        note: '',
      };
    }
    setRows(initial);
  }, [data]);

  const eligibleByUser = useMemo(() => {
    const map: Record<number, AdminShopEligibleUser> = {};
    for (const u of data?.data ?? []) map[u.user_id] = u;
    return map;
  }, [data]);

  const selectedItems: BatchPayoutItem[] = Object.entries(rows)
    .filter(([, r]) => r.selected)
    .map(([userId, r]) => ({
      user_id: Number(userId),
      amount: parseFloat(r.amount),
      note: r.note.trim() || undefined,
    }));

  const totalSelected = selectedItems.reduce((acc, it) => acc + (Number.isFinite(it.amount) ? it.amount : 0), 0);

  const invalidRow = selectedItems.find((it) => {
    const cap = eligibleByUser[it.user_id]?.balance_released_shop ?? 0;
    return !Number.isFinite(it.amount) || it.amount <= 0 || it.amount > cap + 0.001;
  });

  const mut = useMutation({
    mutationFn: () =>
      api.adminBatchPayout(shop.id, {
        note: batchNote.trim() || undefined,
        items: selectedItems,
      }),
    onSuccess: (resp) => {
      setResult(resp);
      qc.invalidateQueries({ queryKey: ['shop-eligible-users', shop.id] });
      qc.invalidateQueries({ queryKey: ['cartpanda-shops'] });
      qc.invalidateQueries({ queryKey: ['admin-payouts'] });
      onSuccess?.(resp);
    },
    onError: (err: Error) => setSubmitError(err.message),
  });

  function toggle(userId: number, patch: Partial<RowState>) {
    setRows((prev) => ({
      ...prev,
      [userId]: { ...prev[userId], ...patch },
    }));
  }

  const inputCls =
    'w-full bg-surface-2 border border-white/[0.08] rounded-lg px-3 py-2 text-sm text-white outline-none focus:border-brand/50 focus:ring-1 focus:ring-brand/30 transition-colors';
  const labelCls = 'block text-xs font-semibold text-white/40 uppercase tracking-widest mb-2';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
      <div className="bg-surface-1 border border-white/[0.08] rounded-2xl w-full max-w-3xl shadow-2xl flex flex-col max-h-[90vh]">
        <div className="flex items-center justify-between px-6 pt-6 pb-4 border-b border-white/[0.06]">
          <div>
            <h2 className="text-base font-semibold text-white">Saque em lote — {shop.name}</h2>
            <p className="text-xs text-white/40 mt-1">
              {data ? `${data.data.length} usuário(s) elegível(eis)` : 'Carregando…'}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="text-white/30 hover:text-white/60 transition-colors text-xl leading-none"
          >
            ×
          </button>
        </div>

        {result ? (
          <div className="px-6 py-5 overflow-y-auto flex flex-col gap-4">
            <div className="text-xs text-white/50">
              Lote <span className="font-mono text-white/70">{result.batch_id}</span>
            </div>
            {result.success.length > 0 && (
              <div className="bg-emerald-500/10 border border-emerald-500/20 rounded-xl p-4">
                <div className="text-sm font-semibold text-emerald-300 mb-2">
                  {result.success.length} sucesso(s)
                </div>
                <ul className="text-xs text-emerald-200/80 space-y-1 tabular-nums">
                  {result.success.map((s) => (
                    <li key={s.payout_log_id}>
                      user #{s.user_id} — ${fmt(s.amount)} (log #{s.payout_log_id})
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {result.failures.length > 0 && (
              <div className="bg-red-500/10 border border-red-500/20 rounded-xl p-4">
                <div className="text-sm font-semibold text-red-300 mb-2">
                  {result.failures.length} falha(s)
                </div>
                <ul className="text-xs text-red-200/80 space-y-1">
                  {result.failures.map((f) => (
                    <li key={f.user_id}>
                      user #{f.user_id} — {f.error}
                    </li>
                  ))}
                </ul>
              </div>
            )}
            <div className="flex justify-end gap-3 pt-2">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-semibold transition-colors"
              >
                Fechar
              </button>
            </div>
          </div>
        ) : (
          <>
            <div className="px-6 py-4 overflow-y-auto flex-1">
              {error && (
                <div className="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3 text-sm text-red-400 mb-3">
                  {error instanceof Error ? error.message : 'Erro ao carregar usuários.'}
                </div>
              )}
              {submitError && (
                <div className="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3 text-sm text-red-400 mb-3">
                  {submitError}
                </div>
              )}
              {isLoading && <div className="text-sm text-white/40 py-6">Carregando…</div>}
              {!isLoading && data && data.data.length === 0 && (
                <div className="text-sm text-white/40 py-6 text-center">
                  Nenhum usuário com saldo released disponível nessa loja.
                </div>
              )}
              {!isLoading && data && data.data.length > 0 && (
                <div className="overflow-x-auto rounded-xl border border-white/[0.08]">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-white/[0.06]">
                        {['', 'Usuário', 'Saldo loja', 'Valor', 'Nota'].map((h) => (
                          <th
                            key={h}
                            className="text-left py-2.5 px-3 text-xs font-semibold text-white/30 uppercase tracking-widest"
                          >
                            {h}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-white/[0.04]">
                      {data.data.map((u) => {
                        const r = rows[u.user_id];
                        if (!r) return null;
                        return (
                          <tr key={u.user_id}>
                            <td className="py-2 px-3">
                              <input
                                type="checkbox"
                                checked={r.selected}
                                onChange={(e) => toggle(u.user_id, { selected: e.target.checked })}
                              />
                            </td>
                            <td className="py-2 px-3">
                              <div className="text-white/80">{u.name ?? '—'}</div>
                              <div className="text-[11px] text-white/30">{u.email}</div>
                            </td>
                            <td className="py-2 px-3 text-white/60 tabular-nums">
                              ${fmt(u.balance_released_shop)}
                            </td>
                            <td className="py-2 px-3 w-32">
                              <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={r.amount}
                                onChange={(e) => toggle(u.user_id, { amount: e.target.value })}
                                disabled={!r.selected}
                                className={inputCls}
                              />
                            </td>
                            <td className="py-2 px-3 w-56">
                              <input
                                type="text"
                                value={r.note}
                                onChange={(e) => toggle(u.user_id, { note: e.target.value })}
                                disabled={!r.selected}
                                placeholder="opcional"
                                maxLength={500}
                                className={inputCls}
                              />
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <div className="px-6 py-4 border-t border-white/[0.06] flex flex-col gap-3">
              <div>
                <label className={labelCls}>Nota do lote (opcional)</label>
                <input
                  type="text"
                  value={batchNote}
                  onChange={(e) => setBatchNote(e.target.value)}
                  maxLength={500}
                  placeholder="Ex: Saque semanal 2026-W19"
                  className={inputCls}
                />
              </div>
              <div className="flex items-center justify-between gap-3">
                <div className="text-sm text-white/50 tabular-nums">
                  {selectedItems.length} selecionado(s) · Total{' '}
                  <span className="text-white">${fmt(totalSelected)}</span>
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={onClose}
                    className="px-4 py-2 rounded-xl border border-white/[0.08] text-sm text-white/50 hover:text-white/70 transition-colors"
                  >
                    Cancelar
                  </button>
                  <button
                    type="button"
                    onClick={() => mut.mutate()}
                    disabled={
                      mut.isPending ||
                      selectedItems.length === 0 ||
                      !!invalidRow
                    }
                    className="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-semibold transition-colors disabled:opacity-50"
                  >
                    {mut.isPending ? 'Processando…' : 'Confirmar lote'}
                  </button>
                </div>
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Validar build**

```bash
cd dashboard
npm run build
```

Expected: build completa sem erro.

- [ ] **Step 3: Commit**

```bash
git add dashboard/src/components/BatchPayoutModal.tsx
git commit -m "feat(dashboard): add BatchPayoutModal component"
```

---

## Task 10: Frontend — botão "Saque em lote" em `CartpandaShops`

**Files:**
- Modify: `dashboard/src/pages/admin/CartpandaShops.tsx`

- [ ] **Step 1: Ler componente para localizar onde adicionar**

```bash
cd dashboard
sed -n '1,30p' src/pages/admin/CartpandaShops.tsx
```

Os elementos chave:
- Componente exportado default (provavelmente `CartpandaShops`) renderiza várias `ShopConfigRow`.
- `ShopConfigRow` recebe `shop` como prop.
- Estrutura de grid: `grid-cols-12` com `col-span-3` (nome) + `col-span-5` (URL) + `col-span-2` (cap) + `col-span-2` (botões).

- [ ] **Step 2: Importar `BatchPayoutModal` e o tipo `AdminCartpandaShop`**

No topo do arquivo, garante o import (já existe `AdminCartpandaShopWithStats` mas não o tipo cru):

```tsx
import { api, AdminCartpandaShop, AdminCartpandaShopsResponse, AdminCartpandaShopWithStats } from '../../api/client';
import BatchPayoutModal from '../../components/BatchPayoutModal';
```

- [ ] **Step 3: Adicionar estado do modal no componente pai exportado**

Localiza o componente exportado default da página (provavelmente `export default function CartpandaShops()` ou similar). Dentro dele, adiciona logo no topo do corpo da função:

```tsx
const [batchShop, setBatchShop] = useState<AdminCartpandaShop | null>(null);
```

E no JSX retornado, antes do fechamento do fragmento/div principal, adiciona:

```tsx
{batchShop && (
  <BatchPayoutModal
    shop={batchShop}
    onClose={() => setBatchShop(null)}
  />
)}
```

Passa um prop `onOpenBatch` (do tipo `(shop: AdminCartpandaShop) => void`) para cada `ShopConfigRow` rendered:

```tsx
<ShopConfigRow shop={shop} onOpenBatch={(s) => setBatchShop(s)} />
```

- [ ] **Step 4: Atualizar `ShopConfigRow` para aceitar e usar o prop**

Em `dashboard/src/pages/admin/CartpandaShops.tsx`, troca a assinatura:

De:
```tsx
function ShopConfigRow({ shop }: { shop: AdminCartpandaShopWithStats }) {
```

Para:
```tsx
function ShopConfigRow({
  shop,
  onOpenBatch,
}: {
  shop: AdminCartpandaShopWithStats;
  onOpenBatch: (shop: AdminCartpandaShop) => void;
}) {
```

- [ ] **Step 5: Adicionar botão "Saque em lote" no grid**

Reduz o `col-span-5` da URL para `col-span-4` e introduz uma coluna `col-span-1` para o botão. Localiza no JSX do `ShopConfigRow`:

De:
```tsx
<input
  className="col-span-5 bg-surface-2 ..."
  placeholder="https://loja.../checkout/...?id=... (sem &affiliate=)"
  value={url}
  onChange={(e) => setUrl(e.target.value)}
/>
```

Para:
```tsx
<input
  className="col-span-4 bg-surface-2 border border-white/[0.08] rounded-lg px-3 py-2 text-xs text-white placeholder:text-white/20 outline-none focus:border-brand/50"
  placeholder="https://loja.../checkout/...?id=... (sem &affiliate=)"
  value={url}
  onChange={(e) => setUrl(e.target.value)}
/>
<div className="col-span-1 flex justify-center">
  <button
    type="button"
    onClick={() =>
      onOpenBatch({
        id: shop.id,
        shop_slug: shop.shop_slug,
        name: shop.name,
        default_checkout_template: shop.default_checkout_template,
        daily_cap: shop.daily_cap,
      })
    }
    title="Saque em lote"
    className="text-xs px-2 py-1.5 rounded-lg border border-white/[0.08] text-white/60 hover:text-white hover:border-brand/40 transition-colors"
  >
    Lote
  </button>
</div>
```

> Nota: o objeto passado segue o shape `AdminCartpandaShop`. Se a interface tiver mais campos obrigatórios que não estão no `AdminCartpandaShopWithStats`, ajusta selecionando apenas os campos comuns (TypeScript erra no build se faltar — confirma com `npm run build`).

- [ ] **Step 6: Validar build**

```bash
cd dashboard
npm run build
```

Expected: build completa. Se erro de tipo na construção do `AdminCartpandaShop`, abre `src/api/client.ts` linhas 33-50 (interface `AdminCartpandaShop`) e compatibiliza o objeto literal com os campos requeridos.

- [ ] **Step 7: Commit**

```bash
git add dashboard/src/pages/admin/CartpandaShops.tsx
git commit -m "feat(dashboard): add batch payout button per shop row"
```

---

## Task 11: Verificação final manual

**Files:** nenhum.

- [ ] **Step 1: Suite completa backend**

```bash
cd hub-laravel
php artisan test --compact
```

Expected: todos PASS.

- [ ] **Step 2: Build frontend**

```bash
cd dashboard
npm run build
```

Expected: build limpa.

- [ ] **Step 3: Manual smoke (descrito; engenheiro executa)**

Sobe ambiente de dev (`composer run dev` no hub-laravel + `npm run dev` no dashboard). Login como admin. Navega até a página de lojas (sidebar admin → Lojas Internacionais ou equivalente). Clica botão "Lote" numa loja com usuários elegíveis:

1. Modal abre, lista carrega via `GET /api/admin/internacional-shops/{id}/eligible-users`.
2. Marca 2 usuários, edita valores ≤ saldo da loja, escreve nota do lote, clica "Confirmar lote".
3. Confirma painel verde com 2 sucessos, batch_id exibido.
4. Fecha modal, revisita: lista atualizada (sem os usuários cujo saldo zerou) ou com saldos reduzidos.
5. Verifica em `/saques` (se acessível) ou via DB que `payout_logs.batch_id` tem o valor exibido.

Se algum passo falhar, abre issue com descrição, screenshot, request/response do DevTools.

- [ ] **Step 4: Sem novo commit obrigatório**

Se algo precisar de ajuste, cria commit fix correspondente. Caso contrário, plano completo.

---

## Self-Review (executado durante a escrita do plano)

**Spec coverage:**
- Migration `batch_id` → Task 1 ✓
- `PayoutLog` fillable + factory `forBatch` → Task 2 ✓
- `BalanceService::payout` 7º param → Task 2 ✓
- `GET eligible-users` (reuso da subquery) → Task 3 ✓
- `POST batch-payout` (best-effort, validações, batch_id UUID) → Tasks 4–5 ✓
- Expor `batch_id` no admin payouts list → Task 6 ✓
- Frontend tipos + API methods → Task 8 ✓
- `BatchPayoutModal` (carrega elegíveis, edita rows, footer com total, painel resultado) → Task 9 ✓
- Botão "Saque em lote" no `ShopConfigRow` → Task 10 ✓

**Placeholder scan:** revisado; sem TBD/TODO; código completo em todos os steps de código.

**Type consistency:** assinatura `payout(...)` consistente em service + controllers + testes; tipo `AdminCartpandaShop` usado em modal e prop do row; `BatchPayoutResponse` consistente entre service e controller (já mapeia exatamente).
