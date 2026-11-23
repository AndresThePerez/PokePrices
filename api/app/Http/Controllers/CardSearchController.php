<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Pricing\LatestPrices;
use App\Http\CachePolicy;
use App\Http\Resources\CardSummaryResource;
use Closure;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

#[Group('Cards', 'Pokémon cards from the PokéSearch catalog.')]
final class CardSearchController
{
    /** The only query parameters accepted; anything else (debug, a PokéSearch filter) is a 422, never forwarded. */
    private const array PARAMETERS = ['q', 'page', 'page_size', 'sort'];

    /** The most words a q may have: PokéSearch refuses a q of more terms (400 invalid_param on q). */
    public const int MAX_WORDS = 32;

    /** The 422 message on q above MAX_WORDS. */
    public const string WORD_CAP = 'A search can have at most 32 words.';

    /** A price order orders this many matches: PokéSearch's largest page, the first one. */
    public const int PRICE_WINDOW = 100;

    /** The page size of a price order when page_size is absent, as PokéSearch's own default. */
    public const int PRICE_PAGE_SIZE = 24;

    /**
     * Whitespace as PokéSearch splits q into terms (Go's strings.Fields, unicode.IsSpace): tab to carriage return, space,
     * U+0085, U+00A0, U+1680, U+2000 to U+200A, U+2028, U+2029, U+202F, U+205F and U+3000. Matched as UTF-8 bytes, so
     * a q that is not valid UTF-8 splits the way Go decodes it (a bad byte is never a space).
     */
    private const string TERM_GAP = '/(?:[\t\n\x0B\f\r ]|\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+/';

    /** The search goes through the catalog cache: cached answers, and the degraded last-good copy. */
    public function __construct(private readonly CatalogRepository $catalog, private readonly ConnectionInterface $db) {}

    /**
     * Search cards in the PokéSearch catalog, with each card's headline price.
     *
     * Proxies PokéSearch's search with q (the search text, 1 to 200 characters and at most 32 words, counted as PokéSearch counts terms: runs of whitespace separate them), page (from 1), page_size (PokéSearch's default is 24) and sort only; any other query parameter answers 422. Each row carries variant, the card's first variant in card order (by variant key, as the card page lists them) that has a close, and price, that variant's newest daily close from TCGplayer market prices via TCGCSV with source, feed, day and as_of, the previous close and the change; both are null when no variant of the card has a close. sort is relevance (the default: PokéSearch's order and paging, passed through unchanged; PokéSearch clamps page to its last reachable page, and pages stops at its 9,600-result window, so read pages rather than computing it from total), price_desc or price_asc. A price order takes PokéSearch's first 100 matches (its largest page), orders them by the headline close, rows without a price last and equal closes in PokéSearch's order, and pages that list itself: meta.ordered is how many matches were ordered (null under relevance), meta.total how many exist, meta.pages is at least 1, and a page past the last answers the last page. While PokéSearch is unavailable the last good copy answers with meta.degraded true, or 503 when there is none.
     *
     * @operationId searchCards
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $unknown = array_diff(array_map('strval', array_keys($request->query())), self::PARAMETERS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This query parameter is not accepted.'));
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && self::words($value) > self::MAX_WORDS) {
                    $fail(self::WORD_CAP);
                }
            }],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'in:relevance,price_desc,price_asc'],
        ]);

        $q = (string) $validated['q'];
        $page = isset($validated['page']) ? (int) $validated['page'] : null;
        $pageSize = isset($validated['page_size']) ? (int) $validated['page_size'] : null;
        $sort = isset($validated['sort']) ? (string) $validated['sort'] : 'relevance';
        $byPrice = $sort !== 'relevance';

        $result = $byPrice
            ? $this->catalog->search($q, 1, self::PRICE_WINDOW)
            : $this->catalog->search($q, $page, $pageSize);
        // PokeSearchClient::search() checked this shape before the answer was cached; the casts are no-ops that
        // keep the integers visible to the contract export (Scramble does not read the @var).
        /** @var array{total: int, page: int, pages: int, page_size: int, results: list<array{id: string}&array<string, mixed>>} $answer */
        $answer = $result->data;
        // conditional-get reads this, never the body, to answer a degraded copy no-store.
        $request->attributes->set(CachePolicy::DEGRADED, $result->degraded);

        $hits = $answer['results'];
        // One query for the headline of every row (all the ordered matches in a price order), whatever their number.
        $headlines = LatestPrices::headlines($this->db, array_map(fn (array $hit): string => $hit['id'], $hits));
        $current = (int) $answer['page'];
        $pages = (int) $answer['pages'];
        $size = (int) $answer['page_size'];
        $ordered = null;
        if ($byPrice) {
            // This answer owns the paging: at least one page, and a page past the last answers the last.
            $ordered = count($hits);
            $size = $pageSize ?? self::PRICE_PAGE_SIZE;
            $pages = (int) max(1, intdiv($ordered + $size - 1, $size));
            $current = (int) min($page ?? 1, $pages);
            $hits = array_slice(self::byPrice($hits, $headlines, $sort === 'price_desc'), ($current - 1) * $size, $size);
        }

        $rows = array_map(fn (array $hit): CardSummaryResource => new CardSummaryResource($hit, $headlines[$hit['id']] ?? null), $hits);

        return CardSummaryResource::collection($rows)->additional(['meta' => [
            'total' => (int) $answer['total'],
            'page' => (int) $current,
            'pages' => (int) $pages,
            'page_size' => (int) $size,
            'degraded' => $result->degraded,
            'cached' => $result->cached,
            /** @var 'relevance'|'price_desc'|'price_asc' */
            'sort' => $sort,
            /** @var int|null */
            'ordered' => $ordered,
        ]]);
    }

    /** The number of terms PokéSearch makes of $q: the runs of text between runs of TERM_GAP whitespace. */
    public static function words(string $q): int
    {
        $terms = preg_split(self::TERM_GAP, $q, -1, PREG_SPLIT_NO_EMPTY);

        return $terms === false ? 0 : count($terms);
    }

    /**
     * The hits by headline close (dearest first when $descending), rows without a close last; equal closes, and the
     * rows without one, keep PokéSearch's order.
     *
     * @template T of array{id: string}
     *
     * @param  list<T>  $hits
     * @param  array<string, array<string, mixed>>  $headlines
     * @return list<T>
     */
    private static function byPrice(array $hits, array $headlines, bool $descending): array
    {
        $keyed = [];
        foreach ($hits as $rank => $hit) {
            $close = $headlines[$hit['id']]['price_close'] ?? null;
            $keyed[] = ['hit' => $hit, 'rank' => $rank, 'close' => is_string($close) ? self::sortable($close) : null];
        }
        usort($keyed, function (array $a, array $b) use ($descending): int {
            if ($a['close'] === null || $b['close'] === null) {
                return [$a['close'] === null, $a['rank']] <=> [$b['close'] === null, $b['rank']];
            }
            $order = strcmp($a['close'], $b['close']);

            return ($descending ? -$order : $order) ?: $a['rank'] <=> $b['rank'];
        });

        return array_map(fn (array $one): array => $one['hit'], $keyed);
    }

    /**
     * A close (numeric(12,2) as Postgres gives it: digits, a point, two digits) padded on the left so that two of
     * them compare as strings the way they compare as amounts; the money is never parsed into a float.
     */
    private static function sortable(string $close): string
    {
        return str_pad($close, 24, '0', STR_PAD_LEFT);
    }
}
