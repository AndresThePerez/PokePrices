<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// Reviewer edge tests for T43 (GET /v1/cards/{id}). Every upstream call is faked; every /v1 response is also
// validated against openapi/api.json by Tests\TestCase (C3). The card read goes through the catalog cache on real
// Redis under a per-test prefix with the seed ref primed.

const T43E_BASE = 'http://192.0.2.10:8081';

const T43E_SEED_REF = '0123456789abcdef0123456789abcdef01234567';

const T43E_SENTINEL = 'sentinel-0000-not-a-real-secret';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T43E_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T43E_BASE, T43E_SEED_REF);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

function t43eCard(string $id = 'base1-4', array $overrides = []): array
{
    return array_merge([
        'id' => $id, 'name' => 'Charizard', 'supertype' => 'Pokémon', 'subtypes' => ['Stage 2'], 'hp' => 120,
        'types' => ['Fire'], 'rarity' => 'Rare Holo', 'artist' => 'Mitsuhiro Arita', 'number' => '4',
        'set_id' => 'base1', 'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102,
        'release_date' => '1999-01-09',
        'image_small' => 'https://images.example.com/base1/4.png',
        'image_large' => 'https://images.example.com/base1/4_hires.png',
    ], $overrides);
}

function t43eAnswer(array $card): array
{
    return ['data' => $card, 'seed' => ['ref' => T43E_SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z']];
}

function t43eFake(string $id, mixed $body, int $status = 200): void
{
    Http::fake([T43E_BASE.'/api/v1/cards/'.rawurlencode($id) => Http::response($body, $status)]);
}

function t43eBuild(string $day): int
{
    return DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day, 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11', 'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00', 'finished_at' => '2026-10-12 08:05:00+00', 'counts' => '{}',
    ]);
}

function t43eVariant(string $cardId, string $finish, string $status = 'unverified', ?int $build = null, int $productId = 1, int $epoch = 1): string
{
    $key = "{$cardId}:{$finish}:-:-";
    DB::table('card_variants')->insert([
        'variant_key' => $key, 'card_id' => $cardId, 'finish' => $finish, 'edition' => '-', 'treatment' => '-',
        'catalog_status' => $status,
    ]);
    if ($build !== null) {
        DB::table('source_product_map')->insert([
            'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => $productId,
            'source_subtype' => 'Holofoil', 'source_product_name' => 'Charizard', 'source_number' => '4/102',
            'variant_key' => $key, 'method' => 'number+name', 'confidence' => '1.000', 'name_agreement' => true,
            'series_epoch' => $epoch, 'map_build_id' => $build,
        ]);
    }

    return $key;
}

it('answers the same ETag for the same card across reads and a different one once a variant row is added', function (): void {
    $build = t43eBuild('2026-10-11');
    foreach (['reverse', 'normal', 'holo'] as $i => $finish) {
        t43eVariant('base1-4', $finish, build: $build, productId: 100 + $i);
    }
    t43eFake('base1-4', t43eAnswer(t43eCard()));

    $first = $this->getJson('/v1/cards/base1-4');
    $second = $this->getJson('/v1/cards/base1-4');
    $etag = (string) $first->headers->get('ETag');
    expect($etag)->not->toBe('')
        ->and($second->headers->get('ETag'))->toBe($etag)
        ->and($second->getContent())->toBe($first->getContent())
        ->and(array_column($first->json('data.variants'), 'finish'))->toBe(['holo', 'normal', 'reverse']);

    DB::table('card_variants')->insert([
        'variant_key' => 'base1-4:normal:1st:-', 'card_id' => 'base1-4', 'finish' => 'normal', 'edition' => '1st', 'treatment' => '-',
    ]);
    $third = $this->getJson('/v1/cards/base1-4');
    expect($third->getStatusCode())->toBe(200)
        ->and($third->headers->get('ETag'))->not->toBe($etag)
        ->and($third->json('data.variants'))->toHaveCount(4);
});

