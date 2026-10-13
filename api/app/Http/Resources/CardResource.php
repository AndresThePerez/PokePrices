<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\CatalogResult;
use App\Domain\Mapping\Models\CardVariant;
use App\Domain\Pricing\Models\PriceLatest;
use App\Http\Problems\UpstreamContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card as GET /v1/cards/{id} answers it (plan T43, review-1 rec 12): the PokéSearch card fields PokéPrices
 * shows, its set and images, the PokéPrices variants with their current source mappings and their latest daily
 * close (price, P2-i1 T05), and the provenance of the catalog that answered.
 *
 * Image URLs are passed through as PokéSearch sends them (OQ4: the SPA rewrites them). hp, rarity and artist
 * are null and subtypes and types empty when PokéSearch omits them. catalog.status is the variant rows' catalog
 * check (T45): catalog_missing when any row is flagged, ok when every row is verified, otherwise (and for a card
 * without rows) unverified.
 */
final class CardResource extends JsonResource
{
    /** PokéSearch card fields every answer carries as strings (docs/api/schema/card.json in PokéSearch). */
    private const array REQUIRED_STRINGS = ['id', 'name', 'supertype', 'number', 'set_id', 'set_name', 'set_series', 'release_date', 'image_small', 'image_large'];

    /** PokéSearch card fields that are strings when present. */
    private const array OPTIONAL_STRINGS = ['rarity', 'artist'];

    /** PokéSearch card fields that are lists of strings when present. */
    private const array OPTIONAL_LISTS = ['subtypes', 'types'];

    /**
     * @param  array<string, mixed>  $card  a PokéSearch card checked by fromAnswer()
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
     * @param  Collection<int, CardVariant>  $variants
     *
     * @throws UpstreamContract when the answer is not a card with the requested id and a seed
     */
    public static function fromResult(CatalogResult $result, string $id, Collection $variants): self
    {
        $answer = $result->data;
        $card = $answer['data'] ?? null;
        if (! is_array($card) || array_is_list($card)) {
            throw new UpstreamContract('PokéSearch card answer has no card object.');
        }
        foreach (self::REQUIRED_STRINGS as $field) {
            if (! is_string($card[$field] ?? null)) {
                throw new UpstreamContract("PokéSearch card has no string {$field}.");
            }
        }
        if ($card['id'] !== $id) {
            throw new UpstreamContract('PokéSearch answered with another card.');
        }
        if (! is_int($card['set_total'] ?? null)) {
            throw new UpstreamContract('PokéSearch card has no integer set_total.');
        }
        if (array_key_exists('hp', $card) && ! is_int($card['hp'])) {
            throw new UpstreamContract('PokéSearch card has an hp that is not an integer.');
        }
        foreach (self::OPTIONAL_STRINGS as $field) {
            if (array_key_exists($field, $card) && ! is_string($card[$field])) {
                throw new UpstreamContract("PokéSearch card has a {$field} that is not a string.");
            }
        }
        foreach (self::OPTIONAL_LISTS as $field) {
            $list = $card[$field] ?? [];
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn (mixed $v): bool => ! is_string($v)) !== []) {
                throw new UpstreamContract("PokéSearch card has a {$field} that is not a list of strings.");
            }
        }

        $seed = $answer['seed'] ?? null;
        if (! is_array($seed) || ! array_key_exists('ref', $seed) || ! array_key_exists('seeded_at', $seed)) {
            throw new UpstreamContract('PokéSearch card answer has no seed.');
        }
        $ref = $seed['ref'];
        $seededAt = $seed['seeded_at'];
        if (($ref !== null && ! is_string($ref)) || ($seededAt !== null && ! is_string($seededAt))) {
            throw new UpstreamContract('PokéSearch card answer has a seed that is not strings or null.');
        }

        /** @var array<string, mixed> $card */
        return new self($card, $ref, $seededAt, $variants, $result->degraded ? (string) $result->staleSince : null);
    }

    /**
     * @return array{
     *     id: string, name: string, supertype: string, subtypes: list<string>, hp: int|null, types: list<string>,
     *     rarity: string|null, number: string, artist: string|null,
     *     set: array{id: string, name: string, series: string, total: int, release_date: string},
     *     images: array{small: string, large: string},
     *     variants: list<array{variant_key: string, finish: string, edition: string, treatment: string, sources: list<array{source: string, product_id: int, subtype: string, group_id: int, series_epoch: int, map_snapshot_day: string}>, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null}>,
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
            /** @var list<array{variant_key: string, finish: string, edition: string, treatment: string, sources: list<array{source: string, product_id: int, subtype: string, group_id: int, series_epoch: int, map_snapshot_day: string}>, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null}> */
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

    /** @return list<array{variant_key: string, finish: string, edition: string, treatment: string, sources: list<array{source: string, product_id: int, subtype: string, group_id: int, series_epoch: int, map_snapshot_day: string}>, price: array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null}> */
    private function variants(): array
    {
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
            $variants[] = [
                'variant_key' => $variant->variant_key,
                'finish' => $variant->finish,
                'edition' => $variant->edition,
                'treatment' => $variant->treatment,
                'sources' => $sources,
                'price' => self::price($variant->latestPrice),
            ];
        }

        return $variants;
    }

    /**
     * The price block of a variant (P2-i1 T05, D4, D5): its latest TCGplayer close via TCGCSV with the previous close
     * and change, or null when the collected snapshots hold no close for it. Money and change_pct are decimal
     * strings with two decimals as Postgres numeric(12,2) gives them; day and previous_day are YYYY-MM-DD and as_of
     * (the snapshot marker of the close) is RFC 3339 UTC.
     *
     * @return array{source: string, feed: string, basis: string, currency: string, cadence: string, close: string, day: string, as_of: string, previous_close: string|null, previous_day: string|null, change: string|null, change_pct: string|null, series_epoch: int}|null
     */
    private static function price(?PriceLatest $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        return [
            'source' => $latest->source,
            'feed' => (string) config('pricing.feed'),
            'basis' => 'market',
            'currency' => 'USD',
            'cadence' => 'daily',
            'close' => $latest->close,
            'day' => $latest->day->format('Y-m-d'),
            'as_of' => $latest->as_of->utc()->format('Y-m-d\TH:i:s\Z'),
            'previous_close' => $latest->previous_close,
            'previous_day' => $latest->previous_day?->format('Y-m-d'),
            'change' => $latest->change,
            'change_pct' => $latest->change_pct,
            'series_epoch' => $latest->series_epoch,
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
