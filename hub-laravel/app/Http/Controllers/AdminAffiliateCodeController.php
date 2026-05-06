<?php

namespace App\Http\Controllers;

use App\Models\AffiliateCode;
use App\Models\ShopPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAffiliateCodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AffiliateCode::with(['user:id,email,cartpanda_param', 'pool:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (AffiliateCode $c) => $this->format($c)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:affiliate_codes,code'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'shop_pool_id' => ['required', 'integer', 'exists:shop_pools,id'],
            'label' => ['nullable', 'string', 'max:120'],
            'active' => ['boolean'],
        ]);

        $this->ensurePoolBelongsToUser($data['shop_pool_id'], $data['user_id']);

        $code = AffiliateCode::create($data);
        $code->load(['user:id,email,cartpanda_param', 'pool:id,name']);

        return response()->json(['data' => $this->format($code)], 201);
    }

    public function update(Request $request, AffiliateCode $affiliateCode): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('affiliate_codes', 'code')->ignore($affiliateCode->id)],
            'shop_pool_id' => ['sometimes', 'integer', 'exists:shop_pools,id'],
            'label' => ['nullable', 'string', 'max:120'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['shop_pool_id'])) {
            $this->ensurePoolBelongsToUser($data['shop_pool_id'], $affiliateCode->user_id);
        }

        $affiliateCode->update($data);
        $affiliateCode->load(['user:id,email,cartpanda_param', 'pool:id,name']);

        return response()->json(['data' => $this->format($affiliateCode)]);
    }

    public function destroy(AffiliateCode $affiliateCode): JsonResponse
    {
        $affiliateCode->delete();

        return response()->json(['message' => 'Code deleted']);
    }

    private function ensurePoolBelongsToUser(int $poolId, int $userId): void
    {
        $belongs = ShopPool::where('id', $poolId)->where('user_id', $userId)->exists();
        abort_if(! $belongs, 422, 'Pool does not belong to specified user');
    }

    /**
     * @return array<string, mixed>
     */
    private function format(AffiliateCode $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'user_id' => $c->user_id,
            'user_email' => $c->user?->email,
            'cartpanda_param' => $c->user?->cartpanda_param,
            'shop_pool_id' => $c->shop_pool_id,
            'pool_name' => $c->pool?->name,
            'label' => $c->label,
            'active' => $c->active,
            'clicks' => $c->clicks,
            'created_at' => $c->created_at,
        ];
    }
}
