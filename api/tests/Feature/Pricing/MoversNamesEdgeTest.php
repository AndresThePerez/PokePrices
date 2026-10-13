<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\VerifyCatalog;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// Edge cases of the card names on GET /v1/movers (plan P3-i1 T08, D10, D11, R157, R204): hostile names, ids PHP would
// turn into integer keys, the 2-query pin under a crowded board, the degraded/healthy flip, the shared circuit breaker
// and the card page's cache. PokéSearch is faked by path; every catalog key lives under a prefix of the test's own.

const T08X_BASE = 'http://192.0.2.10:8081';
const T08X_REF = 'ffffffffffffffffffffffffffffffffffffffff';

function t08xMover(string $direction, int $rank, string $card, string $finish = 'holo'): void
{
    $key = "{$card}:{$finish}:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => $finish,
        'edition' => '-', 'treatment' => '-', 'catalog_status' => 'unverified']);
    $up = $direction === 'up';
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => $direction, 'rank' => $rank,
        'variant_key' => $key, 'card_id' => $card, 'series_epoch' => 1, 'close' => $up ? '11.00' : '9.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => $up ? '1.00' : '-1.00', 'change_pct' => $up ? '10.00' : '-10.00']);
}

function t08xCard(string $id, string $suffix = ''): array
{
    return ['id' => $id, 'name' => "Name of {$id}{$suffix}", 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'release_date' => '1999-01-09'];
}

function t08xName(string $id, string $suffix = ''): array
{
    return ['name' => "Name of {$id}{$suffix}", 'set_name' => "Set of {$id}", 'number' => '4'];
}

/** The id lists of every recorded batch request, in order. */
function t08xBatches(): array
{
    return Http::recorded(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards')
        ->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return explode(',', (string) $query['ids']);
        })->values()->all();
}

/** Re-caches the seed ref (its window is 300 s), then starts a fresh recording with the test's fake. */
function t08xPrime(object $test): void
{
    CatalogCache::primeSeedRef(T08X_BASE, T08X_REF);
    Http::fake($test->fake);
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T08X_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    Queue::fake([VerifyCatalog::class]);
    $this->prefix = CatalogCache::isolate();

    $this->catalog = [];
    $this->batch = null;
    $this->fake = function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => T08X_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }
        if ($path === '/api/v1/cards/aa-1') {
            return Http::response(['data' => ['id' => 'aa-1', 'name' => 'Card page name', 'supertype' => 'Pokémon', 'number' => '1'], 'seed' => $seed]);
        }
        if ($path !== '/api/v1/cards') {
            return Http::response(['error' => 'unrouted'], 599);
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

        return Http::response(['data' => $data, 'missing' => $missing, 'seed' => $seed]);
    };
    Http::preventStrayRequests();
    Http::fake($this->fake);
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('serves a hostile name byte for byte as JSON, cold and from the cache', function (): void {
    $names = [
        'aa-1' => ['<script>alert(1)</script>&amp;"\'', '</script><img src=x onerror=alert(1)>'],
        'aa-2' => [str_repeat('Ж', 10000), ' 007 '],
        'aa-3' => ["Nul\u{0000}Tab\tNL\nQuote\"Back\\Emoji\u{1F600}Sep\u{2028}", ''],
    ];
    $rank = 0;
    foreach ($names as $id => [$name, $number]) {
        $this->catalog[$id] = ['name' => $name, 'number' => $number, 'set_name' => $name] + t08xCard($id);
        t08xMover('up', ++$rank, $id);
    }
    $expected = array_values(array_map(fn (array $pair): array => ['name' => $pair[0], 'set_name' => $pair[0], 'number' => $pair[1]], $names));

    $cold = $this->getJson('/v1/movers');
    t08xPrime($this);
    $warm = $this->getJson('/v1/movers');

    expect($cold->headers->get('Content-Type'))->toContain('application/json')
        ->and(array_column($cold->json('data'), 'card'))->toBe($expected)
        ->and(array_column($warm->json('data'), 'card'))->toBe($expected)
        ->and($warm->getContent())->toBe($cold->getContent());
    Http::assertSentCount(0);
});

it('names a card whose id PHP would turn into an integer array key', function (): void {
    $this->catalog = ['12345' => t08xCard('12345'), '0' => t08xCard('0'), 'aa-1' => t08xCard('aa-1')];
    t08xMover('up', 1, '12345');
    t08xMover('up', 2, '0');
    t08xMover('up', 3, 'aa-1');
    t08xMover('up', 4, '007');

    $cold = $this->getJson('/v1/movers');
    t08xPrime($this);
    $warm = $this->getJson('/v1/movers');

    expect($cold->getStatusCode())->toBe(200)
        ->and(array_column($cold->json('data'), 'card'))->toBe([t08xName('12345'), t08xName('0'), t08xName('aa-1'), null])
        ->and($cold->json('meta.degraded'))->toBeFalse()
        ->and($warm->json('data'))->toBe($cold->json('data'));
    Http::assertSentCount(0);
});

