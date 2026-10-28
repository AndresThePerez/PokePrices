<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The price_latest columns a joined read selects as price_<column> (e.g. the watchlist and portfolio rows of
 * VariantRows), so the HTTP layer's PriceBlock::fromRow() can build the same block the card page shows.
 */
final class PriceColumns
{
    public const array LATEST = ['variant_key', 'source', 'series_epoch', 'close', 'day', 'as_of', 'previous_close', 'previous_day', 'change', 'change_pct'];
}
