<?php

namespace App\Http\Controllers;

use App\Models\TiktokPixel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TiktokPixelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pixels = $request->user()->tiktokPixels()->orderBy('created_at')->get();

        return response()->json(['data' => $pixels]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pixel_code' => ['required', 'string', 'max:64'],
            'access_token' => ['required', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:100'],
            'test_event_code' => ['nullable', 'string', 'max:50'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $pixel = $request->user()->tiktokPixels()->create($data);

        return response()->json(['data' => $pixel], 201);
    }

    public function update(Request $request, TiktokPixel $tiktokPixel): JsonResponse
    {
        if ($tiktokPixel->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'pixel_code' => ['sometimes', 'string', 'max:64'],
            'access_token' => ['sometimes', 'string', 'max:500'],
            'label' => ['nullable', 'string', 'max:100'],
            'test_event_code' => ['nullable', 'string', 'max:50'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $tiktokPixel->update($data);

        return response()->json(['data' => $tiktokPixel->fresh()]);
    }

    public function destroy(Request $request, TiktokPixel $tiktokPixel): JsonResponse
    {
        if ($tiktokPixel->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $tiktokPixel->delete();

        return response()->json(['ok' => true]);
    }
}
