<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * The links PokéPrices mails (plan P4-i1 D2). Both land on SPA pages, whose paths avoid every prefix nginx sends to
 * Laravel:
 * - verification: <APP_URL>/account/verify-email?link=<rawurlencode of /email/verify/<id>/<hash>?expires=…&signature=…>;
 *   the page calls that path with Accept: application/json and Fortify answers 204;
 * - reset: <APP_URL>/password/reset?token=<token>&email=<rawurlencode email>.
 */
final class AccountLinks
{
    public static function verification(MustVerifyEmail $notifiable): string
    {
        $key = method_exists($notifiable, 'getKey') ? $notifiable->getKey() : null;
        if (! is_int($key) && ! is_string($key)) {
            throw new RuntimeException('A verification link needs an account id.');
        }
        $expire = config('auth.verification.expire', 60);
        $signed = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(is_int($expire) ? $expire : 60),
            ['id' => $key, 'hash' => sha1($notifiable->getEmailForVerification())],
        );
        $path = (string) parse_url($signed, PHP_URL_PATH);
        $query = parse_url($signed, PHP_URL_QUERY);

        return self::base().'/account/verify-email?link='.rawurlencode($path.(is_string($query) ? '?'.$query : ''));
    }

    public static function reset(CanResetPassword $user, string $token): string
    {
        $query = http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()], '', '&', PHP_QUERY_RFC3986);

        return self::base().'/password/reset?'.$query;
    }

    private static function base(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }
}
