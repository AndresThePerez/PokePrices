<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Accounts\InviteCode;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Invite-only registration (plan P4-i1 D2, D3): the invite code first (nothing else is examined without it), then the
 * email (NormalizeAccountEmail has already trimmed and lower-cased it) and the password. The account stores an email
 * and a password only; name stays null.
 */
final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        InviteCode::check($input[InviteCode::FIELD] ?? null);

        /** @var array{email: string, password: string} $valid */
        $valid = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'not_regex:/[\x00-\x1F\x7F]/', 'max:255', self::unusedEmail(...)],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = new User;
        try {
            $user->forceFill(['email' => $valid['email'], 'password' => $valid['password']])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }

        return $user;
    }

    /** Letter case never splits an account: the comparison is lower(email), as users_email_lower_unique is. */
    private static function unusedEmail(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && User::query()->whereRaw('lower(email) = lower(?)', [$value])->exists()) {
            $fail('The email has already been taken.');
        }
    }
}
