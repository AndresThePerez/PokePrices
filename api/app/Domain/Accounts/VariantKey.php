<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * The shape of a variant key "card_id:finish:edition:treatment" (plan P4-i1 D5, review F-P4-05), mirroring
 * card_variants_variant_key_check and the card_id, finish, edition and treatment checks of card_variants: at most
 * 300 bytes of valid UTF-8. Every {variantKey} route runs it before any query, so a malformed key never reaches the
 * database.
 */
final class VariantKey
{
    public const int MAX_BYTES = 300;

    private const string PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._!?-]{0,127}:(normal|holo|reverse):(-|1st|unlimited|shadowless):(-|[a-z0-9]+(-[a-z0-9]+)*)\z/';

    public static function isValid(string $key): bool
    {
        return strlen($key) <= self::MAX_BYTES
            && mb_check_encoding($key, 'UTF-8')
            && preg_match(self::PATTERN, $key) === 1;
    }
}