it('reads a crowded board with 2 queries and one batch of the distinct ids shared by up and down rows', function (): void {
    for ($i = 1; $i <= 50; $i++) {
        $card = 'c'.(($i - 1) % 25).'-1';
        $this->catalog[$card] = t08xCard($card);
        t08xMover('up', $i, $card, $i <= 25 ? 'holo' : 'reverse');
        t08xMover('down', $i, $card, $i <= 25 ? 'reverse' : 'holo');
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $up = $this->getJson('/v1/movers?direction=up&limit=50');
    $upQueries = count(DB::getQueryLog());
    DB::flushQueryLog();
    $down = $this->getJson('/v1/movers?direction=down&limit=50');
    $downQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $distinct = array_unique(array_column($up->json('data'), 'card_id'));
    expect($up->json('data'))->toHaveCount(50)
        ->and($down->json('data'))->toHaveCount(50)
        ->and($upQueries)->toBe(2)
        ->and($downQueries)->toBe(2)
        ->and(count(t08xBatches()))->toBe(1)
        ->and(count(t08xBatches()[0]))->toBe(25)
        ->and(t08xBatches()[0])->toEqualCanonicalizing($distinct)
        ->and(array_filter($down->json('data'), fn (array $row): bool => $row['card'] === null))->toBe([]);
});

it('answers the same ETag after a degraded answer: no-store while PokéSearch is down, cacheable and 304-able once it is back', function (): void {
    $this->catalog = ['aa-1' => t08xCard('aa-1')];
    t08xMover('up', 1, 'aa-1');

    $this->batch = [503, ['error' => 'down']];
    $down = $this->getJson('/v1/movers');
    $this->batch = null;
    $healthy = $this->getJson('/v1/movers');
    $etag = (string) $healthy->headers->get('ETag');
    $notModified = $this->getJson('/v1/movers', ['If-None-Match' => $etag]);
    $this->travel(86400)->seconds();
    t08xPrime($this);
    $this->batch = [503, ['error' => 'down']];
    $degradedAgain = $this->getJson('/v1/movers', ['If-None-Match' => $etag]);
    $this->batch = null;
    $back = $this->getJson('/v1/movers', ['If-None-Match' => $etag]);

    expect($down->json('data.0.card'))->toBeNull()
        ->and($down->json('meta.degraded'))->toBeTrue()
        ->and($down->headers->has('ETag'))->toBeFalse()
        ->and((string) $down->headers->get('Cache-Control'))->toContain('no-store')
        ->and($healthy->json('data.0.card'))->toBe(t08xName('aa-1'))
        ->and($healthy->json('meta.degraded'))->toBeFalse()
        ->and($etag)->not->toBe('')
        ->and((string) $healthy->headers->get('Cache-Control'))->toContain('public')
        ->and($notModified->getStatusCode())->toBe(304)
        ->and($degradedAgain->getStatusCode())->toBe(200)
        ->and($degradedAgain->json('meta.degraded'))->toBeTrue()
        ->and($degradedAgain->headers->has('ETag'))->toBeFalse()
        ->and((string) $degradedAgain->headers->get('Cache-Control'))->toContain('no-store')
        ->and($back->getStatusCode())->toBe(304)
        ->and($back->headers->get('ETag'))->toBe($etag);
});

it('shares the circuit breaker with the card page: five failing movers reads open it, then names degrade without a request', function (): void {
    $this->catalog = ['aa-1' => t08xCard('aa-1')];
    t08xMover('up', 1, 'aa-1');
    $this->getJson('/v1/movers')->assertOk();
    $this->travel(86400)->seconds();
    t08xPrime($this);
    $this->batch = [503, ['error' => 'down']];

    for ($i = 0; $i < 5; $i++) {
        expect($this->getJson('/v1/movers')->json('meta.degraded'))->toBeTrue();
    }
    $sent = count(t08xBatches());
    $open = $this->getJson('/v1/movers');
    $cardPage = $this->getJson('/v1/cards/aa-1');

    expect($sent)->toBeGreaterThanOrEqual(5)
        ->and(count(t08xBatches()))->toBe($sent)
        ->and($open->getStatusCode())->toBe(200)
        ->and($open->json('meta.degraded'))->toBeTrue()
        ->and($open->json('data.0.card'))->toBe(t08xName('aa-1'))
        ->and($open->headers->has('ETag'))->toBeFalse()
        ->and($cardPage->getStatusCode())->toBe(503);
    Http::assertNotSent(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards/aa-1');
});

it('neither reads nor poisons the card page cache: a cached card page name does not name a mover, and stays intact', function (): void {
    $this->catalog = ['aa-1' => t08xCard('aa-1')];
    t08xMover('up', 1, 'aa-1');
    $page = app(CatalogRepository::class)->card('aa-1');
    $cardKeys = array_values(array_filter(CatalogCache::keys('cache', $this->prefix), fn (string $k): bool => preg_match('/catalog:v1:(lastgood:)?card:/', $k) === 1));
    $before = array_map(fn (string $key): mixed => Redis::connection('cache')->get($key), $cardKeys);
    t08xPrime($this);

    $movers = $this->getJson('/v1/movers');
    $pageAgain = app(CatalogRepository::class)->card('aa-1');

    $after = array_map(fn (string $key): mixed => Redis::connection('cache')->get($key), $cardKeys);
    expect($page?->data['data']['name'] ?? null)->toBe('Card page name')
        ->and($cardKeys)->not->toBe([])
        ->and($movers->json('data.0.card'))->toBe(t08xName('aa-1'))
        ->and(t08xBatches())->toBe([['aa-1']])
        ->and($after)->toBe($before)
        ->and($pageAgain?->data['data']['name'] ?? null)->toBe('Card page name');
    Http::assertNotSent(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards/aa-1');
});
