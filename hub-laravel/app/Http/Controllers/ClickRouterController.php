<?php

namespace App\Http\Controllers;

use App\Services\AffiliateRouter;
use Illuminate\Http\RedirectResponse;

class ClickRouterController extends Controller
{
    public function __construct(private AffiliateRouter $router) {}

    public function redirect(string $cartpandaParam): RedirectResponse
    {
        $result = $this->router->pickShop($cartpandaParam);

        if (isset($result['error'])) {
            $fallback = $result['fallback_url'] ?? 'https://fractal.com';

            return redirect()->away($fallback, 302);
        }

        $base = $result['ck_url'];
        $separator = str_contains($base, '?') ? '&' : '?';
        $url = $base.$separator.'c='.urlencode($result['token']);

        return redirect()->away($url, 302);
    }
}
