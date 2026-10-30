<?php

declare(strict_types=1);

// Alert delivery settings: the catalog grace, the webhook and delivery keys, the per-user webhook limits, the Discord
// test switch and the webhook-test limiter, the export cap and the digest.
return [
    'webhooks' => [
        // Per user: at most this many signed webhooks and Discord webhooks, disabled ones included.
        'limit' => 5,
        'discord_limit' => 1,
        // Exact host:port pairs that skip the address guard and may use http. WEBHOOK_PRIVATE_ALLOW is a
        // comma-separated list; without it, only the dev stack (APP_ENV local) allow-lists the local sink.
        'private_allow' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('WEBHOOK_PRIVATE_ALLOW', env('APP_ENV') === 'local' ? 'api:8096' : ''))),
            fn (string $pair): bool => $pair !== '',
        )),
        'connect_timeout_seconds' => 2,
        'timeout_seconds' => 5,
        'max_response_bytes' => 65536,
    ],

    // Sending and retries: six attempts, delays 30 s x 4^(n-1) capped at an hour with
    // equal jitter, a 300 s claim lease, 10 rows per sweep and 3 per endpoint within a 60 s budget, 30 days kept.
    'delivery' => [
        'max_attempts' => 6,
        'base_seconds' => 30,
        'factor' => 4,
        'cap_seconds' => 3600,
        'lease_seconds' => 300,
        'batch' => 10,
        'per_endpoint' => 3,
        'sweep_budget_seconds' => 60,
        'retention_days' => 30,
    ],

    // Minutes after the deliverable day's newest ok run finished during which a degraded catalog read defers the
    // creation of deliveries; after it, deliveries are created with whatever names the read gave.
    'catalog_grace_minutes' => 30,

    // GET /v1/me/export lists at most this many of a person's deliveries, newest first; with the
    // 30-day retention that is everything kept in practice.
    'export_deliveries_limit' => 500,

    // The live Discord switch: DISCORD_TEST_WEBHOOK_URL is set by Andres in the environment only,
    // never committed; unset, pokeprices:alerts:discord-test sends nothing.
    'discord' => ['test_webhook_url' => env('DISCORD_TEST_WEBHOOK_URL')],

    // Requests per minute, per user (App\Domain\Alerts\AlertRateLimits): POST /v1/webhooks/{id}/test.
    'rate_limits' => ['webhook-test' => 5],

    // The daily digest: at most this many move lines, then "…and <k> more" with a link to the watchlist.
    'digest' => ['max_lines' => 25],
];
