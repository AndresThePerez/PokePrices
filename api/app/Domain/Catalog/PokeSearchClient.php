<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Http\Problems\UpstreamContract;
use App\Http\Problems\UpstreamUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Reads cards from the PokéSearch v1 API (plan T34) and searches its /api/search (T44) through the
 * Http::pokesearch() macro.
 *
 * Ids are checked against CardId before anything is sent, then percent-encoded with rawurlencode: a path
 * segment on its own, a batch as the comma-joined list ("ex10-?" travels as "ex10-%3F", never truncated).
 * While the circuit breaker is open every method throws UpstreamUnavailable without sending anything.
 */
final class PokeSearchClient
{
    /**
     * Retry-After, in seconds, when PokéSearch failed this call: it has already had its one fast retry, so
     * the caller is asked to wait a few seconds rather than hammer it. Once the circuit breaker opens, its
     * remaining open time replaces this.
     */
    public const int RETRY_AFTER_SECONDS = 5;

    /**
     * The container builds the client with the request-path circuit breaker (plan T35), so every read through
     * CatalogRepository, the controllers and the catalog job passes it. A client built without one (the T34
     * unit tests) calls PokéSearch directly.
     */
    public function __construct(private readonly ?CircuitBreaker $breaker = null) {}

    /** The pagination fields of a PokéSearch search answer, each an integer passed on unchanged. */
    private const array SEARCH_PAGE_FIELDS = ['total', 'page', 'pages', 'page_size'];

    /** The fields every PokéSearch search hit carries as strings (only rarity may be omitted). */
    private const array SEARCH_HIT_STRINGS = ['id', 'name', 'number', 'supertype', 'set_id', 'set_name', 'release_date', 'image_small'];

    /** PokéSearch card fields every answer carries as strings (docs/api/schema/card.json in PokéSearch). */
    private const array CARD_REQUIRED_STRINGS = ['id', 'name', 'supertype', 'number', 'set_id', 'set_name', 'set_series', 'release_date', 'image_small', 'image_large'];

    /** PokéSearch card fields that are strings when present. */
    private const array CARD_OPTIONAL_STRINGS = ['rarity', 'artist'];

    /** PokéSearch card fields that are lists of strings when present. */
    private const array CARD_OPTIONAL_LISTS = ['subtypes', 'types'];

    /**
     * GET /api/v1/cards/{id}: the decoded answer, its shape checked by assertCard(), or null when PokéSearch has
     * no such card (404). A body outside the card contract throws before it is returned, so no cache keeps it.
     *
     * @return array<mixed>|null
     *
     * @throws InvalidArgumentException when the id fails the card id pattern (nothing is sent)
     * @throws UpstreamUnavailable when PokéSearch cannot be reached or answers 5xx
     * @throws UpstreamContract when PokéSearch answers any other non-2xx or a body outside the card contract
     */
    public function card(string $id): ?array
    {
        CardId::assertValid($id);

        $response = $this->get('/api/v1/cards/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null;
        }

        $answer = $this->decoded($response);
        self::assertCard($answer, $id);

        return $answer;
    }

    /**
     * The card contract of GET /api/v1/cards/{id} ({data: card, seed: {ref, seeded_at}}), the one definition
     * card() checks before it returns and CardResource checks again on a copy cached before card() did.
     *
     * data is an object with the requested id, the required string fields and an integer set_total; hp, when
     * present, is an integer; rarity and artist, when present, are strings; subtypes and types, when present,
     * are lists of strings. seed holds ref and seeded_at, each a string or null. Anything else is passed on.
     *
     * @param  array<mixed>  $answer
     *
     * @phpstan-assert array{data: array<string, mixed>, seed: array{ref: string|null, seeded_at: string|null}} $answer
     *
     * @throws UpstreamContract when the answer is not a card with the requested id and a seed
     */
    public static function assertCard(array $answer, string $id): void
    {
        $card = $answer['data'] ?? null;
        if (! is_array($card) || array_is_list($card)) {
            throw new UpstreamContract('PokéSearch card answer has no card object.');
        }
        foreach (self::CARD_REQUIRED_STRINGS as $field) {
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
        foreach (self::CARD_OPTIONAL_STRINGS as $field) {
            if (array_key_exists($field, $card) && ! is_string($card[$field])) {
                throw new UpstreamContract("PokéSearch card has a {$field} that is not a string.");
            }
        }
        foreach (self::CARD_OPTIONAL_LISTS as $field) {
            $list = $card[$field] ?? [];
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn (mixed $v): bool => ! is_string($v)) !== []) {
                throw new UpstreamContract("PokéSearch card has a {$field} that is not a list of strings.");
            }
        }

