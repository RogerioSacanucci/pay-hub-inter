<?php

namespace App\Http\Controllers;

use App\Models\CartpandaOrder;
use App\Models\User;
use App\Services\PushcutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartpandaWebhookController extends Controller
{
    private const STATUS_MAP = [
        'order.paid' => 'COMPLETED',
        'order.pending' => 'PENDING',
        'order.cancelled' => 'FAILED',
        'order.chargeback' => 'DECLINED',
        'order.refunded' => 'REFUNDED',
    ];

    public function __construct(private PushcutService $pushcut) {}

    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');
        $status = self::STATUS_MAP[$event] ?? null;

        if ($status === null) {
            return response()->json(['ok' => true]);
        }

        $checkoutParams = $request->input('order.checkout_params');

        $user = $this->resolveUser($checkoutParams);

        if (! $user) {
            return response()->json(['ok' => true]);
        }

        $orderId = (string) $request->input('order.id');

        $order = CartpandaOrder::firstOrNew(['cartpanda_order_id' => $orderId]);

        if ($order->exists && $order->isTerminal()) {
            return response()->json(['ok' => true]);
        }

        $order->fill([
            'user_id' => $user->id,
            'amount' => $request->input('order.payment.actual_price_paid'),
            'currency' => 'USD',
            'status' => $status,
            'event' => $event,
            'payer_email' => $request->input('order.customer.email'),
            'payer_name' => $request->input('order.customer.full_name'),
            'payload' => $request->all(),
        ]);

        $order->save();

        $this->maybeNotify($user, $order, $status);

        return response()->json(['ok' => true]);
    }

    private function resolveUser(mixed $checkoutParams): ?User
    {
        if (! is_array($checkoutParams) || empty($checkoutParams)) {
            return null;
        }

        return User::whereIn('cartpanda_param', array_keys($checkoutParams))->first();
    }

    private function maybeNotify(User $user, CartpandaOrder $order, string $status): void
    {
        if (! $user->pushcut_url) {
            return;
        }

        $notify = $user->pushcut_notify;

        $shouldNotify = match ($status) {
            'COMPLETED' => in_array($notify, ['all', 'paid'], true),
            'PENDING' => in_array($notify, ['all', 'created'], true),
            default => false,
        };

        if ($shouldNotify) {
            $this->pushcut->send($user->pushcut_url, "Cartpanda Order {$status}", [
                'amount' => $order->amount,
                'order_id' => $order->cartpanda_order_id,
                'status' => $status,
            ]);
        }
    }
}