it('answers a 128 character id (the pattern maximum) end to end and asks PokéSearch for it unchanged', function (): void {
    $id = 'a'.str_repeat('b', 127);
    t43eVariant($id, 'normal', build: t43eBuild('2026-10-11'));
    t43eFake($id, t43eAnswer(t43eCard($id)));

    $response = $this->getJson('/v1/cards/'.$id);

    expect($response->getStatusCode())->toBe(200)
        ->and(strlen((string) $response->json('data.id')))->toBe(128)
        ->and($response->json('data.variants'))->toHaveCount(1);
    Http::assertSentCount(1);
});

it('keeps mappings, epochs and unflagged cards when a miss is flagged twice, and leaves a card without rows without any new row', function (): void {
    $build = t43eBuild('2026-10-11');
    $key = t43eVariant('base1-4', 'holo', 'ok', $build, 7, epoch: 3);
    t43eVariant('base1-4', 'normal', 'ok');
    t43eVariant('base1-5', 'holo', 'ok', $build, 8);
    t43eFake('base1-4', ['error' => ['code' => 'not_found']], 404);
    Http::fake([T43E_BASE.'/api/v1/cards/'.rawurlencode('base1-999') => Http::response(['error' => ['code' => 'not_found']], 404)]);
    $rowsBefore = DB::table('card_variants')->count();

    $one = $this->getJson('/v1/cards/base1-4');
    $two = $this->getJson('/v1/cards/base1-4');
    $none = $this->getJson('/v1/cards/base1-999');

    expect($one->json('catalog_missing'))->toBeTrue()
        ->and($two->json('catalog_missing'))->toBeTrue()
        ->and($none->json('catalog_missing'))->toBeFalse()
        ->and(DB::table('card_variants')->count())->toBe($rowsBefore)
        ->and(DB::table('card_variants')->where('card_id', 'base1-4')->pluck('catalog_status')->unique()->all())->toBe(['catalog_missing'])
        ->and(DB::table('card_variants')->where('card_id', 'base1-5')->value('catalog_status'))->toBe('ok')
        ->and((int) DB::table('source_product_map')->where('variant_key', $key)->value('series_epoch'))->toBe(3)
        ->and((int) DB::table('source_product_map')->where('variant_key', $key)->value('map_build_id'))->toBe($build)
        ->and(DB::table('source_product_map_history')->count())->toBe(0)
        ->and(DB::table('card_variants')->where('card_id', 'base1-999')->count())->toBe(0);
});

it('never restores a flagged row on a read and does not write when the card is found', function (): void {
    t43eVariant('base1-4', 'holo', 'catalog_missing');
    $stamp = '2026-01-02 03:04:05+00';
    DB::table('card_variants')->where('card_id', 'base1-4')->update(['catalog_checked_at' => $stamp, 'catalog_seed_ref' => 'old']);
    t43eFake('base1-4', t43eAnswer(t43eCard()));

    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.catalog.status'))->toBe('catalog_missing')
        ->and(Carbon::parse((string) DB::table('card_variants')->where('card_id', 'base1-4')->value('catalog_checked_at'))->equalTo(Carbon::parse($stamp)))->toBeTrue()
        ->and(DB::table('card_variants')->where('card_id', 'base1-4')->value('catalog_seed_ref'))->toBe('old');
});

it('reports unverified for a mix of ok and unverified rows, ok only when every row is ok', function (): void {
    t43eVariant('base1-4', 'holo', 'ok');
    t43eVariant('base1-4', 'normal', 'unverified');
    t43eVariant('base1-5', 'holo', 'ok');
    t43eVariant('base1-5', 'normal', 'ok');
    t43eFake('base1-4', t43eAnswer(t43eCard()));
    t43eFake('base1-5', t43eAnswer(t43eCard('base1-5')));

    expect($this->getJson('/v1/cards/base1-4')->json('data.catalog.status'))->toBe('unverified')
        ->and($this->getJson('/v1/cards/base1-5')->json('data.catalog.status'))->toBe('ok');
});

