<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserShopController extends Controller
{
    public function store(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        $data = $request->validate([
            'shop_id' => ['required', 'integer', 'exists:cartpanda_shops,id'],
        ]);

        $user->shops()->syncWithoutDetaching([$data['shop_id']]);

        return response()->json(['message' => 'Shop attached'], 201);
    }

    public function destroy(int $userId, int $shopId): JsonResponse
    {
        $user = User::findOrFail($userId);
        $user->shops()->detach($shopId);

        return response()->json(['message' => 'Shop detached']);
    }
}
