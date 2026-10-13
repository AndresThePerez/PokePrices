<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// GET /v1/movers names its cards through the batched catalog read (plan P3-i1 T08, D10, D11, R157, review F-P3-06,
// F-P3-07). Movers rows are inserted straight into card_variants and price_movers, PokéSearch is faked by path and
// every request it sees is recorded; every catalog key lives under a prefix unique to the test. Every /v1 response
// is also validated against openapi/api.json by Tests\TestCase (C3), 200 (degraded or not) and 304 alike.

const T08M_BASE = 'http://192.0.2.10:8081';
const T08M_REF = 'dddddddddddddddddddddddddddddddddddddddd';

/** One price_movers row of 2026-10-11 for "<card>:<finish>:-:-" (its card_variants row added when new). */
function t08mMover(string $direction, int $rank, string $card, string $finish = 'holo', ?string $variantCard = null): void
{
    $key = ($variantCard ?? $card).":{$finish}:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $variantCard ?? $card, 'finish' => $finish,
        'edition' => '-', 'treatment' => '-', 'catalog_status' => 'unverified']);
    $up = $direction === 'up';
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => $direction, 'rank' => $rank,
        'variant_key' => $key, 'card_id' => $card, 'series_epoch' => 1, 'close' => $up ? '11.00' : '9.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => $up ? '1.00' : '-1.00', 'change_pct' => $up ? '10.00' : '-10.00']);
}

function t08mCard(string $id, string $suffix = ''): array
{
    return ['id' => $id, 'name' => "Name of {$id}{$suffix}", 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'release_date' => '1999-01-09'];
}

function t08mName(string $id, string $suffix = ''): array
{
    return ['name' => "Name of {$id}{$suffix}", 'set_name' => "Set of {$id}", 'number' => '4'];
}

/** The ids of every recorded batch request, in order. */
function t08mBatches(): array
{
    return Http::recorded(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards')
        ->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return explode(',', (string) $query['ids']);
        })->values()->all();
}

/** Re-caches the seed ref (its window is 300 s), then starts a fresh recording with the test's fake. */
function t08mPrime(object $test): void
{
    CatalogCache::primeSeedRef(T08M_BASE, T08M_REF);
    Http::fake($test->fake);
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T08M_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    Queue::fake([VerifyCatalog::class]);
    $this->prefix = CatalogCache::isolate();

    // id => card (or a malformed value); unlisted ids are answered in missing. $this->batch fails the batch.
    $this->catalog = [];
    $this->batch = null;
    $this->fake = function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3,
                'seed' => ['ref' => T08M_REF, 'seeded_at' => '2026-10-11T20:05:45Z'], 'request_id' => 'upstream-meta']);
        }
        if ($path !== '/api/v1/cards') {
            return Http::response(['error' => 'unrouted'], 599);
        }
        if ($this->batch === 'timeout') {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }
        if (is_array($this->batch)) {
            return Http::response($this->batch[1], $this->batch[0]);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = [];
        $missing = [];
        foreach (explode(',', (string) $query['ids']) as $id) {
            array_key_exists($id, $this->catalog) ? $data[] = $this->catalog[$id] : $missing[] = $id;
        }

        return Http::response(['data' => $data, 'missing' => $missing, 'seed' => ['ref' => T08M_REF, 'seeded_at' => '2026-10-11T20:05:45Z']]);
    };
    Http::preventStrayRequests();
    Http::fake($this->fake);
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('names each row after card_id with one batch of the distinct valid ids, after the seed ref, on a cold cache', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1'), 'ex10-?' => t08mCard('ex10-?')];
    t08mMover('up', 1, 'aa-1');
    t08mMover('up', 2, 'aa-1', 'reverse');
    t08mMover('up', 3, 'bad id', 'holo', 'aa-9');
    t08mMover('up', 4, 'ex10-?');
    t08mMover('up', 5, 'zz-404');

    $response = $this->getJson('/v1/movers?direction=up');

    $response->assertOk();
    expect(array_keys($response->json('data.0')))->toBe(['rank', 'card_id', 'card', 'variant_key', 'finish', 'edition', 'treatment',
        'close', 'previous_close', 'previous_day', 'change', 'change_pct', 'series_epoch'])
        ->and(array_map(fn (array $row): array => [$row['card_id'], $row['card']], $response->json('data')))->toBe([
            ['aa-1', t08mName('aa-1')],
            ['aa-1', t08mName('aa-1')],
            ['bad id', null],
            ['ex10-?', t08mName('ex10-?')],
            ['zz-404', null],
        ])
        ->and($response->json('meta.degraded'))->toBeFalse()
        ->and($response->headers->get('ETag'))->not->toBeNull()
        ->and(Http::recorded()->map(fn (array $pair): ?string => parse_url($pair[0]->url(), PHP_URL_PATH))->all())->toBe(['/api/meta', '/api/v1/cards'])
        ->and(t08mBatches())->toBe([['aa-1', 'ex10-?', 'zz-404']]);
    Http::assertSentCount(2);
});