        $seed = $answer['seed'] ?? null;
        if (! is_array($seed) || ! array_key_exists('ref', $seed) || ! array_key_exists('seeded_at', $seed)) {
            throw new UpstreamContract('PokéSearch card answer has no seed.');
        }
        if (($seed['ref'] !== null && ! is_string($seed['ref'])) || ($seed['seeded_at'] !== null && ! is_string($seed['seeded_at']))) {
            throw new UpstreamContract('PokéSearch card answer has a seed that is not strings or null.');
        }
    }

    /**
     * GET /api/meta: build identity and corpus provenance (seed: {ref, seeded_at} or null), decoded (plan T42).
     *
     * @return array<mixed>
     *
     * @throws UpstreamUnavailable when PokéSearch cannot be reached or answers 5xx
     * @throws UpstreamContract when PokéSearch answers any other non-2xx or a body that is not a JSON object
     */
    public function meta(): array
    {
        return $this->decoded($this->get('/api/meta'));
    }

    /**
     * GET /api/v1/cards?ids=a,b,c: the decoded answer (found cards in data, the rest in missing).
     *
     * @param  list<string>  $ids
     * @return array<mixed>
     *
     * @throws InvalidArgumentException when the list is empty or any id fails the card id pattern (nothing is sent)
     * @throws UpstreamUnavailable when PokéSearch cannot be reached or answers 5xx
     * @throws UpstreamContract when PokéSearch answers any other non-2xx or a body that is not a JSON object
     */
    public function cards(array $ids): array
    {
        if ($ids === []) {
            throw new InvalidArgumentException('At least one card id is required.');
        }
        foreach ($ids as $id) {
            CardId::assertValid($id);
        }

        return $this->decoded($this->get('/api/v1/cards?ids='.rawurlencode(implode(',', $ids))));
    }

    /**
     * GET /api/search with only q, page and page_size (never debug or a filter): the answer, its shape checked.
     *
     * The returned array holds total, page, pages and page_size as PokéSearch sent them and results as a list of
     * hits; each hit carries string id, name, number, supertype, set_id, set_name, release_date and image_small,
     * and rarity as a string or absent (PokéSearch omits an empty rarity). Everything else is passed on as sent.
     *
     * @return array{total: int, page: int, pages: int, page_size: int, results: list<array<string, mixed>>}
     *
     * @throws UpstreamUnavailable when PokéSearch cannot be reached or answers 5xx
     * @throws UpstreamContract when PokéSearch answers any other non-2xx or a body outside that shape
     */
    public function search(string $q, ?int $page = null, ?int $pageSize = null): array
    {
        $query = array_filter(['q' => $q, 'page' => $page, 'page_size' => $pageSize], fn (string|int|null $v): bool => $v !== null);
        $body = $this->decoded($this->get('/api/search', $query));

        $pagination = [];
        foreach (self::SEARCH_PAGE_FIELDS as $field) {
            if (! is_int($body[$field] ?? null)) {
                throw new UpstreamContract("PokéSearch search answer has no integer {$field}.");
            }
            $pagination[$field] = $body[$field];
        }
        $results = $body['results'] ?? null;
        if (! is_array($results) || ! array_is_list($results)) {
            throw new UpstreamContract('PokéSearch search answer has no results list.');
        }
        foreach ($results as $hit) {
            self::assertHit($hit);
        }

        /** @var list<array<string, mixed>> $results */
        return $pagination + ['results' => $results];
    }

    /** @phpstan-assert array<string, mixed> $hit */
    private static function assertHit(mixed $hit): void
    {
        if (! is_array($hit) || array_is_list($hit)) {
            throw new UpstreamContract('PokéSearch search hit is not a JSON object.');
        }
        foreach (self::SEARCH_HIT_STRINGS as $field) {
            if (! is_string($hit[$field] ?? null)) {
                throw new UpstreamContract("PokéSearch search hit has no string {$field}.");
            }
        }
        if (array_key_exists('rarity', $hit) && ! is_string($hit['rarity'])) {
            throw new UpstreamContract('PokéSearch search hit has a rarity that is not a string.');
        }
    }

    /**
     * One GET through the circuit breaker when the client has one. A refused connection, a timeout or a 5xx
     * is a failure; any other answer, a 404 included, shows PokéSearch is up.
     *
     * @param  array<string, string|int>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        $send = function () use ($path, $query): Response {
            try {
                $response = Http::pokesearch()->get($path, $query === [] ? null : $query);
            } catch (ConnectionException $e) {
                throw new UpstreamUnavailable(self::RETRY_AFTER_SECONDS, $e);
            }

            if ($response->serverError()) {
                throw new UpstreamUnavailable(self::RETRY_AFTER_SECONDS, $response->toException());
            }

            return $response;
        };

        return $this->breaker === null ? $send() : $this->breaker->call($send);
    }

    /** @return array<mixed> */
    private function decoded(Response $response): array
    {
        if (! $response->successful()) {
            throw new UpstreamContract("PokéSearch answered HTTP {$response->status()}.", $response->toException());
        }

        $body = $response->json();
        if (! is_array($body) || array_is_list($body)) {
            throw new UpstreamContract('PokéSearch answered with a body that is not a JSON object.');
        }

        return $body;
    }
}
