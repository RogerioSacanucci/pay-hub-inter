<?php

namespace App\Http\Controllers;

use App\Models\ShopPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminShopPoolController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ShopPool::with(['user:id,email', 'targets.shop:id,shop_slug,name,default_checkout_template,daily_cap'])
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (ShopPool $p) => $this->format($p)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'cap_period' => ['nullable', Rule::in(['day', 'hour', 'week'])],
        ]);

        Rule::unique('shop_pools', 'name')->where('user_id', $data['user_id']);

        $exists = ShopPool::where('user_id', $data['user_id'])->where('name', $data['name'])->exists();
        abort_if($exists, 422, 'Pool name already exists for this user');

        $pool = ShopPool::create($data);
        $pool->load(['user:id,email', 'targets.shop:id,shop_slug,name,default_checkout_template,daily_cap']);

        return response()->json(['data' => $this->format($pool)], 201);
    }

    public function update(Request $request, ShopPool $shopPool): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'cap_period' => ['sometimes', Rule::in(['day', 'hour', 'week'])],
        ]);

        if (isset($data['name']) && $data['name'] !== $shopPool->name) {
            $exists = ShopPool::where('user_id', $shopPool->user_id)
                ->where('name', $data['name'])
                ->where('id', '!=', $shopPool->id)
                ->exists();
            abort_if($exists, 422, 'Pool name already exists for this user');
        }

        $shopPool->update($data);
        $shopPool->load(['user:id,email', 'targets.shop:id,shop_slug,name,default_checkout_template,daily_cap']);

        return response()->json(['data' => $this->format($shopPool)]);
    }

    public function destroy(ShopPool $shopPool): JsonResponse
    {
        $codeCount = $shopPool->affiliateCodes()->count();
        abort_if($codeCount > 0, 422, "Pool has {$codeCount} affiliate code(s) pointing to it. Reassign or delete them first.");

        $shopPool->delete();

        return response()->json(['message' => 'Pool deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function format(ShopPool $p): array
    {
        return [
            'id' => $p->id,
            'user_id' => $p->user_id,
            'user_email' => $p->user?->email,
            'name' => $p->name,
            'description' => $p->description,
            'cap_period' => $p->cap_period,
            'targets' => $p->targets->map(fn ($t) => [
                'id' => $t->id,
                'shop_id' => $t->shop_id,
                'shop_slug' => $t->shop?->shop_slug,
                'shop_name' => $t->shop?->name,
                'shop_default_checkout_template' => $t->shop?->default_checkout_template,
                'shop_daily_cap' => $t->shop?->daily_cap,
                'checkout_template' => $t->checkout_template,
                'effective_checkout_template' => $t->checkout_template ?? $t->shop?->default_checkout_template,
                'priority' => $t->priority,
                'daily_cap' => $t->daily_cap,
                'is_overflow' => $t->is_overflow,
                'active' => $t->active,
                'clicks' => $t->clicks,
            ]),
            'created_at' => $p->created_at,
        ];
    }
}
