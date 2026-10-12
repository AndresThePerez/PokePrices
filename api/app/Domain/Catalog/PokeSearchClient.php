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
 * Reads cards from the PokéSearch v1 API through the Http::pokesearch() macro (plan T34).
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

    private function get(string $path): Response
    {
        try {
            $response = Http::pokesearch()->get($path);
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
