<?php

return [
    'user_email' => env('MUNDPAY_USER_EMAIL', 'srretry@fractal.com'),
    'reserve_rate' => (float) env('MUNDPAY_RESERVE_RATE', 0.15),
    'release_delay_days' => (int) env('MUNDPAY_RELEASE_DELAY_DAYS', 3),
    'brl_usd_rate' => (float) env('MUNDPAY_BRL_USD_RATE', 5.0),
];
