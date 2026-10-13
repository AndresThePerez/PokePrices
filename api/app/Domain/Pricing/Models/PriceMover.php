<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One ranked mover of a collected day (plan P2-i1 T12, D7). The table is derived: Movers::rebuild() rewrites it inside
 * every ingest finalize, so the model only reads. The key is (day, source, direction, rank). Money and change_pct
 * stay decimal strings, never floats.
 *
 * @property CarbonImmutable $day
 * @property string $source
 * @property string $direction
 * @property int $rank
 * @property string $variant_key
 * @property string $card_id
 * @property int $series_epoch
 * @property string $close
 * @property string $previous_close
 * @property CarbonImmutable $previous_day
 * @property string $change
 * @property string $change_pct
 */
#[Table('price_movers', key: 'rank', keyType: 'int', incrementing: false, timestamps: false, dateFormat: 'Y-m-d')]
final class PriceMover extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'rank' => 'integer',
            'series_epoch' => 'integer',
            'previous_day' => 'immutable_date',
        ];
    }
}
