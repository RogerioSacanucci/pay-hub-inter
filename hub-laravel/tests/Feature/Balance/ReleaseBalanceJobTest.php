<?php

namespace Tests\Feature\Balance;

use App\Jobs\ReleaseBalanceJob;
use App\Models\CartpandaOrder;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseBalanceJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_releases_completed_orders_older_than_two_days(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0]);

        $order = CartpandaOrder::factory()->for($user)->create([
            'amount' => 100,
            'status' => 'COMPLETED',
            'released_at' => null,
            'created_at' => now()->subDays(3),
        ]);

        ReleaseBalanceJob::dispatchSync();

        // afterFee = 100 * 0.915 = 91.5; releaseAmount = 91.5 * 0.95 = 86.925
        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEqualsWithDelta(13.075, (float) $balance->balance_pending, 0.001); // 100 - 86.925
        $this->assertEqualsWithDelta(86.925, (float) $balance->balance_released, 0.001);

        $order->refresh();
        $this->assertNotNull($order->released_at);
    }

    public function test_does_not_release_orders_younger_than_two_days(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0]);

        CartpandaOrder::factory()->for($user)->create([
            'amount' => 100,
            'status' => 'COMPLETED',
            'released_at' => null,
            'created_at' => now()->subDay(),
        ]);

        ReleaseBalanceJob::dispatchSync();

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(100.0, (float) $balance->balance_pending);
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_does_not_reprocess_already_released_orders(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 0, 'balance_released' => 100]);

        CartpandaOrder::factory()->for($user)->create([
            'amount' => 100,
            'status' => 'COMPLETED',
            'released_at' => now()->subDay(),
            'created_at' => now()->subDays(3),
        ]);

        ReleaseBalanceJob::dispatchSync();

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(0.0, (float) $balance->balance_pending);
        $this->assertEquals(100.0, (float) $balance->balance_released);
    }

    public function test_does_not_release_non_completed_orders(): void
    {
        $user = User::factory()->create();
        UserBalance::factory()->for($user)->create(['balance_pending' => 100, 'balance_released' => 0]);

        CartpandaOrder::factory()->for($user)->pending()->create([
            'amount' => 100,
            'user_id' => $user->id,
            'released_at' => null,
            'created_at' => now()->subDays(3),
        ]);

        ReleaseBalanceJob::dispatchSync();

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(100.0, (float) $balance->balance_pending);
        $this->assertEquals(0.0, (float) $balance->balance_released);
    }

    public function test_processes_multiple_users_correctly(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        UserBalance::factory()->for($userA)->create(['balance_pending' => 200, 'balance_released' => 0]);
        UserBalance::factory()->for($userB)->create(['balance_pending' => 150, 'balance_released' => 0]);

        CartpandaOrder::factory()->for($userA)->create([
            'amount' => 80,
            'status' => 'COMPLETED',
            'released_at' => null,
            'created_at' => now()->subDays(3),
        ]);

        CartpandaOrder::factory()->for($userB)->create([
            'amount' => 50,
            'status' => 'COMPLETED',
            'released_at' => null,
            'created_at' => now()->subDays(5),
        ]);

        ReleaseBalanceJob::dispatchSync();

        // userA: afterFee = 80 * 0.915 = 73.2; releaseAmount = 73.2 * 0.95 = 69.54
        $balanceA = UserBalance::where('user_id', $userA->id)->first();
        $this->assertEqualsWithDelta(130.46, (float) $balanceA->balance_pending, 0.001); // 200 - 69.54
        $this->assertEqualsWithDelta(69.54, (float) $balanceA->balance_released, 0.001);

        // userB: afterFee = 50 * 0.915 = 45.75; releaseAmount = 45.75 * 0.95 = 43.4625
        $balanceB = UserBalance::where('user_id', $userB->id)->first();
        $this->assertEqualsWithDelta(106.5375, (float) $balanceB->balance_pending, 0.001); // 150 - 43.4625
        $this->assertEqualsWithDelta(43.4625, (float) $balanceB->balance_released, 0.001);
    }
}
