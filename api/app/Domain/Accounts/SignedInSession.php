<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tells, before any session starts, whether a request carries a signed-in session: it decrypts the session cookie
 * and reads the user id of that one sessions row by its primary key. It starts no session, writes nothing and sets
 * no cookie.
 *
 * Anything else is a guest: no cookie, a cookie that does not decrypt or was encrypted for another cookie name, a
 * value that is not a session id, an id with no row, a row idle for longer than the session lifetime, a row nobody
 * signed in to, and every request when sessions are not kept in the database. A cookie that does not decrypt costs
 * no query.
 */
final class SignedInSession
{
    public static function userId(Request $request): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }
        $id = self::sessionId($request);
        if ($id === null) {
            return null;
        }
        $connection = config('session.connection');
        $table = config('session.table');
        $lifetime = config('session.lifetime');
        $userId = DB::connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'sessions')
            ->where('id', $id)
            ->where('last_activity', '>=', now()->subMinutes(is_int($lifetime) ? $lifetime : 0)->getTimestamp())
            ->value('user_id');

        return is_int($userId) ? $userId : null;
    }

    /** The session id the request's session cookie carries, or null unless it decrypts to a well-formed id. */
    private static function sessionId(Request $request): ?string
    {
        $name = config('session.cookie');
        $cookie = is_string($name) ? ($request->cookies->all()[$name] ?? null) : null;
        if (! is_string($name) || ! is_string($cookie) || $cookie === '') {
            return null;
        }
        $encrypter = app(Encrypter::class);
        try {
            $decrypted = $encrypter->decrypt($cookie, false);
        } catch (DecryptException) {
            return null;
        }
        $id = is_string($decrypted) ? CookieValuePrefix::validate($name, $decrypted, $encrypter->getAllKeys()) : null;

        return is_string($id) && strlen($id) === 40 && ctype_alnum($id) ? $id : null;
    }
}
