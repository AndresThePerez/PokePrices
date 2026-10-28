<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Every Cache-Control value the PokéPrices API sends, in one place, and the request attribute a
 * controller sets when its answer is a degraded last-good copy.
 *
 * NO_STORE is the readiness and freshness answers and every degraded public read; ACCOUNT is every account
 * answer (NoStore); SEARCH, CARD and HISTORY are the fresh 200s of conditional-get's three policies.
 */
final class CachePolicy
{
    public const string NO_STORE = 'no-store';

    public const string ACCOUNT = 'no-store, private';

    public const string SEARCH = 'public, max-age=60, s-maxage=300';

    public const string CARD = 'public, max-age=60, stale-while-revalidate=300';

    public const string HISTORY = 'public, max-age=300, stale-while-revalidate=600';

    /**
     * The request attribute that marks an answer as degraded (served from a last-good copy while PokéSearch is
     * unavailable). conditional-get reads it instead of decoding the body: true means no-store and no ETag.
     */
    public const string DEGRADED = 'pokeprices.degraded';
}
