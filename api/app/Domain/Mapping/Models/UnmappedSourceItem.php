<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A source product a map build could not map, with the reason and its candidates.
 *
 * @property int $id
 * @property int $map_build_id
 * @property string $source
 * @property int $source_group_id
 * @property int $source_product_id
 * @property string $source_subtype
 * @property string $product_name
 * @property string|null $number
 * @property string $reason
 * @property list<mixed> $candidates
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolution
 */
#[Table('unmapped_source_items', timestamps: false)]
#[Guarded(['id'])]
final class UnmappedSourceItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'map_build_id' => 'integer',
            'source_group_id' => 'integer',
            'source_product_id' => 'integer',
            'candidates' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
