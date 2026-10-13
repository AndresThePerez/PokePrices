<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'pokesearch' => [
        'url' => env('POKESEARCH_URL', 'http://host.docker.internal:8081'),
        'api_key' => env('POKESEARCH_API_KEY'),
        // The readiness probe's own client (plan T19): seconds, one attempt, no retry, no breaker.
        'timeouts' => [
            'readiness' => ['connect' => 1, 'total' => 1],
        ],
    ],

    // Collected TCGCSV days, only ever read (plan T39). The dev stack mounts the repository at /workspace.
    'tcgcsv' => [
        'snapshots' => env('TCGCSV_SNAPSHOTS_DIR', '/workspace/.data/snapshots'),
    ],

    // Dataset checkouts by ref, read by the map audits (plan T40).
    'dataset' => [
        'root' => env('DATASET_DIR', '/workspace/.data/dataset'),
    ],

    // The recorded pokemontcg.io oracle by day: written once by pokeprices:oracle:fetch, read by
    // pokeprices:map:oracle (plan T41).
    'oracle' => [
        'root' => env('ORACLE_DIR', '/workspace/.data/oracle'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