it('answers meta.degraded false and makes no PokéSearch request when there are no movers', function (): void {
    $response = $this->getJson('/v1/movers');

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.degraded'))->toBeFalse();
    Http::assertSentCount(0);
});

it('makes no request on a warm-fresh read and none on a warm-stale read', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1'), 'aa-2' => t08mCard('aa-2')];
    t08mMover('down', 1, 'aa-1');
    t08mMover('down', 2, 'aa-2');
    $cold = $this->getJson('/v1/movers?direction=down')->json();

    t08mPrime($this);
    $fresh = $this->getJson('/v1/movers?direction=down');
    Http::assertSentCount(0);

    $this->catalog = ['aa-1' => t08mCard('aa-1', ' new'), 'aa-2' => t08mCard('aa-2', ' new')];
    $this->travel(3600)->seconds();
    t08mPrime($this);
    $stale = $this->getJson('/v1/movers?direction=down');
    Http::assertSentCount(0);

    expect($fresh->json())->toBe($cold)
        ->and($stale->json())->toBe($cold)
        ->and(array_column($stale->json('data'), 'card'))->toBe([t08mName('aa-1'), t08mName('aa-2')]);
});

it('sends exactly one batch of the absent id plus every stale id, never a fresh one', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1'), 'aa-2' => t08mCard('aa-2'), 'aa-3' => t08mCard('aa-3'), 'aa-4' => t08mCard('aa-4')];
    t08mMover('up', 1, 'aa-2');
    t08mMover('up', 2, 'aa-3');
    $this->getJson('/v1/movers');
    $this->travel(4000)->seconds();
    DB::table('price_movers')->delete();
    t08mMover('up', 1, 'aa-4');
    t08mPrime($this);
    $this->getJson('/v1/movers');
    DB::table('price_movers')->delete();
    t08mMover('up', 1, 'aa-4');
    t08mMover('up', 2, 'aa-3');
    t08mMover('up', 3, 'aa-1');
    t08mMover('up', 4, 'aa-2');
    $this->catalog = array_map(fn (array $card): array => ['name' => $card['name'].' new'] + $card, $this->catalog);
    t08mPrime($this);

    $response = $this->getJson('/v1/movers');

    Http::assertSentCount(1);
    expect(t08mBatches())->toBe([['aa-1', 'aa-2', 'aa-3']])
        ->and(array_column($response->json('data'), 'card'))->toBe([
            t08mName('aa-4'), t08mName('aa-3', ' new'), t08mName('aa-1', ' new'), t08mName('aa-2', ' new'),
        ])
        ->and($response->json('meta.degraded'))->toBeFalse();
});

it('answers card null for a missing id, undegraded, and asks again only after 60 s', function (): void {
    t08mMover('up', 1, 'zz-404');

    $first = $this->getJson('/v1/movers');
    t08mPrime($this);
    $this->travel(59)->seconds();
    $this->getJson('/v1/movers');
    Http::assertSentCount(0);
    $this->travel(1)->seconds();
    $this->getJson('/v1/movers');

    expect($first->json('data.0.card'))->toBeNull()
        ->and($first->json('meta.degraded'))->toBeFalse()
        ->and($first->headers->get('ETag'))->not->toBeNull()
        ->and(t08mBatches())->toBe([['zz-404']])
        ->and(CatalogCache::keys('cache', $this->prefix))->toContain($this->prefix.'catalog:v1:cardname:'.T08M_REF.':zz-404:none');
});

it('names from last-good copies while PokéSearch fails: degraded, no-store, no ETag, never 304', function (mixed $batch): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1')];
    t08mMover('up', 1, 'aa-1');
    t08mMover('up', 2, 'aa-2');
    $etag = (string) $this->getJson('/v1/movers')->headers->get('ETag');
    $this->travel(86400)->seconds();
    t08mPrime($this);
    $this->batch = $batch;

    $response = $this->getJson('/v1/movers', ['If-None-Match' => $etag]);

    expect($response->getStatusCode())->toBe(200)
        ->and(array_column($response->json('data'), 'card'))->toBe([t08mName('aa-1'), null])
        ->and($response->json('meta.degraded'))->toBeTrue()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('public')
        ->and($response->headers->has('ETag'))->toBeFalse();
})->with([
    'a 503' => [[503, ['error' => 'down']]],
    'a refused connection' => ['timeout'],
    'data not a list' => [[200, ['data' => 'aa-1', 'missing' => []]]],
]);

