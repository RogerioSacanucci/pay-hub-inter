<?php

namespace App\Http\Controllers;

use App\Services\AffiliateRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateClickController extends Controller
{
    public function __construct(private AffiliateRouter $router) {}

    public function show(Request $request, string $token): JsonResponse
    {
        $shop = (string) $request->query('shop', '');
        if ($shop === '') {
            return response()->json(['error' => 'shop_required'], 400);
        }

        $result = $this->router->resolve($token, $shop);

        if (isset($result['error'])) {
            $status = match ($result['error']) {
                'invalid_or_expired_token', 'affiliate_not_found' => 404,
                'shop_not_active', 'no_checkout_template' => 503,
                default => 500,
            };

            return response()->json($result, $status);
        }

        return response()->json($result);
    }
}
