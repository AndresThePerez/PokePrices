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
| IP-keyed buckets (register, account-guest) are one global bucket until trustProxies is scoped (the Deploy prompt).
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
        // The signed-in account API, keyed by user.
        'account' => 120,
    ],
];
