<?php

declare(strict_types=1);

use App\Domain\Mapping\Models\CardVariant;
use App\Domain\Mapping\Models\SourceProductMap;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogCache;

// GET /v1/cards/{id} hydrates one PokéSearch card with the PokéPrices variant rows and their TCGplayer sources
// (plan T43, review-1 rec 12). Every upstream call is faked; every /v1 response below is also validated against
// openapi/api.json by Tests\TestCase (C3), the 304, 404, 429, 502 and 503 answers included. The card read goes
// through the catalog cache (T42) on real Redis with a per-test key prefix and a primed seed ref.

const T43_BASE = 'http://192.0.2.10:8081';

const T43_SEED_REF = '0123456789abcdef0123456789abcdef01234567';

const T43_PROBLEM_BASE = 'https://pokeprices.andrestheperez.com/problems/';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T43_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T43_BASE, T43_SEED_REF);
    Http::preventStrayRequests();

    // A fresh in-memory limiter per test, with the tiers registered the same way, so no counter leaks in.
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** One card as PokéSearch's GET /api/v1/cards/{id} carries it in data, with fields PokéPrices must drop. */
function t43Card(string $id = 'base1-4', array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'name' => 'Charizard',
        'supertype' => 'Pokémon',
        'subtypes' => ['Stage 2'],
        'hp' => 120,
        'types' => ['Fire'],
        'evolves_from' => 'Charmeleon',
        'attacks' => [['name' => 'Fire Spin', 'cost' => ['Fire'], 'converted_cost' => 4, 'damage' => '100', 'damage_value' => 100, 'text' => 'Discard 2 Energy.']],
        'retreat_cost' => 3,
        'rarity' => 'Rare Holo',
        'artist' => 'Mitsuhiro Arita',
        'flavor_text' => 'Spits fire.',
        'national_pokedex_numbers' => [6],
        'number' => '4',
        'set_id' => 'base1',
        'set_name' => 'Base',
        'set_series' => 'Base',
        'set_total' => 102,
        'release_date' => '1999-01-09',
        'image_small' => 'https://images.example.com/base1/4.png',
        'image_large' => 'https://images.example.com/base1/4_hires.png',
    ], $overrides);
}

/** A whole PokéSearch card answer: the card and the provenance of the index that answered. */
function t43Answer(array $card, ?string $ref = T43_SEED_REF, ?string $seededAt = '2026-10-11T20:05:45Z'): array
{
    return ['data' => $card, 'seed' => ['ref' => $ref, 'seeded_at' => $seededAt]];
}

function t43FakeCard(string $id, mixed $body, int $status = 200): void
{
    Http::fake([T43_BASE.'/api/v1/cards/'.rawurlencode($id) => Http::response($body, $status)]);
}

/** Every later PokéSearch call fails: the fake factory is replaced, so earlier fakes no longer answer. */
function t43UpstreamDown(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'es down'], 503)]);
}

function t43Build(string $day = '2026-10-11'): int
{
    return DB::table('map_builds')->insertGetId([
        'snapshot_day' => $day,
        'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
        'seed_ref' => null,
        'started_at' => '2026-10-12 08:00:00+00',
        'finished_at' => '2026-10-12 08:05:00+00',
        'counts' => '{}',
    ]);
}

/**
 * $count variant rows of $cardId, each mapped to its own TCGplayer product in build $build (when given).
 * Variant n takes finish, edition and treatment from the 3 x 4 x 2 grid, so up to 24 rows are distinct.
 *
 * @return list<string> the variant keys, in insertion order
 */
function t43Variants(string $cardId, int $count, ?int $build, int $firstProductId = 42000, string $status = 'unverified'): array
{
    $keys = [];
    $n = 0;
    foreach (['-', 'pokeball'] as $treatment) {
        foreach (['-', '1st', 'unlimited', 'shadowless'] as $edition) {
            foreach (['normal', 'holo', 'reverse'] as $finish) {
                if ($n === $count) {
                    break 3;
                }
                $key = "{$cardId}:{$finish}:{$edition}:{$treatment}";
                DB::table('card_variants')->insert([
                    'variant_key' => $key, 'card_id' => $cardId, 'finish' => $finish, 'edition' => $edition,
                    'treatment' => $treatment, 'catalog_status' => $status,
                ]);
                if ($build !== null) {
                    DB::table('source_product_map')->insert([
                        'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => $firstProductId + $n,
                        'source_subtype' => 'Holofoil', 'source_product_name' => 'Charizard', 'source_number' => '4/102',
                        'variant_key' => $key, 'method' => 'number+name', 'confidence' => '1.000',
                        'name_agreement' => true, 'series_epoch' => 1, 'map_build_id' => $build,
                    ]);
                }
                $keys[] = $key;
                $n++;
            }
        }
    }

    return $keys;
}

