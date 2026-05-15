<?php

namespace App\Http\Controllers;

use App\Models\MundpayOrder;
use App\Models\MundpayWebhookLog;
use App\Models\User;
use App\Models\UserPushcutUrl;
use App\Services\BalanceService;
use App\Services\FacebookConversionsService;
use App\Services\PushcutService;
use App\Services\TiktokEventsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MundpayWebhookController extends Controller
{
    /** @var array<string, string> */
    private const STATUS_MAP = [
        'order.paid' => 'COMPLETED',
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

        $payload = $request->all();
        $event = (string) ($payload['event_type'] ?? '');
        $orderId = isset($payload['id']) ? (string) $payload['id'] : null;

        $log = MundpayWebhookLog::create([
            'event' => $event !== '' ? $event : null,
            'mundpay_order_id' => $orderId,
            'status' => 'ignored',
            'status_reason' => null,
            'payload' => $payload,
            'ip_address' => $request->ip(),
        ]);

        try {
            $status = self::STATUS_MAP[$event] ?? null;

            if ($status === null) {
                $log->update(['status_reason' => 'unknown_event']);

                return response()->json(['ok' => true]);
            }

            $resolution = $this->resolveUser($payload);
            $user = $resolution['user'];

            if (! $user) {
                $log->update(['status_reason' => 'user_not_found']);

                return response()->json(['ok' => true]);
            }

            if ($resolution['used_fallback']) {
                Log::warning('mundpay_default_user_fallback', [
                    'order_id' => $orderId,
                    'event' => $event,
                    'affiliate' => data_get($payload, 'tracking.affiliate'),
                    'fallback_user_id' => $user->id,
                ]);
            }

            $user->load('pushcutUrls');

            $order = MundpayOrder::firstOrNew(['mundpay_order_id' => $orderId]);
            $isRefund = $status === 'REFUNDED';

            if ($isRefund && ! $order->exists) {
                $log->update(['status_reason' => 'original_order_not_found']);

                return response()->json(['ok' => true]);
            }

            if ($order->exists && $order->isTerminal()) {
                if ($isRefund && $order->status !== 'REFUNDED') {
                    $this->balance->debitOnChargebackForMundpay($user, $order, applyPenalty: false);
                    $order->update(['status' => $status, 'event' => $event, 'chargeback_at' => $payload['chargeback_at'] ?? now()]);
                    $log->update(['status' => 'processed', 'status_reason' => 'chargeback_applied']);
                } else {
                    $log->update(['status_reason' => 'already_terminal']);
                }

                return response()->json(['ok' => true]);
            }

            $rate = (float) config('mundpay.brl_usd_rate');
            $amount = $rate > 0 ? ((int) ($payload['net_amount'] ?? 0)) / 100 / $rate : 0.0;
            $reserve = round($amount * (float) config('mundpay.reserve_rate'), 6);

            $order->fill([
                'user_id' => $user->id,
                'mundpay_ref' => $payload['ref'] ?? null,
                'amount' => $amount,
                'reserve_amount' => $reserve,
                'currency' => 'USD',
                'status' => $status,
                'event' => $event,
                'payment_method' => $payload['payment_method'] ?? null,
                'payer_email' => data_get($payload, 'customer.email'),
                'payer_name' => data_get($payload, 'customer.name'),
                'payer_phone' => data_get($payload, 'customer.phone'),
                'payer_document' => data_get($payload, 'customer.document'),
                'paid_at' => $payload['paid_at'] ?? null,
                'chargeback_at' => $payload['chargeback_at'] ?? null,
                'payload' => $payload,
            ]);

            if ($status === 'COMPLETED' && $order->release_eligible_at === null) {
                $paidAt = $order->paid_at ?? now();
                $order->release_eligible_at = $paidAt->copy()->addDays((int) config('mundpay.release_delay_days'));
            }

            $order->save();

            if ($status === 'COMPLETED') {
                $this->balance->creditPendingForMundpay($user, $order);
                $this->maybeSendFacebookEvent($user, $order, $payload);
                $this->maybeSendTiktokEvent($user, $payload);
            } elseif ($status === 'REFUNDED') {
                $this->balance->debitOnChargebackForMundpay($user, $order, applyPenalty: false);
            }

            $this->maybeNotify($user, $order, $status);

            $log->update([
                'status' => 'processed',
                'status_reason' => $resolution['used_fallback'] ? 'default_user_fallback' : null,
            ]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'status_reason' => substr($e->getMessage(), 0, 255)]);
            Log::warning('mundpay_webhook_exception', [
                'order_id' => $orderId,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Resolve por affiliate slug primeiro, depois pelo email fallback do config.
     *
     * @param  array<string, mixed>  $payload
     * @return array{user: ?User, used_fallback: bool}
     */
    private function resolveUser(array $payload): array
    {
        $affiliate = (string) data_get($payload, 'tracking.affiliate', '');
        $affiliate = trim($affiliate);

        if ($affiliate !== '') {
            // Defensivo: corta sufixo opcional após '?' caso o gateway algum dia anexe metadata.
            $slug = explode('?', $affiliate, 2)[0];
            $slug = trim($slug);

            if ($slug !== '') {
                $user = User::where('cartpanda_param', $slug)->first();
                if ($user) {
                    return ['user' => $user, 'used_fallback' => false];
                }
            }
        }

        $email = config('mundpay.user_email');
        if (! is_string($email) || $email === '') {
            return ['user' => null, 'used_fallback' => false];
        }

        $user = User::where('email', $email)->first();

        return ['user' => $user, 'used_fallback' => $user !== null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function maybeSendFacebookEvent(User $user, MundpayOrder $order, array $payload): void
    {
        if ($user->facebook_pixel_id === null || $user->facebook_access_token === null) {
            return;
        }

        $fullName = (string) data_get($payload, 'customer.name', '');
        $nameParts = preg_split('/\s+/', trim($fullName), 2);

        $this->facebook->sendPurchaseEvent(
            pixelId: $user->facebook_pixel_id,
            accessToken: $user->facebook_access_token,
            orderId: $order->mundpay_order_id,
            value: (float) $order->amount,
            currency: $order->currency ?? 'USD',
            userData: [
                'email' => data_get($payload, 'customer.email'),
                'first_name' => $nameParts[0] ?? null,
                'last_name' => $nameParts[1] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function maybeSendTiktokEvent(User $user, array $payload): void
    {
        $pixels = $user->tiktokPixels()->where('enabled', true)->get();

        Log::info('mundpay_tiktok_dispatch', [
            'user_id' => $user->id,
            'order_id' => data_get($payload, 'id'),
            'pixels_count' => $pixels->count(),
            'pixel_ids' => $pixels->pluck('id')->all(),
            'ttclid_len' => strlen((string) data_get($payload, 'tracking.ttclid', '')),
        ]);

        if ($pixels->isEmpty()) {
            return;
        }

        $this->tiktok->sendPurchaseEventForMundpay($pixels, $payload);
    }

    private function maybeNotify(User $user, MundpayOrder $order, string $status): void
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
                'order_id' => $order->mundpay_order_id,
                'status' => $status,
            ]));
    }
}
