<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// The batched card names read (plan P3-i1 T08, review F-P3-06, F-P3-07): one GET /api/v1/cards?ids= for every id
// that is absent from its own key family (stale ids ride along), per-card validation, negative markers, last-good
// copies and the degraded fallbacks. Every upstream call is faked by path and recorded; every key lives under a
// prefix unique to the parallel-test token and the test (Tests\Support\CatalogCache).

const T08N_BASE = 'http://192.0.2.10:8081';
const T08N_REF = 'cccccccccccccccccccccccccccccccccccccccc';

function t08nMeta(): array
{
    return [
        'version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3,
        'seed' => ['ref' => T08N_REF, 'seeded_at' => '2026-10-11T20:05:45Z'],
        'request_id' => 'upstream-meta',
    ];
}

/** A well-formed batch card as PokéSearch sends it (more fields than the names read keeps). */
function t08nCard(string $id, string $suffix = ''): array
{
    return ['id' => $id, 'name' => "Name of {$id}{$suffix}", 'supertype' => 'Pokémon', 'number' => '7', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'release_date' => '2023-03-31'];
}

/** The names the read returns for a well-formed card. */
function t08nName(string $id, string $suffix = ''): array
{
    return ['name' => "Name of {$id}{$suffix}", 'set_name' => "Set of {$id}", 'number' => '7'];
}

/** The ids one recorded batch request asked for, in the order sent. */
function t08nIds(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return explode(',', (string) ($query['ids'] ?? ''));
}

/** The recorded batch requests' id lists. */
function t08nBatches(array $calls): array
{
    return array_values(array_map(
        fn (string $url): array => t08nIds($url),
        array_filter($calls, fn (string $url): bool => parse_url($url, PHP_URL_PATH) === '/api/v1/cards'),
    ));
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T08N_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->prefix = CatalogCache::isolate();

    // id => card (or any malformed value); ids not listed are answered in missing. 'batch' may be overridden with
    // [status, body] or 'timeout' to fail the whole batch.
    $this->catalog = [];
    $this->batch = null;
    $this->calls = [];
    Http::preventStrayRequests();
    $this->fake = function (ClientRequest $request) {
        $this->calls[] = $request->url();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/meta') {
            return Http::response(t08nMeta());
        }
        if ($path !== '/api/v1/cards') {
            return Http::response(['error' => 'unrouted'], 599);
        }
        if ($this->batch === 'timeout') {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }
        if (is_array($this->batch)) {
            return Http::response($this->batch[1], $this->batch[0]);
        }
        $ids = t08nIds($request->url());
        $data = [];
        $missing = [];
        foreach ($ids as $id) {
            array_key_exists($id, $this->catalog) ? $data[] = $this->catalog[$id] : $missing[] = $id;
        }

        return Http::response(['data' => $data, 'missing' => $missing, 'seed' => ['ref' => T08N_REF, 'seeded_at' => '2026-10-11T20:05:45Z']]);
    };
    Http::fake($this->fake);
});

/** Re-caches the seed ref (its own window is 300 s) outside the recorded calls, then restores the test's fake. */
function t08nPrime(object $test): void
{
    CatalogCache::primeSeedRef(T08N_BASE, T08N_REF);
    Http::fake($test->fake);
}

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('reads every distinct valid id with one batch after the seed ref, and never sends an invalid id', function (): void {
    $this->catalog = ['sv1-1' => t08nCard('sv1-1'), 'ex10-?' => t08nCard('ex10-?')];

    $answer = app(CatalogRepository::class)->cardNames(['sv1-1', 'bad id', 'ex10-?', 'sv1-1', '']);

    expect($answer)->toBe([
        'cards' => ['sv1-1' => t08nName('sv1-1'), 'bad id' => null, 'ex10-?' => t08nName('ex10-?'), '' => null],
        'degraded' => false,
    ])
        ->and(array_map(fn (string $url): ?string => parse_url($url, PHP_URL_PATH), $this->calls))->toBe(['/api/meta', '/api/v1/cards'])
        ->and(t08nBatches($this->calls))->toBe([['ex10-?', 'sv1-1']]);
});

it('answers an empty or all-invalid list without calling PokéSearch', function (): void {
    $repository = app(CatalogRepository::class);

    expect($repository->cardNames([]))->toBe(['cards' => [], 'degraded' => false])
        ->and($repository->cardNames(['bad id', '-x']))->toBe(['cards' => ['bad id' => null, '-x' => null], 'degraded' => false])
        ->and($this->calls)->toBe([]);
});

