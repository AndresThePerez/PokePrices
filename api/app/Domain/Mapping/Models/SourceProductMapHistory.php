<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * An ended mapping epoch. Append-only: the database refuses UPDATE and DELETE.
 *
 * @property int $id
 * @property string $source
 * @property int $source_group_id
 * @property int $source_product_id
 * @property string $source_subtype
 * @property string $source_product_name
 * @property string|null $source_number
 * @property string $variant_key
 * @property string $method
 * @property string $confidence
 * @property bool $name_agreement
 * @property int $series_epoch
 * @property int $map_build_id
 * @property CarbonImmutable $ended_at
 * @property string $end_reason
 */
#[Table('source_product_map_history', timestamps: false)]
#[Guarded(['id'])]
final class SourceProductMapHistory extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_group_id' => 'integer',
            'source_product_id' => 'integer',
            'confidence' => 'decimal:3',
            'name_agreement' => 'boolean',
            'series_epoch' => 'integer',
            'map_build_id' => 'integer',
            'ended_at' => 'immutable_datetime',
        ];
    }
}
