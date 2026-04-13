<?php

namespace App\Http\Controllers;

use App\Models\CheckoutChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutChangeRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = CheckoutChangeRequest::where('user_id', auth()->id())
            ->orderByDesc('created_at');

        $total = $query->count();
        $requests = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $requests->map(fn (CheckoutChangeRequest $r) => [
                'id' => $r->id,
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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $changeRequest = CheckoutChangeRequest::create([
            'user_id' => auth()->id(),
            'message' => $data['message'],
            'status' => 'pending',
        ]);

        return response()->json([
            'data' => [
                'id' => $changeRequest->id,
                'message' => $changeRequest->message,
                'status' => $changeRequest->status,
                'created_at' => $changeRequest->created_at,
            ],
        ], 201);
    }
}
