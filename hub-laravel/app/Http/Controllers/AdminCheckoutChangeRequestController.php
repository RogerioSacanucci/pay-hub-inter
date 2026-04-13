<?php

namespace App\Http\Controllers;

use App\Models\CheckoutChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCheckoutChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = CheckoutChangeRequest::with('user:id,email')
            ->orderByDesc('created_at');

        $total = $query->count();
        $requests = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $requests->map(fn (CheckoutChangeRequest $r) => [
                'id' => $r->id,
                'user_id' => $r->user_id,
                'user_email' => $r->user?->email,
                'message' => $r->message,
                'status' => $r->status,
                'created_at' => $r->created_at,
            ]),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function update(Request $request, CheckoutChangeRequest $checkoutChangeRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,done'],
        ]);

        $checkoutChangeRequest->update($data);

        return response()->json([
            'data' => [
                'id' => $checkoutChangeRequest->id,
                'status' => $checkoutChangeRequest->status,
            ],
        ]);
    }
}
