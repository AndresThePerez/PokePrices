<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\CatalogRepository;
use App\Http\CachePolicy;
use App\Http\Resources\CardSummaryResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

#[Group('Cards', 'Pokémon cards from the PokéSearch catalog.')]
final class CardSearchController
{
    /** The only query parameters accepted; anything else (debug, a PokéSearch filter) is a 422, never forwarded. */
    private const array PARAMETERS = ['q', 'page', 'page_size'];

    /** The search goes through the catalog cache (T42): cached answers, and the degraded last-good copy. */
    public function __construct(private readonly CatalogRepository $catalog) {}

    /**
     * Search cards in the PokéSearch catalog.
     *
     * Proxies PokéSearch's search with q (the search text), page (from 1; PokéSearch clamps it to its last reachable page) and page_size (PokéSearch's default is 24) only; any other query parameter answers 422. Pagination passes through unchanged: pages is PokéSearch's count, which stops at its 9,600-result window, so read pages rather than computing it from total.
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
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $result = $this->catalog->search(
            (string) $validated['q'],
            isset($validated['page']) ? (int) $validated['page'] : null,
            isset($validated['page_size']) ? (int) $validated['page_size'] : null,
        );
        // PokeSearchClient::search() checked this shape before the answer was cached; the casts are no-ops that
        // keep the integers visible to the contract export (Scramble does not read the @var).
        /** @var array{total: int, page: int, pages: int, page_size: int, results: list<array<string, mixed>>} $answer */
        $answer = $result->data;
        // conditional-get reads this, never the body, to answer a degraded copy no-store (Q1-i5 T02).
        $request->attributes->set(CachePolicy::DEGRADED, $result->degraded);

        return CardSummaryResource::collection($answer['results'])->additional(['meta' => [
            'total' => (int) $answer['total'],
            'page' => (int) $answer['page'],
            'pages' => (int) $answer['pages'],
            'page_size' => (int) $answer['page_size'],
            'degraded' => $result->degraded,
            'cached' => $result->cached,
        ]]);
    }
}
