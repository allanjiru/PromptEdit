<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Simulated demo credits
    |--------------------------------------------------------------------------
    |
    | This assessment-only feature never contacts a payment provider. Keep it
    | explicitly disabled unless the current deployment is intended for demo
    | or reviewer use.
    |
    */
    'enabled' => (bool) env('DEMO_CREDITS_ENABLED', false),

    'max_total_units' => (int) env(
        'DEMO_CREDITS_MAX_TOTAL_USD',
        100
    ) * 1_000_000_000,

    'rate_limit_per_minute' => (int) env(
        'DEMO_CREDITS_RATE_LIMIT_PER_MINUTE',
        10
    ),

    'packages' => [
        'starter' => [
            'label' => 'Starter',
            'amount_units' => 5_000_000_000,
        ],
        'standard' => [
            'label' => 'Standard',
            'amount_units' => 10_000_000_000,
        ],
        'plus' => [
            'label' => 'Plus',
            'amount_units' => 20_000_000_000,
        ],
    ],
];
