<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Http\Problems\CardNotFound;
use App\Http\Problems\UpstreamContract;
use App\Http\Problems\UpstreamUnavailable;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use LogicException;

/**
 * PokéSearch answers through the catalog cache (plan T42, F-P1-025, review-1 recs 9-10, R13).
 *
 * - Seed ref: Cache::flexible('catalog:v1:seed_ref', [60, 300]) over GET /api/meta. Every upstream read also
 *   writes catalog:v1:seed_ref:last on redis-queue (30 days), the copy used while PokéSearch is down. A read
 *   whose ref differs from that copy queues VerifyCatalog (T45).
 * - Card: catalog:v1:card:{seedRef}:{id}, flexible [3600, 86400]; a 404 is remembered 60 s under …:none.
 * - Search: catalog:v1:search:{seedRef}:{sha1(normalised q|page|page_size)}, flexible [300, 900].
 * - Every upstream success also writes a last-good copy, catalog:v1:lastgood:{card|search}:…, for 7 days.
 *   A 5xx, a timeout or a contract violation is never cached.
 * - A cold key is filled under the lock catalog:v1:fill:{key} (5 s), waiting at most 2 s for another filler.
 * - Degraded: when the upstream call fails (UpstreamUnavailable from the client, which is where T35's breaker
 *   reports "open", or no fill lock within 2 s) and a last-good copy exists, the answer is that copy flagged
 *   degraded with stale_since; without one the UpstreamUnavailable (503, Retry-After) goes to the caller.
 *
 * phpunit forces CACHE_STORE=array, so the stores are named: "redis" (data on redis-cache, locks on
 * redis-queue) and "locks" (redis-queue). Each stored value carries the time it was fetched, and a value past
 * its window by the application clock counts as absent even before Redis evicts it, so the windows hold
 * against Carbon's clock as well as Redis's.
 */
final class CatalogRepository
{
    public const string STORE = 'redis';

    public const string SEED_LAST_STORE = 'locks';

    /** @var array{0: int, 1: int} */
    public const array SEED_TTL = [60, 300];

    /** @var array{0: int, 1: int} */
    public const array CARD_TTL = [3600, 86400];

    /** @var array{0: int, 1: int} */
    public const array SEARCH_TTL = [300, 900];

    public const int NEGATIVE_SECONDS = 60;

    public const int LAST_GOOD_SECONDS = 7 * 86400;

    public const int SEED_LAST_SECONDS = 30 * 86400;

    public const int FILL_LOCK_SECONDS = 5;

    public const int FILL_WAIT_SECONDS = 2;

    private const string SEED_KEY = 'catalog:v1:seed_ref';

    private const string SEED_LAST_KEY = 'catalog:v1:seed_ref:last';

    private readonly Repository $store;

    private readonly Repository $seedLastStore;

    public function __construct(private readonly PokeSearchClient $client)
    {
        $this->store = self::repository(self::STORE);
        $this->seedLastStore = self::repository(self::SEED_LAST_STORE);
    }

    /**
     * The seed ref PokéSearch serves, or the remembered one while it is down.
     *
     * @throws UpstreamUnavailable when PokéSearch is down and no seed ref was ever remembered
     * @throws UpstreamContract when /api/meta answers outside its contract
     */
    public function seedRef(): SeedRef
    {
        try {
            [$envelope] = $this->remember(self::SEED_KEY, self::SEED_TTL, function (): array {
                $seed = SeedRef::fromMeta($this->client->meta());
                $last = SeedRef::fromArray($this->seedLastStore->get(self::SEED_LAST_KEY));
                $this->seedLastStore->put(self::SEED_LAST_KEY, $seed->toArray(), self::SEED_LAST_SECONDS);
                if ($last !== null && $last->ref !== $seed->ref) {
                    // T45: PokéSearch was reseeded; check which mapped cards it still has.
                    VerifyCatalog::dispatch();
                }

                return self::envelopeOf($seed->toArray());
            });
        } catch (UpstreamUnavailable $e) {
            return SeedRef::fromArray($this->seedLastStore->get(self::SEED_LAST_KEY)) ?? throw $e;
        }

        return SeedRef::fromArray($envelope['data']) ?? throw new UpstreamContract('The cached seed ref is unreadable.');
    }

    /**
     * GET /api/v1/cards/{id} through the cache: the answer, or null when PokéSearch has no such card.
     *
     * @throws InvalidArgumentException when the id fails the card id pattern (nothing is read or sent)
     * @throws UpstreamUnavailable when PokéSearch failed and no last-good copy younger than 7 days exists
     * @throws UpstreamContract when PokéSearch answers outside its contract
     */
    public function card(string $id): ?CatalogResult
    {
        CardId::assertValid($id);
        $lastGood = "catalog:v1:lastgood:card:{$id}";

        try {
            $key = "catalog:v1:card:{$this->seedRef()->segment()}:{$id}";
            // The marker is the time of the 404; the Redis store hands a numeric value back as a string.
            $missing = $this->store->get("{$key}:none");
            if (is_numeric($missing) && (int) $missing + self::NEGATIVE_SECONDS > self::now()) {
                return null;
            }

            [$envelope, $fetched] = $this->remember($key, self::CARD_TTL, function () use ($id, $key, $lastGood): array {
                $answer = $this->client->card($id);
                if ($answer === null) {
                    $this->store->put("{$key}:none", self::now(), self::NEGATIVE_SECONDS);

                    throw new CardNotFound;
                }

                return $this->keepLastGood($lastGood, $answer);
            });
        } catch (CardNotFound) {
            return null;
        } catch (UpstreamUnavailable $e) {
            return $this->lastGood($lastGood, $e);
        }

        return new CatalogResult($envelope['data'], cached: ! $fetched);
    }

