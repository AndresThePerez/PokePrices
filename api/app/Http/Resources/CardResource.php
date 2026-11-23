<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\CatalogResult;
use App\Domain\Catalog\PokeSearchClient;
use App\Domain\Catalog\Problems\UpstreamContract;
use App\Domain\Mapping\Models\CardVariant;
use App\Domain\Pricing\Listings;
use App\Domain\Pricing\PricingConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card as GET /v1/cards/{id} answers it: the PokéSearch card fields PokéPrices
 * shows, its set and images, the PokéPrices variants with their current source mappings and their latest daily
 * close (price), and the provenance of the catalog that answered.
 *
 * Each price also carries lowest_listing, the low listing of the observation its close came from (null when that
 * observation lists none or 0.00), and thin_market, true when the close is under half of it. A variant without a
 * close keeps price null; when its newest observation lists a low above zero it also carries listing, that lowest
 * listing with the observation's day and snapshot marker. listing is absent otherwise, and on every priced variant.
 *
 * Image URLs are passed through as PokéSearch sends them (OQ4: the SPA rewrites them). hp, rarity and artist
 * are null and subtypes and types empty when PokéSearch omits them. catalog.status is the variant rows' catalog
 * check: catalog_missing when any row is flagged, ok when every row is verified, otherwise (and for a card
 * without rows) unverified.
 */
