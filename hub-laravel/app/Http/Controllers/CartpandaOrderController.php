<?php

namespace App\Http\Controllers;

use App\Models\CartpandaOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartpandaOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = CartpandaOrder::query();

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper($status));
        }

        $total = $query->count();
        $orders = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $orders->map(fn (CartpandaOrder $o) => [
                'cartpanda_order_id' => $o->cartpanda_order_id,
                'amount' => (float) $o->amount,
                'currency' => $o->currency,
                'status' => $o->status,
                'event' => $o->event,
                'payer_name' => $o->payer_name,
                'payer_email' => $o->payer_email,
                'created_at' => $o->created_at,
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
