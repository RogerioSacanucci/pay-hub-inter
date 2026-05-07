<?php

namespace App\Http\Controllers;

use App\Models\ShopPool;
use App\Models\ShopPoolTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminShopPoolTargetController extends Controller
{
    public function store(Request $request, ShopPool $shopPool): JsonResponse
    {
        $data = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:cartpanda_shops,id'],
            'checkout_template' => ['nullable', 'url', 'max:500'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'daily_cap' => ['nullable', 'numeric', 'min:0'],
            'is_overflow' => ['boolean'],
            'active' => ['boolean'],
        ]);

        if ($data['is_overflow'] ?? false) {
            $this->ensureSingleOverflow($shopPool->id);
        }

        $target = $shopPool->targets()->create($data);
        $target->load('shop:id,shop_slug,name,default_checkout_template,daily_cap');

        return response()->json(['data' => $this->format($target)], 201);
    }

    public function update(Request $request, ShopPool $shopPool, ShopPoolTarget $target): JsonResponse
    {
        abort_if($target->shop_pool_id !== $shopPool->id, 404);

        $data = $request->validate([
            'shop_id' => ['sometimes', 'integer', 'exists:cartpanda_shops,id'],
            'checkout_template' => ['nullable', 'url', 'max:500'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'daily_cap' => ['nullable', 'numeric', 'min:0'],
            'is_overflow' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (($data['is_overflow'] ?? false) && ! $target->is_overflow) {
            $this->ensureSingleOverflow($shopPool->id, exceptId: $target->id);
        }

        $target->update($data);
        $target->load('shop:id,shop_slug,name,default_checkout_template,daily_cap');

        return response()->json(['data' => $this->format($target)]);
    }

    public function destroy(ShopPool $shopPool, ShopPoolTarget $target): JsonResponse
    {
        abort_if($target->shop_pool_id !== $shopPool->id, 404);

        $target->delete();

        return response()->json(['message' => 'Target deleted']);
    }

    private function ensureSingleOverflow(int $poolId, ?int $exceptId = null): void
    {
        $query = ShopPoolTarget::where('shop_pool_id', $poolId)->where('is_overflow', true);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        abort_if($query->exists(), 422, 'Pool already has an overflow target');
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ShopPoolTarget $t): array
    {
        $shopDefault = $t->shop?->default_checkout_template;

        return [
            'id' => $t->id,
            'shop_pool_id' => $t->shop_pool_id,
            'shop_id' => $t->shop_id,
            'shop_slug' => $t->shop?->shop_slug,
            'shop_name' => $t->shop?->name,
            'shop_default_checkout_template' => $shopDefault,
            'shop_daily_cap' => $t->shop?->daily_cap,
            'checkout_template' => $t->checkout_template,
            'effective_checkout_template' => $t->checkout_template ?? $shopDefault,
            'priority' => $t->priority,
            'daily_cap' => $t->daily_cap,
            'is_overflow' => $t->is_overflow,
            'active' => $t->active,
            'clicks' => $t->clicks,
        ];
    }
}
