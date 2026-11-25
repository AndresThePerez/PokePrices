<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Sets the new password from a valid reset token. In the same transaction it deletes every sessions
 * row of the user: a reset ends every other session. The reset flow is guest, so the browser doing
 * the reset is not signed in and loses nothing.
 *
 * The read-only demo account's password never changes this way: its reset answers the same 422
 * errors.email as an invalid token.
 */
final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /** @param array<string, mixed> $input */
    public function reset(User $user, array $input): void
    {
        if ($user->is_demo) {
            throw ValidationException::withMessages(['email' => [trans(Password::INVALID_TOKEN)]]);
        }
        /** @var array{password: string} $valid */
        $valid = Validator::make($input, ['password' => $this->passwordRules()])->validate();

        DB::transaction(function () use ($user, $valid): void {
            $user->forceFill(['password' => Hash::make($valid['password'])])->save();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        });
    }
}
