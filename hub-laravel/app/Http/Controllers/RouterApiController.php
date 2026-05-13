<?php

namespace App\Http\Controllers;

use App\Services\AffiliateRouter;
use Illuminate\Http\JsonResponse;

class RouterApiController extends Controller
{
    public function __construct(private AffiliateRouter $router) {}

    public function pick(string $cartpandaParam): JsonResponse
    {
        $result = $this->router->pickShop($cartpandaParam);

        if (isset($result['error'])) {
            $status = match ($result['error']) {
                'affiliate_not_found' => 404,
                'no_active_shops', 'all_capped' => 503,
                default => 500,
            };

            return response()->json($result, $status);
        }

        return response()->json($result);
    }
}
