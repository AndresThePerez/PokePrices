<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use App\Domain\Pricing\Models\PriceLatest;
use App\Domain\Pricing\PricingConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property-read PriceLatest|null $latestPrice
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

    /**
     * The variant's latest TCGplayer close (P2-i1 T05): its price_latest row for the configured source, if any.
     *
     * @return HasOne<PriceLatest, $this>
     */
    public function latestPrice(): HasOne
    {
        return $this->hasOne(PriceLatest::class, 'variant_key', 'variant_key')->where('source', PricingConfig::source());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'catalog_checked_at' => 'immutable_datetime',
        ];
    }
}
