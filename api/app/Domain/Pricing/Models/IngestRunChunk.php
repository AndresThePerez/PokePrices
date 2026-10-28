<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One chunk of a queued ingest run, keyed "<groupId>:<offset>". offset indexes the de-duplicated
 * row list of the group; counts (mapped, unmapped, null_market, upserted, unchanged) is overwritten by a retry.
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