it('reads 24 variants mapped from different builds in 4 queries and dates every source by its own build', function (): void {
    $builds = [t43eBuild('2026-10-09'), t43eBuild('2026-10-10'), t43eBuild('2026-10-11')];
    $n = 0;
    foreach (['normal', 'holo', 'reverse'] as $finish) {
        foreach (['-', '1st', 'unlimited', 'shadowless'] as $edition) {
            foreach (['-', 'pokeball'] as $treatment) {
                $key = "base1-4:{$finish}:{$edition}:{$treatment}";
                DB::table('card_variants')->insert(['variant_key' => $key, 'card_id' => 'base1-4', 'finish' => $finish, 'edition' => $edition, 'treatment' => $treatment]);
                DB::table('source_product_map')->insert([
                    'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => 5000 + $n, 'source_subtype' => 'Normal',
                    'source_product_name' => 'Charizard', 'source_number' => '4/102', 'variant_key' => $key, 'method' => 'number+name',
                    'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $builds[$n % 3],
                ]);
                $n++;
            }
        }
    }
    t43eFake('base1-4', t43eAnswer(t43eCard()));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/v1/cards/base1-4');
    DB::disableQueryLog();
    $queries = count(DB::getQueryLog());

    $days = collect($response->json('data.variants'))->mapWithKeys(fn (array $v): array => [$v['sources'][0]['product_id'] => $v['sources'][0]['map_snapshot_day']]);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.variants'))->toHaveCount(24)
        ->and($queries)->toBe(4)
        ->and($days->unique()->sort()->values()->all())->toBe(['2026-10-09', '2026-10-10', '2026-10-11']);
    // The variants sort by key (finish first), so the product ids are not in insertion order; each id keeps its build.
    foreach ($days as $productId => $day) {
        expect($day)->toBe(['2026-10-09', '2026-10-10', '2026-10-11'][($productId - 5000) % 3]);
    }
});

it('answers the largest integer product id as a JSON integer', function (): void {
    t43eVariant('base1-4', 'holo', 'ok', t43eBuild('2026-10-11'), 2147483647);
    t43eFake('base1-4', t43eAnswer(t43eCard()));

    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('data.variants.0.sources.0.product_id'))->toBe(2147483647)
        ->and($response->json('data.variants.0.sources.0.group_id'))->toBe(604);
});

it('does not leak upstream error bodies or unmapped upstream fields into the response', function (): void {
    t43eFake('base1-4', t43eAnswer(t43eCard('base1-4', ['internal_note' => T43E_SENTINEL, 'image_small' => 'https://images.example.com/base1/4.png'])));
    $ok = $this->getJson('/v1/cards/base1-4');

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => T43E_SENTINEL, 'detail' => 'key '.T43E_SENTINEL], 500)]);
    $down = $this->getJson('/v1/cards/base1-5');

    expect($ok->getStatusCode())->toBe(200)
        ->and((string) $ok->getContent())->not->toContain(T43E_SENTINEL)
        ->and($down->getStatusCode())->toBe(503)
        ->and((string) $down->getContent())->not->toContain(T43E_SENTINEL)
        ->and((string) $down->headers)->not->toContain(T43E_SENTINEL);
});

it('answers HEAD with the ETag and no body, and a matching If-None-Match on HEAD with 304', function (): void {
    t43eVariant('base1-4', 'holo', 'ok', t43eBuild('2026-10-11'));
    t43eFake('base1-4', t43eAnswer(t43eCard()));
    $etag = (string) $this->getJson('/v1/cards/base1-4')->headers->get('ETag');

    $head = $this->call('HEAD', '/v1/cards/base1-4');
    $conditional = $this->call('HEAD', '/v1/cards/base1-4', server: ['HTTP_IF_NONE_MATCH' => $etag]);

    expect($etag)->not->toBe('')
        ->and($head->getStatusCode())->toBe(200)
        ->and($head->getContent())->toBe('')
        ->and($head->headers->get('ETag'))->toBe($etag)
        ->and($conditional->getStatusCode())->toBe(304)
        ->and($conditional->headers->get('ETag'))->toBe($etag);
});

it('does not give a problem answer a public Cache-Control or an ETag', function (): void {
    t43eVariant('base1-4', 'holo', 'ok');
    t43eFake('base1-4', ['error' => ['code' => 'not_found']], 404);

    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('public')
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('stale-while-revalidate');
});
