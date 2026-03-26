<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transactions:import {file? : Path to JSON file (default: vendas.json in parent directory)}')]
#[Description('Import transactions from a JSON file')]
class ImportTransactions extends Command
{
    public function handle(): int
    {
        $file = $this->argument('file') ?? dirname(base_path()).'/vendas.json';

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return 1;
        }

        $items = json_decode(file_get_contents($file), true);
        if (! is_array($items)) {
            $this->error('Invalid JSON file');

            return 1;
        }

        $inserted = $skipped = 0;

        foreach ($items as $item) {
            $txId = $item['id'] ?? null;
            if (! $txId) {
                $skipped++;

                continue;
            }

            $payerEmail = $item['payer']['email'] ?? null;
            $user = $payerEmail ? User::where('payer_email', $payerEmail)->first() : null;

            if (Transaction::where('transaction_id', $txId)->exists()) {
                $skipped++;
            } else {
                Transaction::create([
                    'transaction_id' => $txId,
                    'user_id' => $user?->id,
                    'amount' => (float) ($item['amount'] ?? 0),
                    'currency' => $item['currency'] ?? 'EUR',
                    'method' => $item['method'] ?? 'mbway',
                    'status' => $item['status'] ?? 'PENDING',
                    'payer_email' => $payerEmail,
                    'payer_name' => $item['payer']['name'] ?? null,
                    'created_at' => isset($item['createdAt'])
                        ? date('Y-m-d H:i:s', (int) ($item['createdAt'] / 1000))
                        : now(),
                    'updated_at' => isset($item['updatedAt'])
                        ? date('Y-m-d H:i:s', (int) ($item['updatedAt'] / 1000))
                        : now(),
                ]);
                $inserted++;
            }
        }

        $this->info("Done. Inserted: {$inserted} | Skipped: {$skipped}");

        return 0;
    }
}
