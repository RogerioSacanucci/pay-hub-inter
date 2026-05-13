<?php

namespace App\Services;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class AffiliateRouter
{
    /**
     * Resolve an affiliate routing request: pick the next shop via cap waterfall
     * and build the final checkout URL with the affiliate tag appended.
     *
     * Called exclusively by RouterApiController (the standalone router app).
     * /ck does NOT call this — it receives the final URL via the router.
     *
     * @return array{shop_slug?: string, ck_url?: string, final_url?: string, error?: string, fallback_url?: ?string}
     */
    public function resolve(string $cartpandaParam): array
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

        if (! $picked->default_checkout_template) {
            return $this->error('no_checkout_template');
        }

        $affiliateToken = $this->mintAffiliateToken($user->id);

        $separator = str_contains($picked->default_checkout_template, '?') ? '&' : '?';
        $finalUrl = $picked->default_checkout_template.$separator.'affiliate='.urlencode($affiliateToken);

        return [
            'shop_slug' => $picked->shop_slug,
            'ck_url' => $picked->ckUrl(),
            'final_url' => $finalUrl,
        ];
    }

    /**
     * Encrypt {uid, ts, nonce} so the CartPanda webhook can later identify the
     * affiliate without exposing cartpanda_param in the checkout URL.
     *
     * Stateless — no DB table needed. Each call produces a distinct ciphertext.
     */
    public function mintAffiliateToken(int $userId): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => $userId,
            'ts' => now()->getTimestamp(),
            'n' => Str::random(8),
        ]));
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
