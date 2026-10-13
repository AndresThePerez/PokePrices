<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Accounts\AccountExport;
use App\Http\Resources\AccountExportResource;
use App\Http\Resources\MeResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

#[Group('Account', 'The signed-in PokéPrices account (cookie session; sign in through the session endpoints first).')]
final class MeController
{
    /**
     * Who is signed in.
     *
     * email is the account's lower-cased email address; email_verified says whether it has been verified; two_factor
     * is true once two-factor authentication is confirmed; read_only is true for the read-only demo account. Signed
     * out, the answer is 401. The answer is never cached.
     *
     * @operationId getMe
     */
    public function __invoke(Request $request): MeResource
    {
        // auth:sanctum on the account group has already answered 401 to a signed-out request.
        return new MeResource(self::user($request));
    }

    /**
     * Download your data.
     *
     * Everything PokéPrices keeps about the signed-in account, as a JSON download named pokeprices-export.json: the
     * account (email, when the email was verified, whether two-factor authentication is on, when the account was
     * created), the watchlist and the portfolio ordered by variant key, and the session records newest first (last
     * activity, IP address, browser). Times are RFC 3339 in UTC. It never holds the password, a two-factor secret, a
     * recovery code, a session id or session contents. The read-only demo account may download its data too. The
     * answer is never cached.
     *
     * @operationId exportMe
     */
    public function export(Request $request, AccountExport $export): AccountExportResource
    {
        return new AccountExportResource($export->of(self::user($request)));
    }

    /**
     * Delete your account.
     *
     * Deletes the signed-in account after checking its current password, sent in the JSON body (never in the URL):
     * the account, its watchlist, its portfolio, every session record and any password reset token, in one
     * transaction. This browser is signed out. A wrong password answers 422 with errors.password; password checks
     * are limited to 5 a minute per account (429). The demo account is read-only (403 demo-read-only).
     *
     * @operationId deleteMe
     *
     * @throws ValidationException 422 errors.password for a missing or wrong password
     */
    public function deletion(Request $request, AccountDeletion $deletion): Response
    {
        // bail: a non-string password never reaches the hash check (review F-P4-05: 422, never a TypeError 500).
        $request->validate(['password' => ['bail', 'required', 'string', 'current_password:web']]);

        $deletion->delete(self::user($request));

        // The current device only: the user row is gone, so there is no remember token to cycle.
        Auth::guard('web')->logoutCurrentDevice();
        // auth:sanctum made its RequestGuard the default guard, and it keeps the user it resolved. The database
        // session handler writes this request's new session row with the default guard's user id, so the guard
        // forgets the user too: no sessions row outlives the account with its id.
        $default = Auth::guard();
        if ($default instanceof RequestGuard) {
            $default->forgetUser();
        }
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** auth:sanctum on the account group has already answered 401 to a signed-out request. */
    private static function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
