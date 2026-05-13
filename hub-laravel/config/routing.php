<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Fallback URL
    |--------------------------------------------------------------------------
    |
    | URL returned by AffiliateRouter when no valid target can be resolved
    | (unknown code, pool empty, all targets capped without overflow).
    |
    */
    'default_fallback' => env('AFFILIATE_FALLBACK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Router API Key
    |--------------------------------------------------------------------------
    |
    | Shared secret between the standalone router app and the hub. The router
    | must send it as the X-Router-Key header when calling /api/router/pick.
    |
    */
    'router_api_key' => env('ROUTER_API_KEY'),
];
