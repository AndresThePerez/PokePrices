<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One chunk of a queued ingest run, keyed "<groupId>:<offset>". offset indexes the de-duplicated
 * row list of the group; counts (mapped, unmapped, null_market, upserted, unchanged) is overwritten by a retry.
 * The chunks of a superseded run are pruned once its finished_at is more than RETAIN_HOURS old (model:prune, daily);
 * the chunks of every other run, and every run row, are kept.
 *
 * @property int $id
 * @property int $ingest_run_id
 * @property string $chunk_key
 * @property int $group_id
 * @property int $offset
 * @property int $rows
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property array<string, mixed> $counts
 */
#[Table('ingest_run_chunks', timestamps: false)]
#[Guarded(['id'])]
final class IngestRunChunk extends Model
{
    use Prunable;

    /** How long a superseded run's chunks are kept after the run finished: 30 days. */
    public const int RETAIN_HOURS = 720;

    /**
     * The chunks of superseded runs that finished more than RETAIN_HOURS ago. A running run that a replay marked
     * superseded has no finished_at and is never selected.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return self::query()->whereIn('ingest_run_id', IngestRun::query()
            ->select('id')
            ->where('status', 'superseded')
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', now()->subHours(self::RETAIN_HOURS)->utc()->format('Y-m-d H:i:sP')));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ingest_run_id' => 'integer',
            'group_id' => 'integer',
            'offset' => 'integer',
            'rows' => 'integer',
            'attempts' => 'integer',
            'counts' => 'array',
        ];
    }
}
