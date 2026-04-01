<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = Transaction::query();

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }
        if ($method = $request->query('method')) {
            $query->where('method', strtolower($method));
        }
        $offset = (int) $request->query('utc_offset', 0);
        $dbOffset = (int) round(now()->utcOffset() / 60);
        $effectiveOffset = $offset - $dbOffset;

        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom.' 00:00:00')->subHours($effectiveOffset));
        }
        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($dateTo.' 23:59:59')->subHours($effectiveOffset));
        }
        if ($txId = $request->query('transaction_id')) {
            $query->where('transaction_id', 'like', "%{$txId}%");
        }

        $total = $query->count();
        $transactions = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $transactions->map(fn (Transaction $t) => [
                'transaction_id' => $t->transaction_id,
                'amount' => (float) $t->amount,
                'currency' => $t->currency,
                'method' => $t->method,
                'status' => $t->status,
                'payer_name' => $t->payer_name,
                'payer_email' => $t->payer_email,
                'payer_document' => $t->payer_document,
                'reference_entity' => $t->reference_entity,
                'reference_number' => $t->reference_number,
                'reference_expires_at' => $t->reference_expires_at,
                'created_at' => $t->created_at,
                'updated_at' => $t->updated_at,
            ]),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }
}
