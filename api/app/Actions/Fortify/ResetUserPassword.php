<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Sets the new password from a valid reset token (plan P4-i1 D2). In the same transaction it deletes every sessions
 * row of the user (review F-P4-08): a reset ends every other session. The reset flow is guest, so the browser doing
 * the reset is not signed in and loses nothing.
 */
final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /** @param array<string, mixed> $input */
    public function reset(User $user, array $input): void
    {
        /** @var array{password: string} $valid */
        $valid = Validator::make($input, ['password' => $this->passwordRules()])->validate();

        DB::transaction(function () use ($user, $valid): void {
            $user->forceFill(['password' => $valid['password']])->save();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
        });
    }
}
