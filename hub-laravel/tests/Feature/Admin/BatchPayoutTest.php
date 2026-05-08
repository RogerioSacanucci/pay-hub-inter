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
}