it('keeps each name under its own cardname key and a 7-day last-good copy, never under the card page keys', function (): void {
    $this->catalog = ['sv1-1' => t08nCard('sv1-1')];

    app(CatalogRepository::class)->cardNames(['sv1-1']);

    $fresh = $this->prefix.'catalog:v1:cardname:'.T08N_REF.':sv1-1';
    $lastGood = $this->prefix.'catalog:v1:lastgood:cardname:sv1-1';
    $cacheKeys = CatalogCache::keys('cache', $this->prefix);
    expect($cacheKeys)->toContain($fresh, $lastGood)
        ->and(array_filter($cacheKeys, fn (string $k): bool => str_contains($k, 'catalog:v1:card:') || str_contains($k, 'catalog:v1:lastgood:card:')))->toBe([])
        ->and(Redis::connection('cache')->ttl($lastGood))->toBeGreaterThan(7 * 86400 - 10)->toBeLessThanOrEqual(7 * 86400)
        ->and(Redis::connection('cache')->ttl($fresh))->toBeGreaterThan(86400 - 10)->toBeLessThanOrEqual(86400);
});

it('serves fresh and stale-but-live names from the cache without a request', function (int $age): void {
    $this->catalog = ['sv1-1' => t08nCard('sv1-1'), 'sv1-2' => t08nCard('sv1-2')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['sv1-1', 'sv1-2']);
    $this->catalog = ['sv1-1' => t08nCard('sv1-1', ' renamed'), 'sv1-2' => t08nCard('sv1-2', ' renamed')];
    $before = count($this->calls);

    $this->travel($age)->seconds();
    t08nPrime($this);
    $answer = $repository->cardNames(['sv1-1', 'sv1-2']);

    expect(count($this->calls))->toBe($before)
        ->and($answer)->toBe(['cards' => ['sv1-1' => t08nName('sv1-1'), 'sv1-2' => t08nName('sv1-2')], 'degraded' => false]);
})->with([
    'fresh (3,599 s)' => [3599],
    'stale-but-live (3,600 s)' => [3600],
    'stale-but-live (86,399 s)' => [86399],
]);

it('asks again for a name past its 86,400 s hard window', function (): void {
    $this->catalog = ['sv1-1' => t08nCard('sv1-1')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['sv1-1']);
    $this->catalog = ['sv1-1' => t08nCard('sv1-1', ' renamed')];

    $this->travel(86400)->seconds();
    $answer = $repository->cardNames(['sv1-1']);

    expect(t08nBatches($this->calls))->toBe([['sv1-1'], ['sv1-1']])
        ->and($answer['cards'])->toBe(['sv1-1' => t08nName('sv1-1', ' renamed')]);
});

it('sends one batch of the absent id plus every stale id and no fresh id', function (): void {
    $this->catalog = ['aa-1' => t08nCard('aa-1'), 'aa-2' => t08nCard('aa-2'), 'aa-3' => t08nCard('aa-3'), 'aa-4' => t08nCard('aa-4')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['aa-2', 'aa-3']);
    $this->travel(4000)->seconds();
    $repository->cardNames(['aa-4']);
    $this->catalog = array_map(fn (array $card): array => ['name' => $card['name'].' new'] + $card, $this->catalog);
    $before = count(t08nBatches($this->calls));

    $answer = $repository->cardNames(['aa-4', 'aa-3', 'aa-1', 'aa-2']);

    $batches = t08nBatches($this->calls);
    expect(count($batches))->toBe($before + 1)
        ->and(end($batches))->toBe(['aa-1', 'aa-2', 'aa-3'])
        ->and($answer)->toBe(['cards' => [
            'aa-4' => t08nName('aa-4'),
            'aa-3' => t08nName('aa-3', ' new'),
            'aa-1' => t08nName('aa-1', ' new'),
            'aa-2' => t08nName('aa-2', ' new'),
        ], 'degraded' => false]);
});

it('maps a missing id to null under a 60 s negative marker and asks again only after it', function (): void {
    $repository = app(CatalogRepository::class);

    $first = $repository->cardNames(['zz-404']);
    $this->travel(59)->seconds();
    $second = $repository->cardNames(['zz-404']);
    $this->travel(1)->seconds();
    $third = $repository->cardNames(['zz-404']);

    expect($first)->toBe(['cards' => ['zz-404' => null], 'degraded' => false])
        ->and($second)->toBe($first)
        ->and($third)->toBe($first)
        ->and(t08nBatches($this->calls))->toBe([['zz-404'], ['zz-404']])
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:cardname:'.T08N_REF.':zz-404:none'))->toBe(1);
});

