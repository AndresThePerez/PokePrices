<?php

declare(strict_types=1);

// Alert delivery settings (plan P5-i1 D14). T01 adds the catalog grace, T02 the webhook and delivery keys; later
// tasks add theirs.
return [
    'webhooks' => [
        // Exact host:port pairs that skip the address guard and may use http (D6). WEBHOOK_PRIVATE_ALLOW is a
        // comma-separated list; without it, only the dev stack (APP_ENV local) allow-lists the local sink.
        'private_allow' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('WEBHOOK_PRIVATE_ALLOW', env('APP_ENV') === 'local' ? 'api:8096' : ''))),
            fn (string $pair): bool => $pair !== '',
        )),
        'connect_timeout_seconds' => 2,
        'timeout_seconds' => 5,
        'max_response_bytes' => 65536,
    ],

    // Sending and retries (D7, review A04, A05, A10.4): six attempts, delays 30 s x 4^(n-1) capped at an hour with
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
    // creation of deliveries (D3, review A10.1); after it, deliveries are created with whatever names the read gave.
    'catalog_grace_minutes' => 30,
];