/** @return list<string> the Cache-Control directives, sorted (Symfony normalises their order) */
function t43CacheDirectives(TestResponse $response): array
{
    $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    sort($directives);

    return $directives;
}

it('answers the hydrated card: catalog fields, set, images, mapped variants, catalog provenance and meta', function (): void {
    $build = t43Build('2026-10-11');
    DB::table('card_variants')->insert([
        'variant_key' => 'base1-4:holo:unlimited:-', 'card_id' => 'base1-4', 'finish' => 'holo',
        'edition' => 'unlimited', 'treatment' => '-', 'catalog_status' => 'ok',
    ]);
    DB::table('source_product_map')->insert([
        'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => 42382, 'source_subtype' => 'Holofoil',
        'source_product_name' => 'Charizard', 'source_number' => '4/102', 'variant_key' => 'base1-4:holo:unlimited:-',
        'method' => 'number+name', 'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 2,
        'map_build_id' => $build,
    ]);
    t43FakeCard('base1-4', t43Answer(t43Card()));

    $response = $this->getJson('/v1/cards/base1-4');

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [
            'id' => 'base1-4',
            'name' => 'Charizard',
            'supertype' => 'Pokémon',
            'subtypes' => ['Stage 2'],
            'hp' => 120,
            'types' => ['Fire'],
            'rarity' => 'Rare Holo',
            'number' => '4',
            'artist' => 'Mitsuhiro Arita',
            'set' => ['id' => 'base1', 'name' => 'Base', 'series' => 'Base', 'total' => 102, 'release_date' => '1999-01-09'],
            'images' => ['small' => 'https://images.example.com/base1/4.png', 'large' => 'https://images.example.com/base1/4_hires.png'],
            'variants' => [[
                'variant_key' => 'base1-4:holo:unlimited:-',
                'finish' => 'holo',
                'edition' => 'unlimited',
                'treatment' => '-',
                'sources' => [[
                    'source' => 'tcgplayer',
                    'product_id' => 42382,
                    'subtype' => 'Holofoil',
                    'group_id' => 604,
                    'series_epoch' => 2,
                    'map_snapshot_day' => '2026-10-11',
                ]],
            ]],
            'catalog' => ['seed_ref' => T43_SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z', 'status' => 'ok'],
        ],
        'meta' => ['degraded' => false],
    ]);
    Http::assertSentCount(1);
});

it('carries no price field and none of the PokéSearch fields it does not map', function (): void {
    t43Variants('base1-4', 2, t43Build());
    t43FakeCard('base1-4', t43Answer(t43Card()));

    $response = $this->getJson('/v1/cards/base1-4');

    $response->assertOk();
    $body = (string) $response->getContent();
    foreach (['price', 'market', 'evolves_from', 'attacks', 'retreat_cost', 'flavor_text', 'national_pokedex_numbers', 'set_id', 'image_small', 'request_id', 'source_product_name', 'confidence', 'method'] as $leak) {
        expect($body)->not->toContain($leak);
    }
    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('data')))->toBe(['id', 'name', 'supertype', 'subtypes', 'hp', 'types', 'rarity', 'number', 'artist', 'set', 'images', 'variants', 'catalog'])
        ->and(array_keys($response->json('data.variants.0')))->toBe(['variant_key', 'finish', 'edition', 'treatment', 'sources'])
        ->and(array_keys($response->json('data.variants.0.sources.0')))->toBe(['source', 'product_id', 'subtype', 'group_id', 'series_epoch', 'map_snapshot_day']);
});

