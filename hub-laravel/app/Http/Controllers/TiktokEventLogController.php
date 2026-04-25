<?php

namespace App\Http\Controllers;

use App\Models\TiktokEventLog;
use App\Models\WebhookLog;
use App\Services\TiktokEventsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TiktokEventLogController extends Controller
{
    public function __construct(private TiktokEventsService $tiktok) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = TiktokEventLog::query()->with('pixel:id,pixel_code,label');

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } elseif ($request->has('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($pixelId = $request->query('pixel_id')) {
            $query->where('tiktok_pixel_id', (int) $pixelId);
        }

        $statusClass = $request->query('status');
        if ($statusClass === 'success') {
            $query->where('http_status', '>=', 200)->where('http_status', '<', 300)->where('tiktok_code', 0);
        } elseif ($statusClass === 'error') {
            $query->where(function ($q) {
                $q->whereNull('http_status')
                    ->orWhere('http_status', '<', 200)
                    ->orWhere('http_status', '>=', 300)
                    ->orWhere('tiktok_code', '!=', 0);
            });
        }

        if ($orderId = $request->query('order_id')) {
            $query->where('cartpanda_order_id', (string) $orderId);
        }

        if ($dateFrom = $request->query('date_from')) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom.' 00:00:00'));
        }
        if ($dateTo = $request->query('date_to')) {
            $query->where('created_at', '<=', Carbon::parse($dateTo.' 23:59:59'));
        }

        $total = $query->count();
        $logs = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $logs->map(fn (TiktokEventLog $log) => [
                'id' => $log->id,
                'pixel' => $log->pixel ? [
                    'id' => $log->pixel->id,
                    'pixel_code' => $log->pixel->pixel_code,
                    'label' => $log->pixel->label,
                ] : null,
                'cartpanda_order_id' => $log->cartpanda_order_id,
                'event' => $log->event,
                'http_status' => $log->http_status,
                'tiktok_code' => $log->tiktok_code,
                'tiktok_message' => $log->tiktok_message,
                'request_id' => $log->request_id,
                'success' => $log->http_status !== null
                    && $log->http_status >= 200
                    && $log->http_status < 300
                    && $log->tiktok_code === 0,
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

    public function show(Request $request, TiktokEventLog $tiktokEventLog): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $tiktokEventLog->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $tiktokEventLog->load('pixel:id,pixel_code,label');

        return response()->json(['data' => [
            'id' => $tiktokEventLog->id,
            'pixel' => $tiktokEventLog->pixel,
            'cartpanda_order_id' => $tiktokEventLog->cartpanda_order_id,
            'event' => $tiktokEventLog->event,
            'http_status' => $tiktokEventLog->http_status,
            'tiktok_code' => $tiktokEventLog->tiktok_code,
            'tiktok_message' => $tiktokEventLog->tiktok_message,
            'request_id' => $tiktokEventLog->request_id,
            'payload' => $tiktokEventLog->payload,
            'response' => $tiktokEventLog->response,
            'created_at' => $tiktokEventLog->created_at,
        ]]);
    }

    public function retry(Request $request, TiktokEventLog $tiktokEventLog): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $tiktokEventLog->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $pixel = $tiktokEventLog->pixel;
        if (! $pixel) {
            return response()->json(['error' => 'Pixel removido — não é possível reenviar'], 410);
        }

        $webhookLog = WebhookLog::where('cartpanda_order_id', $tiktokEventLog->cartpanda_order_id)
            ->orderByDesc('created_at')
            ->first();

        if (! $webhookLog || ! is_array($webhookLog->payload)) {
            return response()->json([
                'error' => 'Payload original do webhook não encontrado (provavelmente expurgado após 24h). Não dá para reenviar este evento.',
            ], 410);
        }

        $order = (array) ($webhookLog->payload['order'] ?? []);
        if (empty($order)) {
            return response()->json(['error' => 'Payload do webhook não contém order'], 422);
        }

        $newLog = $this->tiktok->retryForPixel($pixel, $order);

        return response()->json([
            'data' => [
                'id' => $newLog->id,
                'http_status' => $newLog->http_status,
                'tiktok_code' => $newLog->tiktok_code,
                'tiktok_message' => $newLog->tiktok_message,
                'request_id' => $newLog->request_id,
                'success' => $newLog->http_status !== null
                    && $newLog->http_status >= 200
                    && $newLog->http_status < 300
                    && $newLog->tiktok_code === 0,
                'created_at' => $newLog->created_at,
            ],
        ], 201);
    }
}
