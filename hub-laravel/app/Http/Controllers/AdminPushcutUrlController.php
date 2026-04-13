<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserPushcutUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPushcutUrlController extends Controller
{
    public function index(User $user): JsonResponse
    {
        $urls = $user->pushcutUrls()->where('admin_only', true)->orderBy('created_at')->get();

        return response()->json(['data' => $urls]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'notify' => ['required', 'in:all,created,paid'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $url = $user->pushcutUrls()->create([...$data, 'admin_only' => true]);

        return response()->json(['data' => $url], 201);
    }

    public function destroy(UserPushcutUrl $pushcutUrl): JsonResponse
    {
        if (! $pushcutUrl->admin_only) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $pushcutUrl->delete();

        return response()->json(['ok' => true]);
    }
}
