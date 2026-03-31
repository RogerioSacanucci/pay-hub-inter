<?php

namespace App\Http\Controllers;

use App\Models\UserPushcutUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushcutUrlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $urls = $request->user()->pushcutUrls()->orderBy('created_at')->get();

        return response()->json(['data' => $urls]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'notify' => ['required', 'in:all,created,paid'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $url = $request->user()->pushcutUrls()->create($data);

        return response()->json(['data' => $url], 201);
    }

    public function update(Request $request, UserPushcutUrl $pushcutUrl): JsonResponse
    {
        if ($pushcutUrl->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'url' => ['sometimes', 'url', 'max:500'],
            'notify' => ['sometimes', 'in:all,created,paid'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $pushcutUrl->update($data);

        return response()->json(['data' => $pushcutUrl->fresh()]);
    }

    public function destroy(Request $request, UserPushcutUrl $pushcutUrl): JsonResponse
    {
        if ($pushcutUrl->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $pushcutUrl->delete();

        return response()->json(['ok' => true]);
    }
}
