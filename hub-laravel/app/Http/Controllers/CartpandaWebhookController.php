<?php

namespace App\Http\Controllers;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\PushcutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CartpandaWebhookController extends Controller
{
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'order.paid' => 'COMPLETED',
        'order.pending' => 'PENDING',
        'order.cancelled' => 'FAILED',
        'order.chargeback' => 'DECLINED',
        'order.refunded' => 'REFUNDED',
    ];

    public function __construct(
        private PushcutService $pushcut,
        private BalanceService $balance,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $request->isJson()) {
            $request->merge((array) json_decode($request->getContent(), true));
        }

        Log::info('cartpanda_webhook', [
            'content_type' => $request->header('Content-Type'),
            'event'        => $request->input('event'),
            'order_id'     => $request->input('order.id'),
            'checkout_params' => $request->input('order.checkout_params'),
        ]);

        $event = (string) $request->input('event');
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

        $isChargebackEvent = in_array($status, ['DECLINED', 'REFUNDED']);

        if ($order->exists && $order->isTerminal()) {
            // Allow chargebacks on COMPLETED orders: debit balance without re-saving the order.
            // Block if already in a chargeback terminal state (idempotency).
            if ($isChargebackEvent && ! in_array($order->status, ['DECLINED', 'REFUNDED'])) {
                $this->applyBalanceEffect($user, $order, $status);
            }

            return response()->json(['ok' => true]);
        }

        $shop = $this->resolveShop($request->input('order.shop'));

        if ($shop) {
            $user->shops()->syncWithoutDetaching([$shop->id]);
        }

        $order->fill([
            'user_id' => $user->id,
            'shop_id' => $shop?->id,
            'amount' => $request->input('order.payment.actual_price_paid'),
            'currency' => 'USD',
            'status' => $status,
            'event' => $event,
            'payer_email' => $request->input('order.customer.email'),
            'payer_name' => $request->input('order.customer.full_name'),
            'payload' => $request->all(),
        ]);

        $order->save();

        $this->applyBalanceEffect($user, $order, $status);
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

    /**
     * Find or create a shop from the webhook's order.shop data.
     * Uses cartpanda_shop_id (numeric) as the stable unique key.
     * Updates slug and name on every call so they stay fresh.
     */
    private function resolveShop(mixed $shopData): ?CartpandaShop
    {
        if (! is_array($shopData) || empty($shopData['id'])) {
            return null;
        }

        return CartpandaShop::updateOrCreate(
            ['cartpanda_shop_id' => (string) $shopData['id']],
            [
                'shop_slug' => (string) ($shopData['slug'] ?? ''),
                'name' => (string) ($shopData['name'] ?? ''),
            ]
        );
    }

    private function applyBalanceEffect(User $user, CartpandaOrder $order, string $status): void
    {
        match ($status) {
            'COMPLETED' => $this->balance->creditPending($user, $order),
            'DECLINED', 'REFUNDED' => $this->balance->debitOnChargeback($user, $order),
            default => null,
        };
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