it('still answers 200 with null cards when PokéSearch fails and nothing was ever cached', function (): void {
    t08mMover('up', 1, 'aa-1');
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(['error' => 'down'], 503));

    $response = $this->getJson('/v1/movers');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.0.card'))->toBeNull()
        ->and($response->json('meta.degraded'))->toBeTrue()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')->not->toContain('public')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('nulls only the card that lacks a name: the other two named, undegraded, with an ETag', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1'), 'aa-2' => array_diff_key(t08mCard('aa-2'), ['name' => true]), 'aa-3' => t08mCard('aa-3')];
    t08mMover('up', 1, 'aa-1');
    t08mMover('up', 2, 'aa-2');
    t08mMover('up', 3, 'aa-3');

    $response = $this->getJson('/v1/movers');

    expect(array_column($response->json('data'), 'card'))->toBe([t08mName('aa-1'), null, t08mName('aa-3')])
        ->and($response->json('meta.degraded'))->toBeFalse()
        ->and($response->headers->get('ETag'))->not->toBeNull();
});

it('never writes the card page cache keys', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1')];
    t08mMover('up', 1, 'aa-1');

    $this->getJson('/v1/movers')->assertOk();

    $keys = CatalogCache::keys('cache', $this->prefix);
    expect($keys)->toContain($this->prefix.'catalog:v1:cardname:'.T08M_REF.':aa-1', $this->prefix.'catalog:v1:lastgood:cardname:aa-1')
        ->and(array_values(array_filter($keys, fn (string $k): bool => preg_match('/catalog:v1:(lastgood:)?card:/', $k) === 1)))->toBe([]);
});

it('asks for a card shared by two up and two down rows once', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1')];
    t08mMover('up', 1, 'aa-1');
    t08mMover('up', 2, 'aa-1', 'reverse');
    t08mMover('down', 1, 'aa-1', 'reverse');
    t08mMover('down', 2, 'aa-1');

    $up = $this->getJson('/v1/movers?direction=up');
    $down = $this->getJson('/v1/movers?direction=down');

    expect(t08mBatches())->toBe([['aa-1']])
        ->and(array_column($up->json('data'), 'card'))->toBe([t08mName('aa-1'), t08mName('aa-1')])
        ->and(array_column($down->json('data'), 'card'))->toBe([t08mName('aa-1'), t08mName('aa-1')]);
});

it('answers 304 to a matching If-None-Match while undegraded', function (): void {
    $this->catalog = ['aa-1' => t08mCard('aa-1')];
    t08mMover('up', 1, 'aa-1');

    $first = $this->getJson('/v1/movers');
    $matched = $this->getJson('/v1/movers', ['If-None-Match' => (string) $first->headers->get('ETag')]);

    expect($first->getStatusCode())->toBe(200)
        ->and($first->json('data.0.card'))->toBe(t08mName('aa-1'))
        ->and($first->json('meta.degraded'))->toBeFalse()
        ->and($matched->getStatusCode())->toBe(304)
        ->and($matched->headers->get('ETag'))->toBe($first->headers->get('ETag'));
    Http::assertSentCount(2);
});

it('documents the named rows and a required boolean meta.degraded', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/movers']['get'];
    $meta = $operation['responses'][200]['content']['application/json']['schema']['properties']['meta'];
    $mover = $spec['components']['schemas']['MoverResource'];

    expect($meta['properties']['degraded'])->toBe(['type' => 'boolean'])
        ->and($meta['required'])->toContain('degraded')
        ->and($mover['required'])->toContain('card')
        ->and(array_slice(array_keys($mover['properties']), 0, 3))->toBe(['rank', 'card_id', 'card'])
        ->and($mover['properties']['card'])->toBe([
            'type' => ['object', 'null'],
            'properties' => ['name' => ['type' => 'string'], 'set_name' => ['type' => 'string'], 'number' => ['type' => 'string']],
            'required' => ['name', 'set_name', 'number'],
            'additionalProperties' => false,
        ])
        ->and(array_keys($operation['responses']))->toBe([200, 304, 422, 429])
        ->and($operation['description'])->toContain('Each row names its card from the PokéSearch catalog (card is null when PokéSearch does not have the id); while PokéSearch is unavailable, names come from the last good copy or are null, and meta.degraded is true.')
        ->and($operation['description'])->not->toContain('Cards are named by card_id only.');
});
