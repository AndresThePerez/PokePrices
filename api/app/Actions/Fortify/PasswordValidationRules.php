<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * The PokéPrices password rule (plan P4-i1 D2): a string of 12 to 72 characters and at most 72 bytes (bcrypt ignores
 * every byte past 72, and max counts characters; review F-P4-05), confirmed. No breached-password check: it would
 * call the network.
 */
trait PasswordValidationRules
{
    public const int PASSWORD_MAX_BYTES = 72;

    /** @return list<mixed> */
    protected function passwordRules(): array
    {
        return [
            'required',
            'string',
            Password::min(12)->max(72),
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > self::PASSWORD_MAX_BYTES) {
                    $fail('The password must not be longer than 72 bytes.');
                }
            },
            'confirmed',
        ];
    }
}
