<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Problems\UpstreamUnavailable;
use Closure;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use LogicException;
use Throwable;

/**
 * The request-path circuit breaker in front of PokéSearch.
 *
 * Its state lives on the "locks" cache store, which is redis-queue (noeviction), so evicting cache data never
 * closes it:
 * - breaker:pokesearch counts failures (an UpstreamUnavailable from the call) within 30 s of the first one,
 *   with breaker:pokesearch:since holding when that window started;
 * - the fifth failure in the window writes breaker:pokesearch:open, the time the breaker may be probed again
 *   (30 s later). Until then no call is made and the caller gets UpstreamUnavailable with Retry-After = the
 *   remaining open seconds (at least 1);
 * - after that time exactly one caller claims breaker:pokesearch:probe (an atomic add) and makes the call;
 *   everyone else is refused while it is in flight. A successful probe closes the breaker, a failed one opens
 *   it for another 30 s;
 * - a success while closed clears the counter.
 *
 * Time is the application clock (Carbon), not Redis's, so the windows hold under travel() in tests as well.
 * The queued catalog job (VerifyCatalog) throttles with ThrottlesExceptionsWithRedis under the same
 * prefix, keyPrefix(), in a key of its own: that middleware keeps a Redis hash, this breaker plain values, and
 * one key cannot be both.
 */
final class CircuitBreaker
{
    public const string STORE = 'locks';

    /** The key prefix both the request breaker and the queued catalog job's throttle live under. */
    public const string PREFIX = 'breaker:';

    public const string POKESEARCH = 'pokesearch';

    public const int THRESHOLD = 5;

    public const int WINDOW_SECONDS = 30;

    public const int OPEN_SECONDS = 30;

    /** How long a probe may hold its claim; longer than the client's worst case (two 2 s attempts). */
    public const int PROBE_SECONDS = 5;

    /** How long an open breaker is remembered while nobody probes it; after that it starts closed again. */
    public const int STATE_SECONDS = 3600;

    private readonly Repository $store;

    private readonly string $key;

    public function __construct(string $upstream = self::POKESEARCH)
    {
        $this->store = self::repository();
        $this->key = self::PREFIX.$upstream;
    }

    /**
     * The prefix of every breaker key as Redis stores it on redis-queue: the locks store's cache prefix plus
     * "breaker:" (the Redis connection adds its own prefix on top, as it does for every key).
     */
    public static function keyPrefix(): string
    {
        $store = self::repository()->getStore();
        if (! $store instanceof RedisStore) {
            throw new LogicException('The '.self::STORE.' cache store is not a Redis store.');
        }

        return $store->getPrefix().self::PREFIX;
    }

    /**
     * Runs $call unless the breaker is open; an UpstreamUnavailable from it counts as a failure, anything else
     * it returns or throws counts as an answer.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws UpstreamUnavailable when the breaker is open (no call is made) or the call failed
     */
    public function call(Closure $call): mixed
    {
        $now = self::now();
        $openUntil = $this->store->get("{$this->key}:open");
        if (! is_numeric($openUntil)) {
            return $this->closed($call);
        }

        $openUntil = (int) $openUntil;
        if ($now < $openUntil) {
            throw new UpstreamUnavailable($openUntil - $now);
        }

        if (! $this->store->add("{$this->key}:probe", $now, self::PROBE_SECONDS)) {
            throw new UpstreamUnavailable(1);
        }

        return $this->probe($call);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function closed(Closure $call): mixed
    {
        try {
            $result = $call();
        } catch (UpstreamUnavailable $e) {
            throw $this->failed($e);
        }

        $this->store->forget($this->key);
        $this->store->forget("{$this->key}:since");

        return $result;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function probe(Closure $call): mixed
    {
        try {
            $result = $call();
        } catch (UpstreamUnavailable $e) {
            throw $this->open($e);
        } catch (Throwable $e) {
            // Not a verdict on PokéSearch: let the next caller probe.
            $this->store->forget("{$this->key}:probe");

            throw $e;
        }

        foreach (['open', 'probe', 'since'] as $part) {
            $this->store->forget("{$this->key}:{$part}");
        }
        $this->store->forget($this->key);

        return $result;
    }

    /** Counts one failure in the window and opens the breaker on the fifth; returns what the caller throws. */
    private function failed(UpstreamUnavailable $failure): UpstreamUnavailable
    {
        $now = self::now();
        $since = $this->store->get("{$this->key}:since");
        if (! is_numeric($since) || (int) $since + self::WINDOW_SECONDS <= $now) {
            $this->store->put("{$this->key}:since", $now, self::WINDOW_SECONDS);
            $this->store->put($this->key, 0, self::WINDOW_SECONDS);
        }

        // add() (SET NX EX) gives a counter that Redis already expired a TTL again before it is incremented,
        // so the counter never outlives its window.
        $this->store->add($this->key, 0, self::WINDOW_SECONDS);
        $failures = $this->store->increment($this->key);

        return is_int($failures) && $failures >= self::THRESHOLD ? $this->open($failure) : $failure;
    }

    /** Opens the breaker for 30 s from now; the caller is told to wait that long. */
    private function open(UpstreamUnavailable $failure): UpstreamUnavailable
    {
        $this->store->put("{$this->key}:open", self::now() + self::OPEN_SECONDS, self::STATE_SECONDS);
        $this->store->forget("{$this->key}:probe");

        return new UpstreamUnavailable(self::OPEN_SECONDS, $failure->getPrevious() ?? $failure);
    }

    private static function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    private static function repository(): Repository
    {
        $store = Cache::store(self::STORE);
        if (! $store instanceof Repository) {
            throw new LogicException('Cache store '.self::STORE.' is not a cache repository.');
        }

        return $store;
    }
}
