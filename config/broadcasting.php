<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | Elifba broadcasts through Reverb (docs/architecture.md §4, §10). `log`
    | and `null` are for a machine without it; a board then plays without
    | its phone. Tests use `reverb` with a recording client (tests/TestCase).
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            // A status change broadcasts `session.status` inside its HTTP
            // request (no queue): when Reverb is unreachable it must give up
            // quickly; the change itself is already saved (§4).
            'client_options' => [
                'connect_timeout' => 2,
                'timeout' => 3,
                // Local HTTPS (§10): Reverb serves TLS with Herd's certificate,
                // whose CA is not in PHP's bundle; point this at Herd's CA.
                'verify' => env('REVERB_CA_BUNDLE') ?: true,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
