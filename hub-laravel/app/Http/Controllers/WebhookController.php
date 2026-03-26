<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\PushcutService;
use App\Services\WayMbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(
        private WayMbService $wayMb,
        private PushcutService $pushcut,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:PENDING,COMPLETED,FAILED,EXPIRED,DECLINED',
        ]);

        $transactionId = $request->input('transactionId') ?? $request->input('id');
        $transaction = Transaction::where('transaction_id', $transactionId)->first();

        if (! $transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Silently accept callbacks on terminal transactions
        // (WayMB retries on non-2xx, causing infinite loops)
        if ($transaction->isTerminal()) {
            return response()->json(['ok' => true]);
        }

        // Verify with WayMB API before updating DB
        $info = $this->wayMb->getTransactionInfo($transactionId);
        if (($info['status'] ?? '') !== $data['status']) {
            return response()->json(['error' => 'Status mismatch with gateway'], 422);
        }

        $transaction->update([
            'status' => $data['status'],
            'callback_data' => $request->all(),
        ]);

        if ($data['status'] === 'COMPLETED') {
            $user = $transaction->user;
            if ($user->pushcut_url && in_array($user->pushcut_notify, ['all', 'paid'])) {
                $this->pushcut->send($user->pushcut_url, 'Payment Completed', [
                    'amount' => $transaction->amount,
                    'method' => $transaction->method,
                    'status' => 'COMPLETED',
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
