<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One `price.moved` row of the movement outbox. DetectMovement inserts it inside
 * a sync or queued finalize, never replay; nothing updates or deletes it, so the model only reads. dispatched_at is
 * always null here (dispatch is a later step). Money and change_pct stay decimal strings, never floats.
 *
 * @property int $id
 * @property string $type
 * @property string $rule
 * @property string $variant_key
 * @property string $card_id
 * @property string $source
 * @property CarbonImmutable $day
 * @property int $series_epoch
 * @property string $direction
 * @property string $close
 * @property string $previous_close
 * @property CarbonImmutable $previous_day
 * @property string $change
 * @property string $change_pct
 * @property CarbonImmutable $as_of
 * @property CarbonImmutable $previous_as_of
 * @property int $ingest_run_id
 * @property CarbonImmutable|null $dispatched_at
 */
#[Table('price_events', timestamps: false)]
final class PriceEvent extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'series_epoch' => 'integer',
            'previous_day' => 'immutable_date',
            'as_of' => 'immutable_datetime',
            'previous_as_of' => 'immutable_datetime',
            'ingest_run_id' => 'integer',
            'dispatched_at' => 'immutable_datetime',
        ];
    }
}
