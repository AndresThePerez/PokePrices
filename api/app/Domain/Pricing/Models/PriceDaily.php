<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One daily candle of a variant. A filled row repeats the last real close (n_obs 0,
 * filled_from that day, at most 14 days back). The key is (variant_key, source, day); a save or delete matches all
 * three. Money stays a decimal string, never a float.
 *
 * @property string $variant_key
 * @property string $source
 * @property CarbonImmutable $day
 * @property int $series_epoch
 * @property string $open
 * @property string $high
 * @property string $low
 * @property string $close
 * @property int $n_obs
 * @property bool $is_filled
 * @property CarbonImmutable|null $filled_from
 * @property CarbonImmutable $as_of
 */
#[Table('price_daily', key: 'variant_key', keyType: 'string', incrementing: false, timestamps: false, dateFormat: 'Y-m-d H:i:sP')]
#[Unguarded]
final class PriceDaily extends Model
{
    private const array KEY = ['variant_key', 'source', 'day'];

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
            'day' => 'immutable_date',
            'series_epoch' => 'integer',
            'n_obs' => 'integer',
            'is_filled' => 'boolean',
            'filled_from' => 'immutable_date',
            'as_of' => 'immutable_datetime',
        ];
    }
}
