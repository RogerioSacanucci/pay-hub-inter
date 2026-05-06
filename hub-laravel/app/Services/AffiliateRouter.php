<?php

namespace App\Services;

use App\Models\AffiliateCode;
use App\Models\CartpandaOrder;
use App\Models\ShopPool;
use App\Models\ShopPoolTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AffiliateRouter
{
    /**
     * Resolve a click code into a final checkout URL.
     *
     * @return array{url?: string, shop_slug?: string, code?: string, error?: string, fallback_url?: string}
     */
    public function resolve(string $code): array
    {
        $affiliateCode = AffiliateCode::with(['user', 'pool.targets.shop'])
            ->where('code', $code)
            ->where('active', true)
            ->first();

        if (! $affiliateCode) {
            return $this->error('code_not_found');
        }

        $pool = $affiliateCode->pool;

        $targets = $pool->targets
            ->where('active', true)
            ->sortBy('priority')
            ->values();

        if ($targets->isEmpty()) {
            return $this->error('no_active_targets');
        }

        $picked = $this->pickTarget($targets, $pool);

        if (! $picked) {
            return $this->error('all_capped');
        }

        $template = $picked->checkout_template ?? $picked->shop?->default_checkout_template;

        if (! $template) {
            return $this->error('no_checkout_template');
        }

        $tag = $affiliateCode->user->cartpanda_param;
        $separator = str_contains($template, '?') ? '&' : '?';
        $url = $template.$separator.'affiliate='.$tag;

        DB::transaction(function () use ($affiliateCode, $picked) {
            $affiliateCode->increment('clicks');
            $picked->increment('clicks');
        });

        return [
            'url' => $url,
            'shop_slug' => $picked->shop->shop_slug,
            'code' => $affiliateCode->code,
        ];
    }

    /**
     * Walk targets in priority order, returning the first that has remaining cap.
     * If all are capped, return the overflow target if defined.
     *
     * @param  Collection<int, ShopPoolTarget>  $targets
     */
    private function pickTarget(Collection $targets, ShopPool $pool): ?ShopPoolTarget
    {
        $periodStart = $this->periodStart($pool->cap_period);

        foreach ($targets as $target) {
            if ($target->daily_cap === null) {
                return $target;
            }

            $consumed = (float) CartpandaOrder::where('shop_id', $target->shop_id)
                ->where('status', 'COMPLETED')
                ->where('created_at', '>=', $periodStart)
                ->sum('amount');

            if ($consumed < (float) $target->daily_cap) {
                return $target;
            }
        }

        return $targets->firstWhere('is_overflow', true);
    }

    private function periodStart(string $capPeriod): Carbon
    {
        return match ($capPeriod) {
            'hour' => now()->startOfHour(),
            'week' => now()->startOfWeek(),
            default => now()->startOfDay(),
        };
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