it('answers an unmapped card with no variants, null for omitted optional fields and an unseeded catalog', function (): void {
    $card = array_diff_key(t43Card('base1-95', ['name' => 'Switch', 'supertype' => 'Trainer']), array_flip(['hp', 'rarity', 'artist', 'subtypes', 'types']));
    t43FakeCard('base1-95', t43Answer($card, null, null));

    $response = $this->getJson('/v1/cards/base1-95');

    $response->assertOk();
    expect($response->json('data.variants'))->toBe([])
        ->and($response->json('data.subtypes'))->toBe([])
        ->and($response->json('data.types'))->toBe([])
        ->and($response->json('data'))->toHaveKey('hp')
        ->and($response->json('data.hp'))->toBeNull()
        ->and($response->json('data.rarity'))->toBeNull()
        ->and($response->json('data.artist'))->toBeNull()
        ->and($response->json('data.catalog'))->toBe(['seed_ref' => null, 'seeded_at' => null, 'status' => 'unverified'])
        ->and($response->json('meta'))->toBe(['degraded' => false]);
});

it('lists every variant of the card ordered by variant key, a variant without a mapping with no sources', function (): void {
    $build = t43Build();
    t43Variants('base1-4', 3, $build);
    DB::table('card_variants')->insert([
        'variant_key' => 'base1-4:normal:-:stamp-prerelease', 'card_id' => 'base1-4', 'finish' => 'normal',
        'edition' => '-', 'treatment' => 'stamp-prerelease',
    ]);
    t43Variants('base1-5', 2, $build, 43000); // another card's rows never leak in
    t43FakeCard('base1-4', t43Answer(t43Card()));

    $response = $this->getJson('/v1/cards/base1-4');

    $response->assertOk();
    $keys = array_column($response->json('data.variants'), 'variant_key');
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    expect($keys)->toHaveCount(4)
        ->and($keys)->toBe($sorted)
        ->and($keys)->each->toStartWith('base1-4:')
        ->and(collect($response->json('data.variants'))->firstWhere('treatment', 'stamp-prerelease')['sources'])->toBe([]);
});

it('reports catalog_missing in data.catalog.status when a variant row is flagged', function (): void {
    t43Variants('base1-4', 2, null, status: 'ok');
    DB::table('card_variants')->where('variant_key', 'base1-4:holo:-:-')->update(['catalog_status' => 'catalog_missing']);
    t43FakeCard('base1-4', t43Answer(t43Card()));

    $response = $this->getJson('/v1/cards/base1-4');

    $response->assertOk();
    expect($response->json('data.catalog.status'))->toBe('catalog_missing');
});

it('answers 404 card-not-found without calling PokéSearch for an id outside the card id pattern', function (string $path): void {
    $response = $this->getJson('/v1/cards/'.$path);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(T43_PROBLEM_BASE.'card-not-found')
        ->and($response->json('catalog_missing'))->toBeFalse()
        ->and($response->headers->has('ETag'))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'a leading dash' => '-base1-4',
    'a space' => 'base1%204',
    'a colon' => 'base1-4%3Anone',
    'a percent sign' => 'base1%254',
    'a trailing newline' => 'base1-4%0A',
    'a non-ASCII letter' => 'base1-%C3%A9',
    '129 characters' => str_repeat('a', 129),
]);

