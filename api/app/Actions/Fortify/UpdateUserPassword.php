<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Changes the signed-in person's own password behind the current one. The user is always the one the session
 * names (Fortify's controller passes the request's user), so nothing in the request can name another account.
 * The new password follows the sign-up and reset rule.
 *
 * In the same transaction it deletes every other sessions row of the user, as a reset does: the account's other
 * browsers are signed out and this one stays. A remember-me cookie carries a digest of the old hash, so it signs
 * nobody back in either.
 */
final class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /** @param array<string, mixed> $input */
    public function update(User $user, array $input): void
    {
        // bail: a non-string current password never reaches the hash check (422, never a TypeError 500).
        /** @var array{password: string} $valid */
        $valid = Validator::make($input, [
            'current_password' => ['bail', 'required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ])->validate();

        $session = request()->hasSession() ? request()->session() : null;

        DB::transaction(function () use ($user, $valid, $session): void {
            $user->forceFill(['password' => $valid['password']])->save();
            DB::table('sessions')->where('user_id', $user->getKey())
                ->when($session instanceof Session, fn ($rows) => $rows->where('id', '!=', $session?->getId()))
                ->delete();
        });

        // The session holds a keyed digest of the password hash it signed in with, and the account API signs out a
        // session whose digest no longer matches. This session takes the new one, so it stays.
        $guard = Auth::guard('web');
        if ($session instanceof Session && $guard instanceof SessionGuard) {
            $session->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));
        }
    }
}
