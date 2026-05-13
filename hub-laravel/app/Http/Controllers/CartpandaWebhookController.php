<?php

namespace App\Http\Controllers;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\RevenueMilestone;
use App\Models\User;
use App\Models\UserMilestoneAchievement;
use App\Models\UserPushcutUrl;
use App\Models\WebhookLog;
use App\Services\BalanceService;
use App\Services\FacebookConversionsService;
use App\Services\PushcutService;
use App\Services\TiktokEventsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CartpandaWebhookController extends Controller
{
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'order.paid' => 'COMPLETED',
        'order.created' => 'PENDING',
        'order.cancelled' => 'FAILED',
        'order.chargeback' => 'DECLINED',
        'order.refunded' => 'REFUNDED',
    ];

    public function __construct(
        private PushcutService $pushcut,
        private BalanceService $balance,
        private FacebookConversionsService $facebook,
        private TiktokEventsService $tiktok,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $request->isJson()) {
            $request->merge((array) json_decode($request->getContent(), true));
        }

        Log::error('cartpanda_webhook', [
            'content_type' => $request->header('Content-Type'),
            'event' => $request->input('event'),
            'order_id' => $request->input('order.id'),
            'checkout_params' => $request->input('order.checkout_params'),
        ]);

        $log = WebhookLog::create([
            'event' => $request->input('event'),
            'cartpanda_order_id' => $request->input('order.id') ? (string) $request->input('order.id') : null,
            'shop_slug' => $request->input('order.shop.slug'),
            'status' => 'ignored',
            'status_reason' => null,
            'payload' => $request->all(),
            'ip_address' => $request->ip(),
        ]);

        try {
            $event = (string) $request->input('event');
            $status = self::STATUS_MAP[$event] ?? null;

            if ($status === null) {
                $log->update(['status_reason' => 'unknown_event']);

                return response()->json(['ok' => true]);
            }

            $isChargebackEvent = in_array($status, ['DECLINED', 'REFUNDED']);
            $orderId = (string) $request->input('order.id');

            $user = $this->resolveUser($request->input('order.checkout_params'))
                ?? ($isChargebackEvent ? $this->resolveUserFromExistingOrder($orderId) : null);

            $usedDefault = false;
            if (! $user) {
                $user = $this->resolveDefaultUser();
                $usedDefault = $user !== null;
            }

            if (! $user) {
                $log->update(['status_reason' => 'user_not_found']);

                return response()->json(['ok' => true]);
            }

            if ($usedDefault) {
                $log->update(['status_reason' => 'default_user_fallback']);
                Log::warning('cartpanda_default_user_fallback', [
                    'order_id' => $orderId,
                    'shop_slug' => $request->input('order.shop.slug'),
                    'checkout_params' => $request->input('order.checkout_params'),
                    'event' => $event,
                    'fallback_user_id' => $user->id,
                ]);
            }

            $user->load('pushcutUrls');

            $order = CartpandaOrder::firstOrNew(['cartpanda_order_id' => $orderId]);

            if ($isChargebackEvent && ! $order->exists) {
                $log->update(['status_reason' => 'original_order_not_found']);

                return response()->json(['ok' => true]);
            }

            if ($order->exists && $order->isTerminal()) {
                if ($isChargebackEvent && ! in_array($order->status, ['DECLINED', 'REFUNDED'])) {
                    $this->applyBalanceEffect($user, $order, $status);
                    $order->update(['status' => $status, 'event' => $event]);
                    $log->update(['status' => 'processed', 'status_reason' => 'chargeback_applied']);
                } else {
                    $log->update(['status_reason' => 'already_terminal']);
                }

                return response()->json(['ok' => true]);
            }

            $shop = $this->resolveShop($request->input('order.shop'));

            if ($shop) {
                $user->shops()->syncWithoutDetaching([$shop->id]);
            }

            $exchangeRate = (float) $request->input('order.payment.actual_exchange_rate');
            $sellerSplit = (float) $request->input('order.all_payments.0.seller_split_amount');
            $sellerAllowance = (float) $request->input('order.all_payments.0.seller_allowance_amount');

            $amount = round($sellerSplit * $exchangeRate, 6);
            $reserveAmount = $sellerAllowance > 0
                ? round($sellerAllowance * $exchangeRate, 6)
                : round($amount * config('cartpanda.reserve_rate_fallback'), 6);

            $order->fill([
                'user_id' => $user->id,
                'shop_id' => $shop?->id,
                'amount' => $amount,
                'reserve_amount' => $reserveAmount,
                'currency' => 'USD',
                'status' => $status,
                'event' => $event,
                'payer_email' => $request->input('order.customer.email'),
                'payer_name' => $request->input('order.customer.full_name'),
                'payload' => $request->all(),
            ]);

            if ($status === 'COMPLETED' && $order->release_eligible_at === null) {
                $order->release_eligible_at = now()->addDays(2);
            }

            $order->save();

            $this->applyBalanceEffect($user, $order, $status);
            $this->maybeNotify($user, $order, $status);

            if ($status === 'COMPLETED') {
                $this->maybeSendFacebookEvent($user, $order, $request);
                $this->maybeSendTiktokEvent($user, $request);
                $this->checkMilestones($user->id);
            }

            $log->update(['status' => 'processed']);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'status_reason' => substr($e->getMessage(), 0, 255)]);
        }

        return response()->json(['ok' => true]);
    }

    private function resolveUser(mixed $checkoutParams): ?User
    {
        if (! is_array($checkoutParams) || empty($checkoutParams)) {
            return null;
        }

        return User::whereIn('cartpanda_param', array_values($checkoutParams))->first();
    }

    private function resolveUserFromExistingOrder(string $orderId): ?User
    {
        return CartpandaOrder::where('cartpanda_order_id', $orderId)
            ->with('user')
            ->first()
            ?->user;
    }

    /**
     * Fallback user used when checkout_params is empty (organic traffic, direct
     * links, etc.). Configured via CARTPANDA_DEFAULT_USER_EMAIL.
     */
    private function resolveDefaultUser(): ?User
    {
        $email = config('cartpanda.default_user_email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return User::where('email', $email)->first();
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
            'DECLINED' => $this->balance->debitOnChargeback($user, $order, applyPenalty: true),
            'REFUNDED' => $this->balance->debitOnChargeback($user, $order, applyPenalty: false),
            default => null,
        };
    }

    private function maybeSendFacebookEvent(User $user, CartpandaOrder $order, Request $request): void
    {
        if ($user->facebook_pixel_id === null || $user->facebook_access_token === null) {
            return;
        }

        $fullName = (string) $request->input('order.customer.full_name');
        $nameParts = preg_split('/\s+/', trim($fullName), 2);

        $this->facebook->sendPurchaseEvent(
            pixelId: $user->facebook_pixel_id,
            accessToken: $user->facebook_access_token,
            orderId: $order->cartpanda_order_id,
            value: (float) $order->amount,
            currency: $order->currency ?? 'USD',
            userData: [
                'email' => $request->input('order.customer.email'),
                'first_name' => $nameParts[0] ?? null,
                'last_name' => $nameParts[1] ?? null,
            ],
        );
    }

    private function maybeSendTiktokEvent(User $user, Request $request): void
    {
        $pixels = $user->tiktokPixels()->where('enabled', true)->get();

        if ($pixels->isEmpty()) {
            return;
        }

        $this->tiktok->sendPurchaseEvent($pixels, (array) $request->input('order'));
    }

    private function maybeNotify(User $user, CartpandaOrder $order, string $status): void
    {
        $title = match ($status) {
            'COMPLETED' => 'Venda Aprovada - '.$user->email,
            default => "Order {$status}",
        };

        $user->pushcutUrls
            ->filter(fn (UserPushcutUrl $dest) => match ($status) {
                'COMPLETED' => in_array($dest->notify, ['all', 'paid'], true),
                'PENDING' => in_array($dest->notify, ['all', 'created'], true),
                default => false,
            })
            ->each(fn (UserPushcutUrl $dest) => $this->pushcut->send($dest->url, $title, [
                'amount' => $order->amount,
                'order_id' => $order->cartpanda_order_id,
                'status' => $status,
            ]));
    }

    private function checkMilestones(int $userId): void
    {
        $total = (float) CartpandaOrder::where('user_id', $userId)
            ->where('status', 'COMPLETED')
            ->sum('amount');

        $achievedIds = UserMilestoneAchievement::where('user_id', $userId)
            ->pluck('milestone_id')
            ->all();

        $unachieved = RevenueMilestone::orderBy('order')
            ->whereNotIn('id', $achievedIds)
            ->where('value', '<=', $total)
            ->get();

        foreach ($unachieved as $milestone) {
            UserMilestoneAchievement::firstOrCreate(
                ['user_id' => $userId, 'milestone_id' => $milestone->id],
                ['total_at_achievement' => $milestone->value, 'achieved_at' => now()]
            );
        }
    }
}