it('answers 404 card-not-found with catalog_missing false when PokéSearch has no such card and PokéPrices no rows', function (): void {
    t43FakeCard('base1-999', ['error' => ['code' => 'not_found']], 404);

    $response = $this->getJson('/v1/cards/base1-999');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(T43_PROBLEM_BASE.'card-not-found')
        ->and($response->json('title'))->toBe('Card Not Found')
        ->and($response->json('catalog_missing'))->toBeFalse()
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('answers catalog_missing true for a PokéSearch miss with variant rows, flags the rows and deletes nothing', function (): void {
    $build = t43Build();
    $keys = t43Variants('base1-4', 3, $build, status: 'ok');
    $other = t43Variants('base1-5', 1, $build, 43000, status: 'ok');
    t43FakeCard('base1-4', ['error' => ['code' => 'not_found']], 404);
    $this->freezeSecond();

    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->json('type'))->toBe(T43_PROBLEM_BASE.'card-not-found')
        ->and($response->json('catalog_missing'))->toBeTrue()
        ->and($response->headers->has('ETag'))->toBeFalse();
    $rows = DB::table('card_variants')->where('card_id', 'base1-4')->orderBy('variant_key')->get();
    expect($rows->pluck('variant_key')->sort()->values()->all())->toBe(collect($keys)->sort()->values()->all())
        ->and($rows->pluck('catalog_status')->unique()->all())->toBe(['catalog_missing'])
        ->and($rows->pluck('catalog_seed_ref')->unique()->all())->toBe([T43_SEED_REF])
        ->and($rows->every(fn (object $row): bool => Carbon::parse($row->catalog_checked_at)->equalTo(Carbon::now())))->toBeTrue()
        ->and(DB::table('source_product_map')->whereIn('variant_key', $keys)->count())->toBe(3)
        ->and(DB::table('card_variants')->where('variant_key', $other[0])->value('catalog_status'))->toBe('ok');

    // The 404 is remembered for 60 s (T42); the answer within that window is the same and still flags.
    $again = $this->getJson('/v1/cards/base1-4');
    expect($again->getStatusCode())->toBe(404)
        ->and($again->json('catalog_missing'))->toBeTrue();
    Http::assertSentCount(1);
});

it('answers the 503 upstream-unavailable problem with Retry-After when PokéSearch is down and nothing was cached', function (): void {
    t43Variants('base1-4', 1, t43Build());
    t43UpstreamDown();

    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(T43_PROBLEM_BASE.'upstream-unavailable')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and(DB::table('card_variants')->where('card_id', 'base1-4')->value('catalog_status'))->toBe('unverified');
});

it('answers a degraded 200 from the last-good copy with stale_since, no-store, no ETag and never 304', function (): void {
    t43Variants('base1-4', 1, t43Build());
    $this->freezeSecond();
    $fetchedAt = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');
    t43FakeCard('base1-4', t43Answer(t43Card()));
    $fresh = $this->getJson('/v1/cards/base1-4');
    $fresh->assertOk();
    $etag = (string) $fresh->headers->get('ETag');

    $this->travel(86401)->seconds(); // past both flexible windows of the card key [3600, 86400]
    t43UpstreamDown();
    $degraded = $this->getJson('/v1/cards/base1-4');
    $revalidated = $this->withHeader('If-None-Match', $etag)->getJson('/v1/cards/base1-4');

    expect($etag)->not->toBe('')
        ->and($degraded->getStatusCode())->toBe(200)
        ->and($degraded->json('meta'))->toBe(['degraded' => true, 'stale_since' => $fetchedAt])
        ->and($degraded->json('data.id'))->toBe('base1-4')
        ->and($degraded->json('data.variants'))->toHaveCount(1)
        ->and($degraded->headers->has('ETag'))->toBeFalse()
        ->and(t43CacheDirectives($degraded))->toContain('no-store')
        ->and(t43CacheDirectives($degraded))->not->toContain('public')
        ->and((string) $degraded->headers->get('Cache-Control'))->not->toContain('max-age')
        ->and($revalidated->getStatusCode())->toBe(200)
        ->and($revalidated->getContent())->not->toBe('')
        ->and($revalidated->headers->has('ETag'))->toBeFalse()
        ->and($revalidated->json('meta.degraded'))->toBeTrue();
});

it('sends an xxh128 body-hash ETag and public, max-age=60, stale-while-revalidate=300 on a 200', function (): void {
    t43Variants('base1-4', 2, t43Build());
    t43FakeCard('base1-4', t43Answer(t43Card()));

    $response = $this->getJson('/v1/cards/base1-4');

    $response->assertOk();
    expect($response->headers->get('ETag'))->toBe('"'.hash('xxh128', (string) $response->getContent()).'"')
        ->and(t43CacheDirectives($response))->toBe(['max-age=60', 'public', 'stale-while-revalidate=300']);
});

it('answers 304 with the ETag and no body when If-None-Match matches, and 200 when it does not', function (): void {
    t43Variants('base1-4', 2, t43Build());
    t43FakeCard('base1-4', t43Answer(t43Card()));
    $etag = (string) $this->getJson('/v1/cards/base1-4')->headers->get('ETag');

    $matched = $this->withHeader('If-None-Match', $etag)->getJson('/v1/cards/base1-4');
    $unmatched = $this->withHeader('If-None-Match', '"00000000000000000000000000000000"')->getJson('/v1/cards/base1-4');

    expect($etag)->not->toBe('')
        ->and($matched->getStatusCode())->toBe(304)
        ->and($matched->getContent())->toBe('')
        ->and($matched->headers->get('ETag'))->toBe($etag)
        ->and(t43CacheDirectives($matched))->toBe(['max-age=60', 'public', 'stale-while-revalidate=300'])
        ->and($unmatched->getStatusCode())->toBe(200)
        ->and($unmatched->json('data.id'))->toBe('base1-4');
    Http::assertSentCount(1);
});

it('keeps the search route on its own cache policy', function (): void {
    Http::fake([T43_BASE.'/api/search*' => Http::response(['total' => 0, 'page' => 1, 'pages' => 0, 'page_size' => 24, 'results' => []])]);

    $response = $this->getJson('/v1/cards?q=charizard');

    $response->assertOk();
    expect(t43CacheDirectives($response))->toBe(['max-age=60', 'public', 's-maxage=300']);
});

it('puts throttle:public-read, conditional-get and the PokéSearch tag on the card route', function (): void {
    $route = Route::getRoutes()->match(Request::create('/v1/cards/base1-4', 'GET'));
    $middleware = $route->gatherMiddleware();

    expect($route->getName())->toBe('cards.show')
        ->and($middleware)->toContain('throttle:public-read')
        ->and(array_filter($middleware, fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'conditional-get')))->not->toBe([])
        ->and($route->getAction('pokesearch'))->toBeTrue();
});

