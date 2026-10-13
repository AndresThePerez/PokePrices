<?php

declare(strict_types=1);

/*
| PokéPrices accounts (plan P4-i1 D3, D7).
|
| invite_code: registration is invite-only (R208). The code comes from the INVITE_CODE environment variable and is
| never committed; with no code configured, registration accepts none. Tests set it with config([...]).
|
| rate_limits: the account tiers, in requests per minute (each an integer >= 1; anything else fails the boot),
| registered by App\Domain\Accounts\AccountRateLimits. The anonymous tiers stay in config/rate_limits.php.
| IP-keyed buckets (register, account-guest, account-demo, demo-session) are one global bucket until trustProxies is
| scoped (the Deploy prompt).
|
| limits: the most variants a watchlist and a portfolio hold, and the largest quantity of a holding (D5; the database
| checks quantity BETWEEN 1 AND 9999 too).
|
| demo: the read-only demo account (D8, R209), seeded by Database\Seeders\DemoAccountSeeder. DEMO_PASSWORD has no
| committed default: unset, the seeder stores a random 40-character password and the demo is entered only through
| POST /v1/demo/session; set, the normal sign-in form works too. Keys missing from card_variants are skipped.
*/

return [
    'invite_code' => env('INVITE_CODE'),

    'rate_limits' => [
        // Fortify's sign-in, keyed by the normalised email and the IP.
        'login' => 5,
        // The two-factor challenge, keyed by the pending sign-in in the session.
        'two-factor' => 5,
        // POST /register, keyed by IP.
        'register' => 5,
        // Anonymous account traffic, keyed by IP; runs before any session starts.
        'account-guest' => 60,
        // Password confirmations, keyed by user.
        'password-confirm' => 5,
        // The signed-in account API, keyed by user; the demo user gets account-demo instead.
        'account' => 120,
        // The account API for the shared demo user, keyed by IP, so one visitor cannot spend it for all.
        'account-demo' => 30,
        // POST /v1/demo/session, keyed by IP.
        'demo-session' => 10,
    ],

    'limits' => [
        'watchlist' => 100,
        'portfolio' => 100,
        'quantity_max' => 9999,
    ],

    'demo' => [
        'enabled' => (bool) filter_var(env('DEMO_ENABLED', true), FILTER_VALIDATE_BOOL),
        'email' => env('DEMO_EMAIL', 'demo@example.com'),
        'password' => env('DEMO_PASSWORD'),
        'watchlist' => ['base1-4:holo:unlimited:-', 'base1-4:holo:1st:-', 'ex5-17:reverse:-:-'],
        'portfolio' => ['base1-4:holo:unlimited:-' => 1, 'ex5-17:reverse:-:-' => 3],
    ],
];
