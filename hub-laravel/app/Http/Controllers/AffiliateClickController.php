<?php

namespace App\Http\Controllers;

use App\Services\AffiliateRouter;
use Illuminate\Http\JsonResponse;

class AffiliateClickController extends Controller
{
    public function __construct(private AffiliateRouter $router) {}

    public function show(string $code): JsonResponse
    {
        $result = $this->router->resolve($code);

        if (isset($result['error'])) {
            $status = match ($result['error']) {
                'code_not_found' => 404,
                'no_active_targets', 'all_capped', 'no_checkout_template' => 503,
                default => 500,
            };

            return response()->json($result, $status);
        }

        return response()->json($result);
    }
}
