<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
| Fortify, headless. The SPA owns every page, so Fortify registers no view routes and answers JSON.
| Email and password only; registration is invite-only (App\Actions\Fortify\CreateNewUser). A signed-in person changes
| the password behind the current one on PUT /user/password (App\Actions\Fortify\UpdateUserPassword).
|
| lowercase_usernames stays false: App\Http\Middleware\NormalizeAccountEmail trims and lower-cases a string email on
| every Fortify POST before validation, the broker and the limiter key, and leaves a non-string email to validation.
| Fortify's own lower-casing runs before validation on /register and /reset-password and would turn an array email
| into a 500.
*/

return [
    'guard' => 'web',

    'middleware' => ['web'],

    'auth_middleware' => 'auth',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'views' => false,

    'home' => '/account',

    'prefix' => '',

    'domain' => null,

    'lowercase_usernames' => false,

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'verification' => '6,1',
    ],

    'paths' => [],

    'redirects' => [],

    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
    ],
];
