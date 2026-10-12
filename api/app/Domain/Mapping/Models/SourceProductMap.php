<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The current mapping of one source product and subtype to a card variant (plan T36, R131). A remap moves the
 * old row into source_product_map_history and writes the new one with series_epoch + 1.
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
 */
#[Table('source_product_map', timestamps: false)]
#[Guarded(['id'])]
final class SourceProductMap extends Model
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
        ];
    }
}
