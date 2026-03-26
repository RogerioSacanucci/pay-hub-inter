<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WayMbService
{
    public function __construct(
        public readonly string $url,
        public readonly string $accountEmail,
    ) {}

    /**
     * @param  array{amount: float, currency: string, method: string, payer_phone?: string, payer_email?: string, payer_name?: string, payer_document?: string}  $data
     * @return array<string, mixed>
     */
    public function createTransaction(array $data): array
    {
        $response = Http::timeout(10)
            ->throw()
            ->post("{$this->url}/transactions/create", [
                ...$data,
                'account_email' => $this->accountEmail,
            ]);

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionInfo(string $transactionId): array
    {
        $response = Http::timeout(10)
            ->throw()
            ->post("{$this->url}/transactions/info", ['id' => $transactionId]);

        return $response->json();
    }
}
