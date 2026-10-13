<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One measured (day, source, rule) of movement_volume (plan P2-i1 T13 item 5). The table is derived from price_daily
 * by MovementVolume::rebuild() (in every finalize and in pokeprices:prices:movement-volume), so the model only reads.
 *
 * @property CarbonImmutable $day
 * @property string $source
 * @property string $rule
 * @property string $pct
 * @property string $abs
 * @property int $compared
 * @property int $up
 * @property int $down
 * @property int $epoch_breaks
 * @property int $filled_skipped
 * @property int $extreme
 * @property int $sub_2_previous
 */
#[Table('movement_volume', key: 'rule', keyType: 'string', incrementing: false, timestamps: false, dateFormat: 'Y-m-d')]
final class MovementVolumeRow extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'compared' => 'integer',
            'up' => 'integer',
            'down' => 'integer',
            'epoch_breaks' => 'integer',
            'filled_skipped' => 'integer',
            'extreme' => 'integer',
            'sub_2_previous' => 'integer',
        ];
    }
}
