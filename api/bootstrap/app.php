<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ConditionalGet;
use App\Http\Middleware\JsonGuest;
use App\Http\Middleware\NoStore;
use App\Http\Problems\ProblemDetails;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        // P4 (plan P4-i1 D1): guest answers a signed-in person with 409 already-signed-in, never a redirect; a guest
        // on an auth route gets the 401 problem, never a redirect to a login page (the SPA owns /sign-in); every web
        // answer (Fortify, Sanctum's csrf-cookie) is no-store. The api group is not made stateful: only the /v1 account
        // group is (routes/api.php).
        $middleware->alias(['conditional-get' => ConditionalGet::class, 'guest' => JsonGuest::class]);
        $middleware->redirectGuestsTo(null);
        $middleware->appendToGroup('web', NoStore::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // nginx sends Laravel only API, health, Fortify and Sanctum paths, so every error is JSON (R16).
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);
        $exceptions->render(fn (Throwable $e, Request $request) => ProblemDetails::fromThrowable($e, $request));
    })->create();
