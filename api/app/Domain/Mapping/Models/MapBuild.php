<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One map build over a collected snapshot day. snapshot_day is the day the map was built from, never a
 * price date.
 *
 * @property int $id
 * @property CarbonImmutable $snapshot_day
 * @property string $dataset_ref
 * @property string|null $seed_ref
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, mixed> $counts
 */
#[Table('map_builds', timestamps: false)]
#[Guarded(['id'])]
final class MapBuild extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot_day' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'counts' => 'array',
        ];
    }
}
