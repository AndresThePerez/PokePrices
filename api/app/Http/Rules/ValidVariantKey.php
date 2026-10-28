<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Domain\Accounts\AccountLimits;
use App\Domain\Accounts\VariantKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ValidationException;

/**
 * A {variantKey} route key must have the shape VariantKey checks (plan P4-i1 D5, review F-P4-05). The rule runs
 * before any query, so a malformed key never reaches the database, and refuses with the same message as a key that
 * names no variant. It is implicit, so an empty key is refused too.
 */
final class ValidVariantKey implements ValidationRule
{
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! VariantKey::isValid($value)) {
            $fail(AccountLimits::UNKNOWN_VARIANT);
        }
    }

    /**
     * Refuses a malformed route key with 422 errors.variant_key alone, before any body field is looked at.
     *
     * @throws ValidationException errors.variant_key
     */
    public static function check(mixed $key): void
    {
        validator(['variant_key' => $key], ['variant_key' => [new self]])->validate();
    }
}
