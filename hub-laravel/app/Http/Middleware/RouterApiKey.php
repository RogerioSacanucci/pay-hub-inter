<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RouterApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('routing.router_api_key', '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->header('X-Router-Key'))) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
