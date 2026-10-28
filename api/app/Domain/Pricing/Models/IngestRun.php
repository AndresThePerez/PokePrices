<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One ingest of one collected day. marker is null only for a refusal before the marker was read;
 * status superseded marks a run whose rows a replay wiped. counts holds the start-level counts plus the sum
 * of the run's done chunks.
 *
 * @property int $id
 * @property string $source
 * @property string $feed
 * @property CarbonImmutable $day
 * @property CarbonImmutable|null $marker
 * @property string $mode
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property array<string, mixed> $counts
 * @property string|null $error
 */
#[Table('ingest_runs', timestamps: false, dateFormat: 'Y-m-d H:i:sP')]
#[Guarded(['id'])]
final class IngestRun extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'marker' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'counts' => 'array',
        ];
    }
}
