<?php

namespace Tests\Feature\Transactions;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_sees_only_own_transactions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user->id]);
        Transaction::factory()->create(['user_id' => $other->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_sees_all_transactions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user1->id]);
        Transaction::factory()->create(['user_id' => $user2->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_admin_filters_by_user_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user1->id]);
        Transaction::factory()->create(['user_id' => $user2->id]);
        $token = $admin->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions?user_id='.$user1->id);
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_transactions_filter_by_status(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'COMPLETED']);
        Transaction::factory()->create(['user_id' => $user->id, 'status' => 'PENDING']);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions?status=COMPLETED');
        $this->assertCount(1, $response->json('data'));
    }

    public function test_transactions_paginate(): void
    {
        $user = User::factory()->create();
        Transaction::factory()->count(25)->create(['user_id' => $user->id]);
        $token = $user->createToken('auth')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/transactions?page=1');
        $response->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);
        $this->assertCount(20, $response->json('data'));
        $this->assertEquals(25, $response->json('meta.total'));
        $this->assertEquals(2, $response->json('meta.pages'));
    }

    public function test_unauthenticated_user_cannot_access_transactions(): void
    {
        $this->getJson('/api/transactions')->assertUnauthorized();
    }
}
