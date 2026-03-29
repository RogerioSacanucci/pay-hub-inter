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

        $balance = UserBalance::where('user_id', $user->id)->first();
        $this->assertEquals(0.0, (float) $balance->balance_pending);
        $this->assertEquals(100.0, (float) $balance->balance_released);

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

        $balanceA = UserBalance::where('user_id', $userA->id)->first();
        $this->assertEquals(120.0, (float) $balanceA->balance_pending);
        $this->assertEquals(80.0, (float) $balanceA->balance_released);

        $balanceB = UserBalance::where('user_id', $userB->id)->first();
        $this->assertEquals(100.0, (float) $balanceB->balance_pending);
        $this->assertEquals(50.0, (float) $balanceB->balance_released);
    }
}
