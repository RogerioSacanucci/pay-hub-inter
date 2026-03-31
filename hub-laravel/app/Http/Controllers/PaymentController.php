<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use App\Services\PushcutService;
use App\Services\WayMbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private WayMbService $wayMb,
        private PushcutService $pushcut,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:10000',
            'method' => 'required|in:mbway,multibanco',
            'currency' => 'nullable|string|size:3',
            'payer.email' => 'required|email',
            'payer.name' => 'required|string',
            'payer.phone' => 'nullable|string',
            'success_url' => 'nullable|url',
            'failed_url' => 'nullable|url',
        ]);

        $user = User::with('pushcutUrls')->where('payer_email', $data['payer']['email'])->first();
        if (! $user) {
            return response()->json(['error' => 'Payer email not registered'], 422);
        }

        $phone = $data['method'] === 'mbway'
            ? ($data['payer']['phone'] ?? '')
            : '9'.rand(10000000, 99999999);

        $payload = [
            'amount' => (float) $data['amount'],
            'method' => $data['method'],
            'currency' => $data['currency'] ?? 'EUR',
            'payer' => [
                'email' => $data['payer']['email'],
                'name' => $data['payer']['name'],
                'phone' => $phone,
                'document' => (string) rand(100000000, 999999999),
            ],
            'successUrl' => $data['success_url'] ?? $user->success_url,
            'failedUrl' => $data['failed_url'] ?? $user->failed_url,
            'callbackUrl' => config('app.url').'/api/webhook',
        ];

        $result = $this->wayMb->createTransaction($payload);

        // WayMB may return 'transactionID' (camelCase D) or 'id'
        $txId = $result['transactionID'] ?? $result['id'] ?? null;
        if (! $txId) {
            return response()->json(['error' => 'Gateway did not return a transaction ID'], 502);
        }

        $transaction = Transaction::create([
            'transaction_id' => $txId,
            'user_id' => $user->id,
            'amount' => (float) $data['amount'],
            'currency' => $data['currency'] ?? 'EUR',
            'method' => $data['method'],
            'status' => 'PENDING',
            'payer_email' => $data['payer']['email'],
            'payer_name' => $data['payer']['name'],
            'payer_phone' => $phone,
            'payer_document' => $payload['payer']['document'],
            'reference_entity' => $result['referenceData']['entity'] ?? null,
            'reference_number' => $result['referenceData']['reference'] ?? null,
            'reference_expires_at' => isset($result['referenceData']['expiresAt'])
                ? date('Y-m-d H:i:s', strtotime($result['referenceData']['expiresAt']))
                : null,
        ]);

        $user->pushcutUrls
            ->filter(fn ($dest) => in_array($dest->notify, ['all', 'created']))
            ->each(fn ($dest) => $this->pushcut->send($dest->url, 'New Payment', [
                'amount' => $transaction->amount,
                'method' => $transaction->method,
                'status' => 'PENDING',
            ]));

        return response()->json([
            'transactionId' => $transaction->transaction_id,
            'method' => $transaction->method,
            'amount' => $transaction->amount,
            'generatedMBWay' => $result['generatedMBWay'] ?? $result['generatedMbWay'] ?? null,
            'referenceData' => [
                'entity' => $transaction->reference_entity,
                'reference' => $transaction->reference_number,
                'expiresAt' => $transaction->reference_expires_at,
            ],
        ]);
    }

    public function checkStatus(Request $request): JsonResponse
    {
        $id = $request->query('id') ?? $request->query('transactionId');

        $transaction = Transaction::where('transaction_id', $id)->first();
        if (! $transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        return response()->json([
            'transactionId' => $transaction->transaction_id,
            'status' => $transaction->status,
            'method' => $transaction->method,
            'amount' => $transaction->amount,
            'referenceData' => [
                'entity' => $transaction->reference_entity,
                'reference' => $transaction->reference_number,
                'expiresAt' => $transaction->reference_expires_at,
            ],
        ]);
    }
}
