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
];
