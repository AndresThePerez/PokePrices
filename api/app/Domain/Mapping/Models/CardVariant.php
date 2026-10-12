<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced variant of a PokéSearch card, keyed "card_id:finish:edition:treatment" (plan T36). card_id has no
 * foreign key: PokéSearch owns card identity.
 *
 * @property string $variant_key
 * @property string $card_id
 * @property string $finish
 * @property string $edition
 * @property string $treatment
 * @property string $catalog_status
 * @property string|null $catalog_seed_ref
 * @property CarbonImmutable|null $catalog_checked_at
 * @property-read Collection<int, SourceProductMap> $sources
 */
#[Table('card_variants', key: 'variant_key', keyType: 'string', incrementing: false, timestamps: false)]
#[Unguarded]
final class CardVariant extends Model
{
    /**
     * The current source mappings of this variant (at most one per source).
     *
     * @return HasMany<SourceProductMap, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(SourceProductMap::class, 'variant_key', 'variant_key');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'catalog_checked_at' => 'immutable_datetime',
        ];
    }
}
