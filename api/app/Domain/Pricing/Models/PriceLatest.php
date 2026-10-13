<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The latest real close of a variant with its previous close and change (plan P2-i1 T05, D4, D5). The table is
 * derived: LatestPrices::rebuild() rewrites it inside every ingest finalize, so the model only reads. The key is
 * (variant_key, source). Money and change_pct stay decimal strings, never floats.
 *
 * @property string $variant_key
 * @property string $source
 * @property int $series_epoch
 * @property string $close
 * @property CarbonImmutable $day
 * @property CarbonImmutable $as_of
 * @property string|null $previous_close
 * @property CarbonImmutable|null $previous_day
 * @property string|null $change
 * @property string|null $change_pct
 */
#[Table('price_latest', key: 'variant_key', keyType: 'string', incrementing: false, timestamps: false, dateFormat: 'Y-m-d H:i:sP')]
final class PriceLatest extends Model
{
    private const array KEY = ['variant_key', 'source'];

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
            'series_epoch' => 'integer',
            'day' => 'immutable_date',
            'as_of' => 'immutable_datetime',
            'previous_day' => 'immutable_date',
        ];
    }
}
