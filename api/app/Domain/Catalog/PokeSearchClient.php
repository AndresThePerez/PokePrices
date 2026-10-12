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
 */
final class PokeSearchClient
{
    /**
     * Retry-After, in seconds, when PokéSearch failed this call: it has already had its one fast retry, so
     * the caller is asked to wait a few seconds rather than hammer it. T35's breaker replaces this with the
     * remaining open time once it opens.
     */
    public const int RETRY_AFTER_SECONDS = 5;

    /** The pagination fields of a PokéSearch search answer, each an integer passed on unchanged. */
    private const array SEARCH_PAGE_FIELDS = ['total', 'page', 'pages', 'page_size'];

    /** The fields every PokéSearch search hit carries as strings (only rarity may be omitted). */
    private const array SEARCH_HIT_STRINGS = ['id', 'name', 'number', 'supertype', 'set_id', 'set_name', 'release_date', 'image_small'];

    /**
     * GET /api/v1/cards/{id}: the decoded answer, or null when PokéSearch has no such card (404).
     *
     * @return array<mixed>|null
     *
     * @throws InvalidArgumentException when the id fails the card id pattern (nothing is sent)
     * @throws UpstreamUnavailable when PokéSearch cannot be reached or answers 5xx
     * @throws UpstreamContract when PokéSearch answers any other non-2xx or a body that is not a JSON object
     */
    public function card(string $id): ?array
    {
        CardId::assertValid($id);

        $response = $this->get('/api/v1/cards/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null;
        }

        return $this->decoded($response);
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

    /** @param array<string, string|int> $query */
    private function get(string $path, array $query = []): Response
    {
        try {
            $response = Http::pokesearch()->get($path, $query === [] ? null : $query);
        } catch (ConnectionException $e) {
            throw new UpstreamUnavailable(self::RETRY_AFTER_SECONDS, $e);
        }

        if ($response->serverError()) {
            throw new UpstreamUnavailable(self::RETRY_AFTER_SECONDS, $response->toException());
        }

        return $response;
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
