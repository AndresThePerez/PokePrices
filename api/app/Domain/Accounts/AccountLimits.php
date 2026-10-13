<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

/**
 * The account list limits (plan P4-i1 D5) from config/accounts.php limits, and the messages a full list or an
 * unknown variant answers with (422 errors.variant_key, D6).
 */
final class AccountLimits
{
    public const string UNKNOWN_VARIANT = 'PokéPrices has no variant with this key.';

    public static function watchlist(): int
    {
        return self::read('watchlist', 100);
    }

    public static function portfolio(): int
    {
        return self::read('portfolio', 100);
    }

    public static function quantityMax(): int
    {
        return self::read('quantity_max', 9999);
    }

    public static function watchlistFull(): string
    {
        return 'Your watchlist holds at most '.self::watchlist().' variants.';
    }

    public static function portfolioFull(): string
    {
        return 'Your portfolio holds at most '.self::portfolio().' variants.';
    }

    private static function read(string $name, int $default): int
    {
        $value = config('accounts.limits.'.$name, $default);

        return is_int($value) && $value >= 1 ? $value : $default;
    }
}
