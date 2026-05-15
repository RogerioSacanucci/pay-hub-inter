<?php

namespace App\Http\Controllers;

use App\Models\MundpayWebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMundpayWebhookLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = MundpayWebhookLog::query()->orderByDesc('created_at');

        if ($request->filled('event')) {
            $query->where('event', $request->query('event'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $total = $query->count();
        $logs = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'data' => $logs->map(fn (MundpayWebhookLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'mundpay_order_id' => $log->mundpay_order_id,
                'status' => $log->status,
                'status_reason' => $log->status_reason,
                'payload' => $log->payload,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
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
