<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
| Sanctum in cookie mode only: the SPA and the API share one hostname, and the account routes are
| stateful for first-party requests from it. No personal access tokens: no token table, no HasApiTokens, and
| FortifyServiceProvider makes Sanctum ignore any Authorization header. bootstrap/app.php does not call
| statefulApi(): EnsureFrontendRequestsAreStateful is route middleware on the /v1 account group only, so public
| reads never start a session.
*/

return [
    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'pokeprices.localhost:8095')),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => '',

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],
];
