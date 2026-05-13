<?php

namespace App\Services;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class AffiliateRouter
{
    private const TOKEN_TTL_SECONDS = 600;

    /**
     * Phase 1: pick shop by cap waterfall and mint a fresh token.
     * Used by ClickRouterController (/r/{cartpanda_param}).
     *
     * @return array{shop_slug?: string, ck_url?: string, token?: string, error?: string, fallback_url?: ?string}
     */
    public function pickShop(string $cartpandaParam): array
    {
        $user = User::where('cartpanda_param', $cartpandaParam)->first();
        if (! $user) {
            return $this->error('affiliate_not_found');
        }

        $shops = CartpandaShop::where('active_for_routing', true)
            ->orderByRaw('routing_priority IS NULL, routing_priority ASC, id ASC')
            ->get();

        if ($shops->isEmpty()) {
            return $this->error('no_active_shops');
        }

        $picked = $this->pickFirstWithCap($shops);
        if (! $picked) {
            return $this->error('all_capped');
        }

        return [
            'shop_slug' => $picked->shop_slug,
            'ck_url' => $picked->ckUrl(),
            'token' => $this->mintToken($user->id),
        ];
    }

    /**
     * Phase 2: decode token, build final checkout URL for a specific shop.
     * Used by AffiliateClickController (/api/click/{token}?shop={slug}).
     *
     * @return array{url?: string, shop_slug?: string, error?: string, fallback_url?: ?string}
     */
    public function resolve(string $token, string $shopSlug): array
    {
        $decoded = $this->decodeToken($token);
        if ($decoded === null) {
            return $this->error('invalid_or_expired_token');
        }

        $user = User::find($decoded['uid']);
        if (! $user) {
            return $this->error('affiliate_not_found');
        }

        $shop = CartpandaShop::where('shop_slug', $shopSlug)
            ->where('active_for_routing', true)
            ->first();

        if (! $shop) {
            return $this->error('shop_not_active');
        }

        if (! $shop->default_checkout_template) {
            return $this->error('no_checkout_template');
        }

        $separator = str_contains($shop->default_checkout_template, '?') ? '&' : '?';
        $url = $shop->default_checkout_template.$separator.'affiliate='.urlencode($user->cartpanda_param);

        return [
            'url' => $url,
            'shop_slug' => $shop->shop_slug,
        ];
    }

    public function mintToken(int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $userId,
            'ts' => now()->getTimestamp(),
            'n' => Str::random(8),
        ]));
    }

    /**
     * @return array{uid: int, ts: int}|null
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $raw = Crypt::decryptString($token);
        } catch (DecryptException $e) {
            return null;
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload) || ! isset($payload['uid'], $payload['ts'])) {
            return null;
        }

        if (now()->getTimestamp() - (int) $payload['ts'] > self::TOKEN_TTL_SECONDS) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  Collection<int, CartpandaShop>  $shops
     */
    private function pickFirstWithCap(Collection $shops): ?CartpandaShop
    {
        $periodStart = now()->startOfDay();

        foreach ($shops as $shop) {
            if ($shop->daily_cap === null) {
                return $shop;
            }

            $consumed = (float) CartpandaOrder::where('shop_id', $shop->id)
                ->where('status', 'COMPLETED')
                ->where('created_at', '>=', $periodStart)
                ->sum('amount');

            if ($consumed < (float) $shop->daily_cap) {
                return $shop;
            }
        }

        return null;
    }

    /**
     * @return array{error: string, fallback_url: ?string}
     */
    private function error(string $code): array
    {
        return [
            'error' => $code,
            'fallback_url' => config('routing.default_fallback'),
        ];
    }
}
