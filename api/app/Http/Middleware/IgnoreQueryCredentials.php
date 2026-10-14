<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passwords travel in the request body only (plan P5-i1 D9; the P4 carry-forward on ?password=): a password,
 * password_confirmation or current_password in the query string is removed before anything reads input, so a request
 * that carries one only in the URL fails validation (POST /v1/me/deletion answers 422 errors.password, POST /login
 * signs nobody in). A global middleware, so it runs before routing and every other middleware that reads input.
 */
final class IgnoreQueryCredentials
{
    public const array KEYS = ['password', 'password_confirmation', 'current_password'];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::KEYS as $key) {
            $request->query->remove($key);
        }

        return $next($request);
    }
}
