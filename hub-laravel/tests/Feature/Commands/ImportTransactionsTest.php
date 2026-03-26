<?php

namespace Tests\Feature\Commands;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportTransactionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_transactions_from_json(): void
    {
        $user = User::factory()->create(['payer_email' => 'payer@test.com']);

        $data = [[
            'id' => 'txn_import_1',
            'status' => 'COMPLETED',
            'amount' => 25.00,
            'method' => 'mbway',
            'currency' => 'EUR',
            'payer' => ['email' => 'payer@test.com', 'name' => 'Test User'],
            'createdAt' => now()->timestamp * 1000,
            'updatedAt' => now()->timestamp * 1000,
        ]];

        $path = storage_path('test_import.json');
        File::put($path, json_encode($data));

        $this->artisan('transactions:import', ['file' => $path])
            ->assertExitCode(0);

        $this->assertDatabaseHas('transactions', ['transaction_id' => 'txn_import_1']);

        File::delete($path);
    }

    public function test_skips_duplicate_transaction_ids(): void
    {
        $user = User::factory()->create(['payer_email' => 'payer@test.com']);
        Transaction::factory()->create(['user_id' => $user->id, 'transaction_id' => 'txn_dup']);

        $data = [[
            'id' => 'txn_dup',
            'status' => 'COMPLETED',
            'amount' => 10.00,
            'method' => 'mbway',
            'currency' => 'EUR',
            'payer' => ['email' => 'payer@test.com', 'name' => 'User'],
            'createdAt' => now()->timestamp * 1000,
            'updatedAt' => now()->timestamp * 1000,
        ]];

        $path = storage_path('test_import_dup.json');
        File::put($path, json_encode($data));

        $this->artisan('transactions:import', ['file' => $path])
            ->expectsOutputToContain('Skipped: 1')
            ->assertExitCode(0);

        File::delete($path);
    }
}
