<?php

namespace App\Http\Controllers;

use App\Models\CheckoutPreview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CheckoutPreviewController extends Controller
{
    public function token(): JsonResponse
    {
        $user = auth()->user();
        $preview = CheckoutPreview::where('user_id', $user->id)->first();

        if (! $preview) {
            return response()->json(['has_preview' => false]);
        }

        $url = URL::temporarySignedRoute(
            'checkout-preview.show',
            now()->addHour(),
            ['user' => $user->id]
        );

        return response()->json(['has_preview' => true, 'url' => $url]);
    }

    public function show(User $user): Response
    {
        $preview = CheckoutPreview::where('user_id', $user->id)->first();

        if (! $preview) {
            abort(404, 'No checkout preview found');
        }

        if (! Storage::disk('local')->exists($preview->file_path)) {
            abort(404, 'Preview file not found');
        }

        $content = Storage::disk('local')->get($preview->file_path);

        return response($content, 200, ['Content-Type' => 'text/html']);
    }
}
