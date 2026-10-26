<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Credentials travel in the request body only (plan P5-i1 D9; the P4 carry-forward on ?password=; plan Q1-i1 T04,
 * review a1). A password, password_confirmation or current_password in the query string is removed from every
 * request, and a write (any method but GET or HEAD) to a credential-bearing endpoint loses its whole query string,
 * so an invite code, a two-factor code, a recovery code, a reset token or a remember flag that travels only in the
 * URL counts for nothing: POST /register?invite_code=… answers 422 errors.invite_code, POST /login?remember=1 mints
 * no remember-me cookie, POST /two-factor-challenge?code=… signs nobody in. Every other path keeps its query (the
 * signed email/verify link depends on it). A global middleware, so it runs before routing and every other middleware
 * that reads input; it matches the request path as the router does (URL-decoded, slashes trimmed).
 */
final class IgnoreQueryCredentials
{
    public const array KEYS = ['password', 'password_confirmation', 'current_password'];

    /** The credential-bearing write endpoints; they take no query parameters at all. */
    public const array PATHS = [
        'login',
        'register',
        'two-factor-challenge',
        'reset-password',
        'forgot-password',
        'user/confirm-password',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-recovery-codes',
        'v1/me/deletion',
    ];

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::KEYS as $key) {
            $request->query->remove($key);
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && in_array(trim(rawurldecode($request->path()), '/'), self::PATHS, true)) {
            $request->query->replace([]);
        }

        return $next($request);
    }
}
