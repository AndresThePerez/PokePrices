<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One raw observation of a variant at a TCGCSV marker. The key is (variant_key, source,
 * observed_at); a save or delete matches all three. Money stays a decimal string, never a float. Instants are
 * written with their offset, so the session time zone never shifts them.
 *
 * @property string $variant_key
 * @property string $source
 * @property CarbonImmutable $observed_at
 * @property CarbonImmutable $day
 * @property int $series_epoch
 * @property int $source_product_id
 * @property string $source_subtype
 * @property string|null $market
 * @property string|null $low
 * @property string|null $mid
 * @property string|null $high
 * @property string|null $direct_low
 * @property int $ingest_run_id
 */
#[Table('price_points', key: 'variant_key', keyType: 'string', incrementing: false, timestamps: false, dateFormat: 'Y-m-d H:i:sP')]
#[Unguarded]
final class PricePoint extends Model
{
    private const array KEY = ['variant_key', 'source', 'observed_at'];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query): Builder
    {
        foreach (self::KEY as $column) {
            $query->where($column, '=', $this->getRawOriginal($column) ?? $this->getAttributes()[$column] ?? null);
        }

        return $query;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query): Builder
    {
        return $this->setKeysForSaveQuery($query);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'day' => 'immutable_date',
            'series_epoch' => 'integer',
            'source_product_id' => 'integer',
            'ingest_run_id' => 'integer',
        ];
    }
}
