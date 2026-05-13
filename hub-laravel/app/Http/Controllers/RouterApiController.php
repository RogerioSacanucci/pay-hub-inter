<?php

namespace App\Http\Controllers;

use App\Services\AffiliateRouter;
use Illuminate\Http\JsonResponse;

class RouterApiController extends Controller
{
    public function __construct(private AffiliateRouter $router) {}

    public function resolve(string $cartpandaParam): JsonResponse
    {
        $result = $this->router->resolve($cartpandaParam);

        if (isset($result['error'])) {
            $status = match ($result['error']) {
                'affiliate_not_found' => 404,
                'no_active_shops', 'all_capped', 'no_checkout_template' => 503,
                default => 500,
            };

            return response()->json($result, $status);
        }

        return response()->json($result);
    }
}
