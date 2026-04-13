<?php

namespace App\Http\Controllers;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminCheckoutPreviewController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $exists = CheckoutPreview::where('user_id', $user->id)->exists();

        return response()->json(['has_preview' => $exists]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:html,htm', 'max:2048'],
        ]);

        $relativePath = "checkout-previews/{$user->id}.html";
        Storage::disk('local')->put($relativePath, $request->file('file')->get());

        CheckoutPreview::updateOrCreate(
            ['user_id' => $user->id],
            ['file_path' => $relativePath]
        );

        return response()->json(['message' => 'Preview uploaded successfully']);
    }

    public function destroy(User $user): JsonResponse
    {
        $preview = CheckoutPreview::where('user_id', $user->id)->first();

        if (! $preview) {
            return response()->json(['message' => 'No preview found'], 404);
        }

        Storage::disk('local')->delete($preview->file_path);
        $preview->delete();

        return response()->json(['message' => 'Preview deleted']);
    }
}