final class CardResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $card  a PokéSearch card checked by PokeSearchClient::assertCard()
     * @param  Collection<int, CardVariant>  $variants  the card's variant rows, sources and their builds loaded
     * @param  string|null  $staleSince  when a degraded answer's last-good copy was fetched; null for a fresh answer
     */
    public function __construct(
        array $card,
        private readonly ?string $seedRef,
        private readonly ?string $seededAt,
        private readonly Collection $variants,
        private readonly ?string $staleSince = null,
    ) {
        parent::__construct($card);
    }

    /**
     * The resource for a catalog read of PokéSearch GET /api/v1/cards/{id} ({data: card, seed: {ref, seeded_at}}).
     *
     * PokeSearchClient::card() has already checked a fresh answer; the same check runs once more here so a copy
     * cached before that check existed still answers 502, never 500.
     *
     * @param  Collection<int, CardVariant>  $variants
     *
     * @throws UpstreamContract when the answer is not a card with the requested id and a seed
     */
    public static function fromResult(CatalogResult $result, string $id, Collection $variants): self
    {
        $answer = $result->data;
        PokeSearchClient::assertCard($answer, $id);

        return new self($answer['data'], $answer['seed']['ref'], $answer['seed']['seeded_at'], $variants, $result->degraded ? (string) $result->staleSince : null);
    }

    /**
     * @return array{
     *     id: string, name: string, supertype: string, subtypes: list<string>, hp: int|null, types: list<string>,
     *     rarity: string|null, number: string, artist: string|null,
     *     set: array{id: string, name: string, series: string, total: int, release_date: string},
     *     images: array{small: string, large: string},
     *     variants: list<array{variant_key: string, finish: string, edition: string, treatment: string, sources: list<array{source: string, product_id: int, subtype: string, group_id: int, series_epoch: int, map_snapshot_day: string}>, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int, lowest_listing: string|null, thin_market: bool}|null, listing?: array{source: string, feed: string, currency: string, cadence: string, lowest_listing: string, day: string, as_of: string}}>,
     *     catalog: array{seed_ref: string|null, seeded_at: string|null, status: string}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: string, name: string, supertype: string, subtypes?: list<string>, hp?: int, types?: list<string>, rarity?: string, number: string, artist?: string, set_id: string, set_name: string, set_series: string, set_total: int, release_date: string, image_small: string, image_large: string} $card */
        $card = $this->resource;

        return [
            'id' => $card['id'],
            'name' => $card['name'],
            'supertype' => $card['supertype'],
            /** @var list<string> */
            'subtypes' => $card['subtypes'] ?? [],
            'hp' => isset($card['hp']) ? (int) $card['hp'] : null,
            /** @var list<string> */
            'types' => $card['types'] ?? [],
            'rarity' => isset($card['rarity']) ? (string) $card['rarity'] : null,
            'number' => $card['number'],
            'artist' => isset($card['artist']) ? (string) $card['artist'] : null,
            'set' => [
                'id' => $card['set_id'],
                'name' => $card['set_name'],
                'series' => $card['set_series'],
                'total' => (int) $card['set_total'],
                'release_date' => $card['release_date'],
            ],
            'images' => [
                'small' => $card['image_small'],
                'large' => $card['image_large'],
            ],
            /** @var list<array{variant_key: string, finish: string, edition: string, treatment: string, sources: list<array{source: string, product_id: int, subtype: string, group_id: int, series_epoch: int, map_snapshot_day: string}>, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int, lowest_listing: string|null, thin_market: bool}|null, listing?: array{source: string, feed: string, currency: string, cadence: string, lowest_listing: string, day: string, as_of: string}}> */
            'variants' => $this->variants(),
            'catalog' => [
                /** @var string|null */
                'seed_ref' => $this->seedRef,
                /** @var string|null */
                'seeded_at' => $this->seededAt,
                'status' => $this->catalogStatus(),
            ],
        ];
    }

    /**
     * meta: degraded, and stale_since only on a degraded answer (the time its last-good copy was fetched).
     *
     * @return array{meta: array{degraded: bool, stale_since?: string}}
     */
    public function with(Request $request): array
    {
        return [
            /** @var array{degraded: bool, stale_since?: string} */
            'meta' => $this->staleSince === null ? ['degraded' => false] : ['degraded' => true, 'stale_since' => $this->staleSince],
        ];
    }

    /** @return list<array{variant_key: string, finish: string, edition: string, treatment: string, sources: list<array{source: string, product_id: int, subtype: string, group_id: int, series_epoch: int, map_snapshot_day: string}>, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int, lowest_listing: string|null, thin_market: bool}|null, listing?: array{source: string, feed: string, currency: string, cadence: string, lowest_listing: string, day: string, as_of: string}}> */
    private function variants(): array
    {
        $latest = [];
        foreach ($this->variants as $variant) {
            $latest[$variant->variant_key] = $variant->latestPrice;
        }
        // One query for every variant's listing, whatever the number of variants.
        $listings = Listings::forVariants($latest);

        $variants = [];
        foreach ($this->variants as $variant) {
            $sources = [];
            foreach ($variant->sources as $source) {
                $sources[] = [
                    'source' => $source->source,
                    'product_id' => $source->source_product_id,
                    'subtype' => $source->source_subtype,
                    'group_id' => $source->source_group_id,
                    'series_epoch' => $source->series_epoch,
                    'map_snapshot_day' => $source->mapBuild->snapshot_day->format('Y-m-d'),
                ];
            }
            $listing = $listings[$variant->variant_key] ?? null;
            $one = [
                'variant_key' => $variant->variant_key,
                'finish' => $variant->finish,
                'edition' => $variant->edition,
                'treatment' => $variant->treatment,
                'sources' => $sources,
                'price' => PriceBlock::withListing($variant->latestPrice, $listing['lowest'] ?? null, $listing['thin'] ?? false),
            ];
            if ($variant->latestPrice === null && $listing !== null && $listing['lowest'] !== null) {
                $one['listing'] = self::listing($listing['lowest'], $listing['day'], $listing['as_of']);
            }
            $variants[] = $one;
        }

        return $variants;
    }

    /**
     * The newest lowest listing of a variant without a close: no price, only what its newest observation lists, with
     * that observation's day and snapshot marker.
     *
     * @return array{source: string, feed: string, currency: string, cadence: string, lowest_listing: string, day: string, as_of: string}
     */
    private static function listing(string $lowest, string $day, string $asOf): array
    {
        $provenance = PricingConfig::provenance();

        return [
            'source' => $provenance['source'],
            'feed' => $provenance['feed'],
            'currency' => $provenance['currency'],
            'cadence' => PricingConfig::CADENCE,
            'lowest_listing' => $lowest,
            'day' => $day,
            'as_of' => $asOf,
        ];
    }

    private function catalogStatus(): string
    {
        $statuses = $this->variants->pluck('catalog_status')->unique()->values()->all();

        return match (true) {
            in_array('catalog_missing', $statuses, true) => 'catalog_missing',
            $statuses === ['ok'] => 'ok',
            default => 'unverified',
        };
    }
}