it('nulls only the malformed card, logs its id and stays undegraded', function (): void {
    Log::spy();
    $this->catalog = ['aa-1' => t08nCard('aa-1'), 'aa-2' => array_diff_key(t08nCard('aa-2'), ['name' => true]), 'aa-3' => t08nCard('aa-3')];
    $repository = app(CatalogRepository::class);

    $answer = $repository->cardNames(['aa-1', 'aa-2', 'aa-3']);

    expect($answer)->toBe(['cards' => ['aa-1' => t08nName('aa-1'), 'aa-2' => null, 'aa-3' => t08nName('aa-3')], 'degraded' => false])
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:cardname:'.T08N_REF.':aa-2'))->toBe(0)
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:lastgood:cardname:aa-2'))->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['card_id' => 'aa-2'])->once();
});

it('rejects a card whose field is not a string', function (string $field, mixed $value): void {
    $this->catalog = ['aa-1' => [$field => $value] + t08nCard('aa-1'), 'aa-3' => t08nCard('aa-3')];

    expect(app(CatalogRepository::class)->cardNames(['aa-1', 'aa-3']))
        ->toBe(['cards' => ['aa-1' => null, 'aa-3' => t08nName('aa-3')], 'degraded' => false]);
})->with([
    'name null' => ['name', null],
    'number int' => ['number', 7],
    'set_name list' => ['set_name', ['Set']],
]);

it('falls back to last-good copies, else null, when the batch fails', function (mixed $batch): void {
    $this->catalog = ['aa-1' => t08nCard('aa-1')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['aa-1']);
    // Past the hard window: aa-1 is absent from its fresh key, only its last-good copy is left.
    $this->travel(86400)->seconds();
    $this->batch = $batch;

    $answer = $repository->cardNames(['aa-1', 'aa-2']);

    expect($answer)->toBe(['cards' => ['aa-1' => t08nName('aa-1'), 'aa-2' => null], 'degraded' => true]);
})->with([
    'a 503' => [[503, ['error' => 'down']]],
    'a timeout' => ['timeout'],
    'data not a list' => [[200, ['data' => ['id' => 'aa-1'], 'missing' => []]]],
    'missing not a list' => [[200, ['data' => [], 'missing' => 'aa-1']]],
]);

it('keeps a stale-but-live name when the batch it joined fails', function (): void {
    $this->catalog = ['aa-1' => t08nCard('aa-1')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['aa-1']);
    Redis::connection('cache')->del($this->prefix.'catalog:v1:lastgood:cardname:aa-1');
    $this->travel(4000)->seconds();
    t08nPrime($this);
    $this->batch = [503, ['error' => 'down']];

    expect($repository->cardNames(['aa-1', 'aa-2']))->toBe(['cards' => ['aa-1' => t08nName('aa-1'), 'aa-2' => null], 'degraded' => true]);
});

it('is degraded with last-good names when the seed ref cannot be read at all', function (): void {
    Cache::store('redis')->put('catalog:v1:lastgood:cardname:aa-1', ['data' => t08nName('aa-1'), 'fetched_at' => now()->getTimestamp()], 600);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(['error' => 'down'], 503));

    expect(app(CatalogRepository::class)->cardNames(['aa-1', 'aa-2']))
        ->toBe(['cards' => ['aa-1' => t08nName('aa-1'), 'aa-2' => null], 'degraded' => true]);
});

it('is degraded when another filler holds the fill lock past the wait', function (): void {
    $this->catalog = ['aa-1' => t08nCard('aa-1')];
    $repository = app(CatalogRepository::class);
    $repository->seedRef();
    $lock = Cache::store('redis')->lock('catalog:v1:fill:cardnames:'.sha1('aa-1,aa-2'), 10);
    expect($lock->get())->toBeTrue();

    try {
        $answer = $repository->cardNames(['aa-2', 'aa-1']);
    } finally {
        $lock->release();
    }

    expect($answer)->toBe(['cards' => ['aa-2' => null, 'aa-1' => null], 'degraded' => true])
        ->and(t08nBatches($this->calls))->toBe([]);
});