it('answers the 121st card read in a minute from one IP with the 429 problem and Retry-After', function (): void {
    t43FakeCard('base1-4', t43Answer(t43Card()));

    for ($i = 1; $i <= 120; $i++) {
        $this->getJson('/v1/cards/base1-4')->assertOk();
    }
    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(T43_PROBLEM_BASE.'too-many-requests')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $response->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($response->headers->has('ETag'))->toBeFalse();

    $other = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.31'])->getJson('/v1/cards/base1-4');
    expect($other->getStatusCode())->toBe(200);
});

it('answers the 502 upstream-contract problem when PokéSearch answers outside its card contract', function (int $status, mixed $body): void {
    t43FakeCard('base1-4', $body, $status);

    $response = $this->getJson('/v1/cards/base1-4');

    expect($response->getStatusCode())->toBe(502)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(T43_PROBLEM_BASE.'upstream-contract')
        ->and($response->headers->has('ETag'))->toBeFalse();
})->with([
    'a 400' => [400, ['error' => ['code' => 'invalid_param']]],
    'no data' => [200, ['seed' => ['ref' => null, 'seeded_at' => null]]],
    'no seed' => [200, ['data' => t43Card()]],
    'data a list' => [200, t43Answer([1, 2])],
    'a card without a name' => [200, t43Answer(array_diff_key(t43Card(), ['name' => true]))],
    'a card without image_large' => [200, t43Answer(array_diff_key(t43Card(), ['image_large' => true]))],
    'a string set_total' => [200, t43Answer(t43Card(overrides: ['set_total' => '102']))],
    'a string hp' => [200, t43Answer(t43Card(overrides: ['hp' => '120']))],
    'a subtypes string' => [200, t43Answer(t43Card(overrides: ['subtypes' => 'Stage 2']))],
    'a numeric rarity' => [200, t43Answer(t43Card(overrides: ['rarity' => 3]))],
    'another card' => [200, t43Answer(t43Card('base1-5'))],
    'a numeric seed ref' => [200, ['data' => t43Card(), 'seed' => ['ref' => 7, 'seeded_at' => null]]],
]);

