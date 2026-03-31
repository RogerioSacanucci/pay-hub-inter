<?php

namespace Tests\Feature\Balance;

use App\Models\PayoutLog;
use App\Models\User;
use App\Models\UserBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_user_balance_and_payout_logs(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create([
            'balance_pending' => 120.5,
            'balance_released' => 340.0,
        ]);

        PayoutLog::factory()->for($user)->create([
            'admin_user_id' => $admin->id,
            'amount' => -200.0,
            'type' => 'withdrawal',
            'note' => 'Test payout',
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk()
            ->assertJsonStructure([
                'balance' => ['balance_pending', 'balance_reserve', 'balance_released', 'currency'],
                'payout_logs' => [
                    'data' => [['id', 'amount', 'type', 'note', 'admin_email', 'created_at']],
                    'meta' => ['total', 'page', 'per_page', 'pages'],
                ],
            ])
            ->assertJsonPath('balance.balance_pending', '120.500000')
            ->assertJsonPath('balance.balance_released', '340.000000')
            ->assertJsonPath('payout_logs.meta.total', 1);
    }

    public function test_admin_can_view_balance_for_user_without_balance_record(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance");

        $response->assertOk()
            ->assertJsonPath('balance.balance_pending', '0.000000')
            ->assertJsonPath('balance.balance_released', '0.000000')
            ->assertJsonPath('balance.currency', 'USD')
            ->assertJsonPath('payout_logs.data', []);
    }

    public function test_admin_can_create_withdrawal_payout(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create([
            'balance_pending' => 0,
            'balance_released' => 500.0,
        ]);

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 200.00,
            'type' => 'withdrawal',
            'note' => 'Monthly payout',
        ]);

        $response->assertOk()
            ->assertJsonPath('balance.balance_released', '300.000000');

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'type' => 'withdrawal',
            'note' => 'Monthly payout',
        ]);
    }

    public function test_admin_can_create_adjustment_payout(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create([
            'balance_pending' => 0,
            'balance_released' => 100.0,
        ]);

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 50.00,
            'type' => 'adjustment',
            'note' => 'Correction',
        ]);

        $response->assertOk()
            ->assertJsonPath('balance.balance_released', '150.000000');

        $this->assertDatabaseHas('payout_logs', [
            'user_id' => $user->id,
            'type' => 'adjustment',
        ]);
    }

    public function test_payout_validates_required_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'type']);
    }

    public function test_payout_validates_type_enum(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_payout_validates_note_max_length(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'withdrawal',
            'note' => str_repeat('a', 501),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('note');
    }

    public function test_non_admin_cannot_access_balance(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance")
            ->assertStatus(403);
    }

    public function test_non_admin_cannot_create_payout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'withdrawal',
        ])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_balance(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/admin/users/{$user->id}/balance")->assertUnauthorized();
    }

    public function test_unauthenticated_cannot_create_payout(): void
    {
        $user = User::factory()->create();

        $this->postJson("/api/admin/users/{$user->id}/payout", [
            'amount' => 100,
            'type' => 'withdrawal',
        ])->assertUnauthorized();
    }

    public function test_returns_404_for_nonexistent_user(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/users/99999/balance')
            ->assertNotFound();

        $this->withToken($token)->postJson('/api/admin/users/99999/payout', [
            'amount' => 100,
            'type' => 'withdrawal',
        ])->assertNotFound();
    }

    public function test_payout_logs_are_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $token = $admin->createToken('auth')->plainTextToken;

        UserBalance::factory()->for($user)->create();
        PayoutLog::factory()->for($user)->count(25)->create([
            'admin_user_id' => $admin->id,
        ]);

        $response = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance?page=1");

        $response->assertOk()
            ->assertJsonPath('payout_logs.meta.total', 25)
            ->assertJsonPath('payout_logs.meta.per_page', 20)
            ->assertJsonPath('payout_logs.meta.pages', 2);
        $this->assertCount(20, $response->json('payout_logs.data'));

        $page2 = $this->withToken($token)->getJson("/api/admin/users/{$user->id}/balance?page=2");
        $this->assertCount(5, $page2->json('payout_logs.data'));
    }
}