    /**
     * GET /api/search with q, page and page_size through the cache. The key normalises q (trimmed, inner
     * whitespace collapsed, lower-cased, as PokéSearch matches it); the q sent upstream is the caller's.
     *
     * @throws UpstreamUnavailable when PokéSearch failed and no last-good copy younger than 7 days exists
     * @throws UpstreamContract when PokéSearch answers outside its contract
     */
    public function search(string $q, ?int $page = null, ?int $pageSize = null): CatalogResult
    {
        $hash = sha1(self::normalise($q).'|'.($page ?? '').'|'.($pageSize ?? ''));
        $lastGood = "catalog:v1:lastgood:search:{$hash}";

        try {
            $key = "catalog:v1:search:{$this->seedRef()->segment()}:{$hash}";
            [$envelope, $fetched] = $this->remember($key, self::SEARCH_TTL, fn (): array => $this->keepLastGood(
                $lastGood,
                $this->client->search($q, $page, $pageSize),
            ));
        } catch (UpstreamUnavailable $e) {
            return $this->lastGood($lastGood, $e);
        }

        return new CatalogResult($envelope['data'], cached: ! $fetched);
    }

    /**
     * Trimmed, inner whitespace runs collapsed to one space, lower-cased. A q that is not valid UTF-8 is kept
     * byte for byte: folding it would map distinct queries onto one key.
     */
    public static function normalise(string $q): string
    {
        if (! mb_check_encoding($q, 'UTF-8')) {
            return $q;
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $q)));
    }

    /**
     * Cache::flexible over $fill, with a cold key filled under the fill lock. Returns the envelope and whether
     * it is the one this read fetched from PokéSearch (a stale answer whose refresh ran in the same process is
     * still the cached copy).
     *
     * @param  array{0: int, 1: int}  $ttl  fresh and hard windows, in seconds
     * @param  Closure(): array{data: array<mixed>, fetched_at: int}  $fill
     * @return array{0: array{data: array<mixed>, fetched_at: int}, 1: bool}
     *
     * @throws UpstreamUnavailable when the fill failed or another filler held the lock for 2 s
     */
    private function remember(string $key, array $ttl, Closure $fill): array
    {
        $fetched = null;
        $counted = function () use ($fill, &$fetched): array {
            return $fetched = $fill();
        };

        if (self::live($this->store->get($key), $ttl[1]) !== null) {
            // Fresh, or stale inside the hard window: flexible answers now and refreshes after the response.
            $envelope = self::live($this->store->flexible($key, $ttl, $counted), $ttl[1]);
            if ($envelope !== null) {
                return [$envelope, $fetched === $envelope];
            }
        }

        $locks = $this->store->getStore();
        if (! $locks instanceof LockProvider) {
            throw new LogicException('The catalog cache store cannot lock.');
        }

        try {
            $envelope = $locks->lock("catalog:v1:fill:{$key}", self::FILL_LOCK_SECONDS)->block(
                self::FILL_WAIT_SECONDS,
                function () use ($key, $ttl, $counted): mixed {
                    // Another filler may have finished while this one waited; a value past its hard window by
                    // the application clock is dropped so flexible fills it again.
                    if (self::live($this->store->get($key), $ttl[1]) === null) {
                        $this->store->forget($key);
                    }

                    return $this->store->flexible($key, $ttl, $counted);
                },
            );
        } catch (LockTimeoutException $e) {
            throw new UpstreamUnavailable(PokeSearchClient::RETRY_AFTER_SECONDS, $e);
        }

        $live = self::live($envelope, $ttl[1]);
        if ($live === null) {
            throw new LogicException("The catalog cache returned no usable value for {$key}.");
        }

        return [$live, $fetched === $live];
    }

    /**
     * @param  array<mixed>  $data
     * @return array{data: array<mixed>, fetched_at: int}
     */
    private function keepLastGood(string $lastGood, array $data): array
    {
        $envelope = self::envelopeOf($data);
        $this->store->put($lastGood, $envelope, self::LAST_GOOD_SECONDS);

        return $envelope;
    }

    /** @throws UpstreamUnavailable the failure itself when no last-good copy younger than 7 days exists */
    private function lastGood(string $lastGood, UpstreamUnavailable $failure): CatalogResult
    {
        $envelope = self::live($this->store->get($lastGood), self::LAST_GOOD_SECONDS) ?? throw $failure;

        return new CatalogResult(
            $envelope['data'],
            cached: true,
            degraded: true,
            staleSince: Carbon::createFromTimestampUTC($envelope['fetched_at'])->format('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array{data: array<mixed>, fetched_at: int}
     */
    private static function envelopeOf(array $data): array
    {
        return ['data' => $data, 'fetched_at' => self::now()];
    }

    /**
     * A stored envelope younger than $maxAge seconds by the application clock, else null.
     *
     * @return array{data: array<mixed>, fetched_at: int}|null
     */
    private static function live(mixed $value, int $maxAge): ?array
    {
        if (! is_array($value) || ! is_array($value['data'] ?? null) || ! is_int($value['fetched_at'] ?? null)) {
            return null;
        }

        return self::now() < $value['fetched_at'] + $maxAge ? ['data' => $value['data'], 'fetched_at' => $value['fetched_at']] : null;
    }

    private static function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    private static function repository(string $name): Repository
    {
        $store = Cache::store($name);
        if (! $store instanceof Repository) {
            throw new LogicException("Cache store {$name} is not a cache repository.");
        }

        return $store;
    }
}
