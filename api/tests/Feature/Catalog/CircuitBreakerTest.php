<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\CircuitBreaker;
use App\Domain\Catalog\PokeSearchClient;
use App\Http\Problems\UpstreamUnavailable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// The request-path circuit breaker (plan T35, F-P1-024, R129): counters on the locks store (redis-queue) under
// breaker:pokesearch, every key under a prefix unique to the parallel-test token and the test. Upstream answers
// are faked from a status table and every request is counted; a 500 is used for a failure because the client
// never retries it, so one failed call is exactly one request.

const T35_BASE = 'http://192.0.2.10:8081';
const T35_CARD = ['data' => ['id' => 'base1-4', 'name' => 'Charizard'], 'seed' => ['ref' => null, 'seeded_at' => null]];
const T35_META = ['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 1, 'seed' => null, 'request_id' => 'upstream-meta'];

/**
 * One card read through the container-built client (the request path), returning the UpstreamUnavailable it
 * threw, or null when it answered.
 */
function t35Read(): ?UpstreamUnavailable
{
    try {
        app(PokeSearchClient::class)->card('base1-4');
    } catch (UpstreamUnavailable $e) {
        return $e;
    }

    return null;
}

function t35RetryAfter(?UpstreamUnavailable $e): ?string
{
    return $e?->getHeaders()['Retry-After'] ?? null;
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T35_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();
    $this->prefixes = [$this->prefix];

    // The status every card request answers with, read at the time of each request; $this->during, when set,
    // runs inside the next request (a caller arriving while that request is in flight).
    $this->status = 500;
    $this->during = null;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) {
        if ($this->during !== null) {
            $during = $this->during;
            $this->during = null;
            $during();
        }
        if (str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/meta')) {
            return Http::response(T35_META, $this->status === 200 ? 200 : $this->status);
        }

        return $this->status === 200 ? Http::response(T35_CARD) : Http::response(['error' => 'es down'], $this->status);
    });
});

afterEach(function (): void {
    ParallelTesting::resolveTokenUsing(null);
    foreach ($this->prefixes as $prefix) {
        CatalogCache::flush($prefix);
    }
});

it('(a) opens after five failures, so the sixth call makes no upstream request and says how long to wait', function (): void {
    $failures = [];
    for ($i = 0; $i < 5; $i++) {
        $failures[] = t35Read();
    }
    $sixth = t35Read();

    Http::assertSentCount(5);
    expect($failures)->each->toBeInstanceOf(UpstreamUnavailable::class)
        ->and($sixth)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and(t35RetryAfter($sixth))->toBe('30');
});

it('reports the remaining open seconds as Retry-After, never below 1', function (): void {
    for ($i = 0; $i < 5; $i++) {
        t35Read();
    }

    $this->travel(10)->seconds();
    $after10 = t35Read();
    $this->travel(19)->seconds();
    $after29 = t35Read();

    Http::assertSentCount(5);
    expect(t35RetryAfter($after10))->toBe('20')
        ->and(t35RetryAfter($after29))->toBe('1');
});

it('(b) keeps breaker:pokesearch on redis connection default (redis-queue) and never on cache', function (): void {
    t35Read();

    $key = $this->prefix.'breaker:pokesearch';
    expect(Redis::connection('default')->exists($key))->toBe(1)
        ->and(Redis::connection('cache')->exists($key))->toBe(0)
        ->and(Redis::connection('default')->ttl($key))->toBeGreaterThan(0)->toBeLessThanOrEqual(30)
        ->and(CatalogCache::keys('cache', $this->prefix))->toBe([]);
});

it('(c) allows exactly one half-open probe 31 s after opening; a failed probe re-opens for 30 s', function (): void {
    for ($i = 0; $i < 5; $i++) {
        t35Read();
    }
    $this->travel(31)->seconds();

    // While the probe is in flight a second caller is refused without a request of its own.
    $concurrent = 'not called';
    $this->during = function () use (&$concurrent): void {
        $concurrent = t35Read();
    };
    $probe = t35Read();
    $afterProbe = t35Read();

    Http::assertSentCount(6);
    expect($concurrent)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and($probe)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and(t35RetryAfter($afterProbe))->toBe('30');

    // Another 31 s: one more probe and no more.
    $this->travel(31)->seconds();
    t35Read();
    t35Read();
    Http::assertSentCount(7);
});

