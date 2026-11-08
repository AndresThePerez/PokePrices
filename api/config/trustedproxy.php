<?php

declare(strict_types=1);

/*
| PokéPrices trusted proxies (read per request by Laravel's global TrustProxies middleware).
|
| proxies: the TRUSTED_PROXIES environment variable, passed through as a string; TrustProxies splits a comma list
| and maps the literal REMOTE_ADDR to the request's peer address.
|   unset        trust nobody: X-Forwarded-* are ignored and the peer is the client (dev).
|   REMOTE_ADDR  trust only the immediate peer, the address that reached nginx (production: deploy/stack.server.yml).
|                cloudflared appends the client to any client-sent X-Forwarded-For and nginx forwards it untouched,
|                so the rightmost entry is the client only when nothing but that peer is trusted.
|   *            trusts every hop, which makes the LEFTMOST, client-chosen X-Forwarded-For entry the client IP:
|                spoofable, never for production.
|
| The trusted headers are set in bootstrap/app.php: X-Forwarded-For, -Proto and -Port. X-Forwarded-Host is never
| trusted.
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