it('reaches the controller as ex10-? and ex10-! from the encoded path and asks PokéSearch for the encoded id', function (string $path, string $id, string $upstream): void {
    t43Variants($id, 1, t43Build());
    Http::fake([T43_BASE.'/api/v1/cards/*' => Http::response(t43Answer(t43Card($id, ['name' => 'Unown', 'number' => substr($id, 5)])))]);

    $response = $this->getJson('/v1/cards/'.$path);

    $response->assertOk();
    expect($response->json('data.id'))->toBe($id)
        ->and($response->json('data.variants.0.variant_key'))->toBe("{$id}:normal:-:-");
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1)
        ->and($recorded->first()[0]->url())->toBe(T43_BASE.'/api/v1/cards/'.$upstream);
})->with([
    'question mark' => ['ex10-%3F', 'ex10-?', 'ex10-%3F'],
    'exclamation mark' => ['ex10-!', 'ex10-!', 'ex10-%21'],
    'exclamation mark encoded' => ['ex10-%21', 'ex10-!', 'ex10-%21'],
]);

it('documents GET /cards/{id} with a closed card schema and the 304, 404, 429, 502 and 503 answers', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/cards/{id}']['get'] ?? null;

    expect($operation)->not->toBeNull()
        ->and(array_map('strval', array_keys($operation['responses'])))->toBe(['200', '304', '404', '429', '502', '503'])
        ->and($operation['responses']['404']['content']['application/problem+json']['schema']['$ref'])->toBe('#/components/schemas/Problem')
        ->and($operation['responses']['304']['headers'])->toHaveKey('ETag')
        ->and($operation['responses']['429']['headers'])->toHaveKey('Retry-After')
        ->and($operation['responses']['503']['headers'])->toHaveKey('Retry-After');
    $body = $operation['responses']['200']['content']['application/json']['schema'];
    $resolve = fn (array $schema): array => isset($schema['$ref']) ? $spec['components']['schemas'][basename($schema['$ref'])] : $schema;
    $body = $resolve($body);
    $card = $resolve($body['properties']['data']);
    expect($body['additionalProperties'])->toBeFalse()
        ->and($card['additionalProperties'])->toBeFalse()
        ->and(array_keys($card['properties']))->toBe(['id', 'name', 'supertype', 'subtypes', 'hp', 'types', 'rarity', 'number', 'artist', 'set', 'images', 'variants', 'catalog'])
        ->and(strtolower((string) json_encode([$body, $card])))->not->toContain('price');
});

it('throws on a lazy relation access to a P1 model', function (): void {
    $build = t43Build();
    t43Variants('base1-4', 2, $build);

    $variants = CardVariant::query()->where('card_id', 'base1-4')->get();
    $sources = SourceProductMap::query()->whereIn('variant_key', $variants->pluck('variant_key'))->get();

    expect($variants)->toHaveCount(2)
        ->and($sources)->toHaveCount(2)
        ->and(fn () => $variants->first()->sources)->toThrow(LazyLoadingViolationException::class)
        ->and(fn () => $sources->first()->mapBuild)->toThrow(LazyLoadingViolationException::class);
})->group('P7');

it('registers the lazy-loading guard from App\Domain\Catalog, never from app/Providers', function (): void {
    $catalog = '';
    foreach (glob(app_path('Domain/Catalog/*.php')) ?: [] as $file) {
        $catalog .= (string) file_get_contents($file);
    }
    $providers = '';
    foreach (glob(app_path('Providers/*.php')) ?: [] as $file) {
        $providers .= (string) file_get_contents($file);
    }

    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and($catalog)->toContain('Model::preventLazyLoading(')
        ->and($providers)->not->toContain('preventLazyLoading');
})->group('P7');

it('reads a card with 3 queries whether it has 1 or 24 variant rows', function (): void {
    $build = t43Build();
    t43Variants('base1-4', 1, $build);
    t43Variants('base1-5', 24, $build, 43000);
    Http::fake([T43_BASE.'/api/v1/cards/*' => fn ($request) => Http::response(t43Answer(t43Card(basename($request->url()))))]);

    $counts = [];
    foreach (['base1-4' => 1, 'base1-5' => 24] as $id => $rows) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson("/v1/cards/{$id}");
        DB::disableQueryLog();
        $response->assertOk();
        expect($response->json('data.variants'))->toHaveCount($rows);
        $counts[$id] = count(DB::getQueryLog());
    }

    expect($counts)->toBe(['base1-4' => 3, 'base1-5' => 3]);
})->group('P8');