it('closes after a successful probe, and the closed breaker then needs five fresh failures to open', function (): void {
    for ($i = 0; $i < 5; $i++) {
        t35Read();
    }
    $this->travel(31)->seconds();
    $this->status = 200;

    $probe = t35Read();
    $next = t35Read();
    $this->status = 500;
    for ($i = 0; $i < 4; $i++) {
        t35Read();
    }
    $stillClosed = t35Read();
    $open = t35Read();

    Http::assertSentCount(5 + 2 + 4 + 1);
    expect($probe)->toBeNull()
        ->and($next)->toBeNull()
        ->and($stillClosed)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and(t35RetryAfter($open))->toBe('30');
});

it('clears the counter on a success while closed', function (): void {
    for ($i = 0; $i < 4; $i++) {
        t35Read();
    }
    $this->status = 200;
    $ok = t35Read();
    $this->status = 500;
    for ($i = 0; $i < 4; $i++) {
        t35Read();
    }
    $fifthSinceClear = t35Read();

    Http::assertSentCount(4 + 1 + 4 + 1);
    expect($ok)->toBeNull()
        ->and(t35RetryAfter($fifthSinceClear))->toBe('30');
});

it('counts only failures within 30 s of the first one', function (): void {
    for ($i = 0; $i < 4; $i++) {
        t35Read();
    }
    $this->travel(31)->seconds();
    $window = [];
    for ($i = 0; $i < 5; $i++) {
        $window[] = t35Read();
    }
    $open = t35Read();

    // The four old failures fell out of the window: the next five all reached PokéSearch and the fifth opened it.
    Http::assertSentCount(4 + 5);
    expect(array_map('t35RetryAfter', $window))->toBe(['5', '5', '5', '5', '30'])
        ->and(t35RetryAfter($open))->toBe('30');
});

it('(d) keeps the state of two parallel-test tokens apart', function (): void {
    ParallelTesting::resolveTokenUsing(fn (): string => '1');
    $first = CatalogCache::isolate();
    $this->prefixes[] = $first;
    for ($i = 0; $i < 5; $i++) {
        t35Read();
    }
    $openUnderFirst = t35Read();

    ParallelTesting::resolveTokenUsing(fn (): string => '2');
    $second = CatalogCache::isolate();
    $this->prefixes[] = $second;
    $this->status = 200;
    $closedUnderSecond = t35Read();

    Http::assertSentCount(6);
    expect($first)->toStartWith('pokeprices-test-1-')
        ->and($second)->toStartWith('pokeprices-test-2-')
        ->and(t35RetryAfter($openUnderFirst))->toBe('30')
        ->and($closedUnderSecond)->toBeNull()
        ->and(Redis::connection('default')->exists($first.'breaker:pokesearch'))->toBe(1)
        ->and(Redis::connection('default')->exists($second.'breaker:pokesearch'))->toBe(0);
});

it('serves the last-good copy as degraded while open, and a cold read gets 503 with the remaining seconds', function (): void {
    $this->status = 200;
    app(CatalogRepository::class)->card('base1-4');
    $this->status = 500;
    $this->travel(86401)->seconds();
    for ($i = 0; $i < 5; $i++) {
        t35Read();
    }
    $sent = count(Http::recorded());

    $degraded = app(CatalogRepository::class)->card('base1-4');
    $cold = null;
    try {
        app(CatalogRepository::class)->card('base1-5');
    } catch (UpstreamUnavailable $e) {
        $cold = $e;
    }

    Http::assertSentCount($sent);
    expect($degraded?->degraded)->toBeTrue()
        ->and($degraded?->data)->toBe(T35_CARD)
        ->and(t35RetryAfter($cold))->toBe('30');
});

it('keeps ThrottlesExceptionsWithRedis off the request path: only the VerifyCatalog job uses it', function (): void {
    $users = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        // The class reference (a use statement or a qualified name), not a mention in prose.
        if (str_ends_with((string) $file, '.php') && str_contains((string) file_get_contents((string) $file), 'Queue\\Middleware\\ThrottlesExceptions')) {
            $users[] = substr((string) $file, strlen(app_path()) + 1);
        }
    }
    sort($users);

    expect($users)->toBe(['Domain/Catalog/VerifyCatalog.php'])
        ->and(CircuitBreaker::keyPrefix())->toBe($this->prefix.'breaker:');
});
