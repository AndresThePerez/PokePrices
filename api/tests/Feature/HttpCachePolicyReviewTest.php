<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Reviewer edge tests for Q1-i5 T02 (the public-read edge). Every upstream call is faked on RFC 5737 addresses; every
// /v1 GET is also validated against openapi/api.json by Tests\TestCase (C3).

const T02R_BASE = 'http://192.0.2.10:8081';

const T02R_REF = '0123456789abcdef0123456789abcdef01234567';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T02R_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T02R_BASE, T02R_REF);
    Http::preventStrayRequests();
    Queue::fake([VerifyCatalog::class]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** @return array<string, mixed> */
function t02rCard(string $name): array
{
    return [
        'id' => 'base1-4', 'name' => $name, 'supertype' => 'Pokémon', 'subtypes' => ['Stage 2'], 'hp' => 120,
        'types' => ['Fire'], 'rarity' => 'Rare Holo', 'artist' => 'Mitsuhiro Arita', 'number' => '4',
        'set_id' => 'base1', 'set_name' => 'Flabébé/Base & "Co" <日本> 😀', 'set_series' => 'Base', 'set_total' => 102,
        'release_date' => '1999-01-09',
        'image_small' => 'https://images.example.com/base1/4.png',
        'image_large' => 'https://images.example.com/base1/4_hires.png',
    ];
}

function t02rUpstream(string $name): void
{
    $seed = ['ref' => T02R_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
    Http::fake([
        T02R_BASE.'/api/v1/cards/base1-4' => Http::response(['data' => t02rCard($name), 'seed' => $seed]),
        T02R_BASE.'/api/v1/cards?*' => Http::response(['data' => [t02rCard($name)], 'missing' => [], 'seed' => $seed]),
        T02R_BASE.'/api/search*' => Http::response(['total' => 1, 'page' => 1, 'pages' => 1, 'page_size' => 24, 'took_ms' => 3, 'results' => [t02rCard($name)]]),
    ]);
}

function t02rRows(): void
{
    DB::table('card_variants')->insert(['variant_key' => 'base1-4:holo:-:-', 'card_id' => 'base1-4', 'finish' => 'holo',
        'edition' => '-', 'treatment' => '-', 'catalog_status' => 'unverified']);
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 1,
        'variant_key' => 'base1-4:holo:-:-', 'card_id' => 'base1-4', 'series_epoch' => 1, 'close' => '11.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => '1.00', 'change_pct' => '10.00', 'as_of' => '2026-10-11 20:05:45+00']);
}

function t02rHead(object $test, string $path, array $server = []): TestResponse
{
    return $test->call('HEAD', $path, server: $server + ['HTTP_ACCEPT' => 'application/json']);
}

it('gives GET and HEAD one ETag for names with accents, quotes, slashes, CJK and emoji on the public reads', function (string $name, string $path): void {
    t02rRows();
    t02rUpstream($name);
    $this->getJson($path)->assertOk(); // warm the catalog cache so meta.cached is the same below

    $get = $this->getJson($path);
    $head = t02rHead($this, $path);

    expect($get->getStatusCode())->toBe(200)
        ->and($get->headers->get('ETag'))->toBe('"'.hash('xxh128', (string) $get->getContent()).'"')
        ->and($head->headers->get('ETag'))->toBe($get->headers->get('ETag'))
        ->and(t02rHead($this, $path, ['HTTP_IF_NONE_MATCH' => (string) $get->headers->get('ETag')])->getStatusCode())->toBe(304);
})->with([
    'accent' => ['Flabébé', '/v1/cards/base1-4'],
    'slash and quote' => ['A/B "C" \\ D', '/v1/cards/base1-4'],
    'emoji and CJK' => ['Pikachu 😀 ピカチュウ', '/v1/cards?q=pika'],
    'accent on movers' => ['Flabébé', '/v1/movers'],
    'slash on movers' => ['A/B & <C>', '/v1/movers?direction=up&limit=1'],
]);

it('gives GET and HEAD the same ETag for a JSON body with floats, empty objects, nulls and every encoding option the app might set', function (int $options): void {
    Route::middleware('conditional-get:history')->get('/v1/__t02r_shapes', fn () => response()->json([
        'data' => [
            'unit' => 1.0, 'tenth' => 0.1, 'huge' => 1.0e25, 'tiny' => 5.0e-10, 'int' => 7, 'nul' => null,
            'object' => (object) [], 'list' => [], 'nested' => ['a' => [], 'b' => (object) [], 'c' => [[], [null]]],
            'sparse' => [3 => 'x', 5 => 'y'], 'numeric_keys' => ['0' => 'a', '1' => 'b'], 'empty_key' => ['' => 1],
            'text' => "é/日本😀 \u{2028} \" \\ <>&'",
            'money' => '12.30',
        ],
        'meta' => ['degraded' => false],
    ], 200, [], $options));

    $get = $this->getJson('/v1/__t02r_shapes');
    $head = t02rHead($this, '/v1/__t02r_shapes');

    expect($get->headers->get('ETag'))->toBe('"'.hash('xxh128', (string) $get->getContent()).'"')
        ->and($head->headers->get('ETag'))->toBe($get->headers->get('ETag'))
        ->and(t02rHead($this, '/v1/__t02r_shapes', ['HTTP_IF_NONE_MATCH' => (string) $get->headers->get('ETag')])->getStatusCode())->toBe(304);
})->with([
    'defaults' => [0],
    'unescaped unicode and slashes' => [JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES],
    'preserved zero fraction' => [JSON_PRESERVE_ZERO_FRACTION],
    'pretty print' => [JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE],
    'hex tags and quotes' => [JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT],
    'all together' => [JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT],
]);

it('answers 304 to a quoted, weak, listed or wildcard If-None-Match on GET and HEAD alike, and 200 to a stale one', function (string $header, int $status): void {
    t02rRows();
    t02rUpstream('Charizard');
    $etag = (string) $this->getJson('/v1/movers')->headers->get('ETag');
    $value = str_replace('{etag}', $etag, $header);

    $get = $this->withHeader('If-None-Match', $value)->getJson('/v1/movers');
    $head = t02rHead($this, '/v1/movers', ['HTTP_IF_NONE_MATCH' => $value]);

    expect($etag)->not->toBe('')
        ->and($get->getStatusCode())->toBe($status)
        ->and($head->getStatusCode())->toBe($status);
    if ($status === 304) {
        expect($get->headers->get('ETag'))->toBe($etag)
            ->and($head->headers->get('ETag'))->toBe($etag)
            ->and((string) $get->getContent())->toBe('');
    }
})->with([
    'exact' => ['{etag}', 304],
    'weak' => ['W/{etag}', 304],
    'listed after another' => ['"0000", {etag}', 304],
    'listed before another' => ['{etag}, "0000"', 304],
    'wildcard' => ['*', 304],
    'stale' => ['"00000000000000000000000000000000"', 200],
    'unquoted' => ['not-an-etag', 200],
]);

it('treats a fresh read after a degraded one as cacheable: the degraded mark does not outlive its request', function (): void {
    t02rRows();
    t02rUpstream('Charizard');
    $this->freezeSecond();
    $this->getJson('/v1/cards/base1-4')->assertOk();
    $this->travel(86401)->seconds();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['error' => 'down'], 503)]);
    $degraded = $this->getJson('/v1/cards/base1-4');
    Http::swap(new Factory);
    Http::preventStrayRequests();
    CatalogCache::primeSeedRef(T02R_BASE, T02R_REF);
    t02rUpstream('Charizard');
    $this->travel(120)->seconds();

    $fresh = $this->getJson('/v1/cards/base1-4');

    expect($degraded->json('meta.degraded'))->toBeTrue()
        ->and($degraded->headers->has('ETag'))->toBeFalse()
        ->and($fresh->json('meta.degraded'))->toBeFalse()
        ->and($fresh->headers->get('Cache-Control'))->toBe('max-age=60, public, stale-while-revalidate=300')
        ->and($fresh->headers->has('ETag'))->toBeTrue();
});

it('sends no-store, private and no ETag on a guest 401, a 429 and a CSRF 419 of the account routes, and no cookie on the throttled one', function (): void {
    Accounts::setUp();
    // Laravel skips the CSRF check while running unit tests; bind the guard as CsrfTest does, so a 419 can happen.
    app()->bind(PreventRequestForgery::class, fn (Application $app): PreventRequestForgery => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $ip = ['REMOTE_ADDR' => '192.0.2.61'];

    $unauthenticated = $this->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    for ($i = 1; $i < 60; $i++) {
        $this->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    }
    $throttled = $this->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    $noCsrf = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.62'])->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'nobody@example.com', 'password' => 'x']);

    expect($unauthenticated->getStatusCode())->toBe(401)
        ->and($unauthenticated->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($throttled->getStatusCode())->toBe(429)
        ->and($throttled->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($throttled->headers->has('ETag'))->toBeFalse()
        ->and($throttled->headers->getCookies())->toBe([])
        ->and($noCsrf->getStatusCode())->toBe(419)
        ->and($noCsrf->headers->get('Cache-Control'))->toBe('no-store, private');
});

it('sends no-store, private on a throttled forgot-password and csrf-cookie request, and leaves the public search 429 as it was', function (): void {
    Accounts::setUp();
    $ip = ['REMOTE_ADDR' => '192.0.2.63'];
    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables($ip)->postJson('/forgot-password', ['email' => 'nobody@example.com']);
    }
    $forgot = $this->withServerVariables($ip)->postJson('/forgot-password', ['email' => 'nobody@example.com']);

    for ($i = 1; $i <= 60; $i++) {
        $this->withServerVariables($ip)->get('/sanctum/csrf-cookie');
    }
    $csrf = $this->withServerVariables($ip)->get('/sanctum/csrf-cookie');

    Http::fake([T02R_BASE.'/api/search*' => Http::response(['total' => 0, 'page' => 1, 'pages' => 0, 'page_size' => 24, 'took_ms' => 1, 'results' => []])]);
    for ($i = 1; $i <= 60; $i++) {
        $this->getJson('/v1/cards?q=card'.$i)->assertOk();
    }
    $search = $this->getJson('/v1/cards?q=card');

    expect($forgot->getStatusCode())->toBe(429)
        ->and($forgot->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($csrf->getStatusCode())->toBe(429)
        ->and($csrf->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($search->getStatusCode())->toBe(429)
        ->and($search->headers->get('Cache-Control'))->toBe('no-cache, private')
        ->and($search->headers->has('ETag'))->toBeFalse();
});

/** @return ArrayObject<int, string> the verbs of every card_variants statement from now on */
function t02rVariantStatements(): ArrayObject
{
    $statements = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($statements): void {
        $sql = strtolower(ltrim($query->sql));
        if (str_contains($sql, '"card_variants"')) {
            $statements->append(strtok($sql, ' '));
        }
    });

    return $statements;
}

function t02rMiss(string $id = 'base1-4'): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([T02R_BASE.'/api/v1/cards/'.$id => Http::response(['error' => ['code' => 'not_found']], 404)]);
}

function t02rVariant(string $finish, string $status, ?string $seed = null, ?string $checked = null): void
{
    DB::table('card_variants')->insert(['variant_key' => "base1-4:{$finish}:-:-", 'card_id' => 'base1-4', 'finish' => $finish,
        'edition' => '-', 'treatment' => '-', 'catalog_status' => $status, 'catalog_seed_ref' => $seed, 'catalog_checked_at' => $checked]);
}

it('flags every variant of a card on its first miss with one SELECT and one UPDATE, and answers a repeat miss with the same stamp and one SELECT', function (): void {
    t02rVariant('holo', 'ok');
    t02rVariant('normal', 'unverified');
    t02rVariant('reverse', 'catalog_missing', 'older-seed');
    t02rMiss();
    $statements = t02rVariantStatements();

    $first = $this->getJson('/v1/cards/base1-4');
    $firstStatements = $statements->getArrayCopy();
    $stamp = DB::table('card_variants')->pluck('catalog_checked_at', 'finish')->all();
    $this->travel(61)->seconds(); // past the 60 s negative-cache window
    t02rMiss();
    $statements->exchangeArray([]);
    $second = $this->getJson('/v1/cards/base1-4');
    $secondStatements = $statements->getArrayCopy();

    expect($first->json('catalog_missing'))->toBeTrue()
        ->and($firstStatements)->toBe(['select', 'update'])
        ->and(DB::table('card_variants')->pluck('catalog_status')->unique()->all())->toBe(['catalog_missing'])
        ->and(DB::table('card_variants')->pluck('catalog_seed_ref')->unique()->all())->toBe([T02R_REF])
        ->and(collect($stamp)->filter(fn (mixed $at): bool => $at === null)->count())->toBe(0)
        ->and($second->json('catalog_missing'))->toBeTrue()
        ->and($secondStatements)->toBe(['select'])
        ->and(DB::table('card_variants')->pluck('catalog_checked_at', 'finish')->all())->toBe($stamp);
});

it('re-flags and restamps a flagged card on a repeat miss under a new seed, and writes only the rows that differ', function (): void {
    t02rVariant('holo', 'catalog_missing', T02R_REF, '2026-01-02 03:04:05+00');
    t02rVariant('normal', 'catalog_missing', 'older-seed', '2026-01-02 03:04:05+00');
    t02rVariant('reverse', 'catalog_missing', null, '2026-01-02 03:04:05+00');
    t02rMiss();
    $statements = t02rVariantStatements();

    $response = $this->getJson('/v1/cards/base1-4');
    $seen = $statements->getArrayCopy();

    $rows = DB::table('card_variants')->get()->keyBy('finish');
    expect($response->json('catalog_missing'))->toBeTrue()
        ->and($seen)->toBe(['select', 'update'])
        ->and($rows['normal']->catalog_seed_ref)->toBe(T02R_REF)
        ->and($rows['reverse']->catalog_seed_ref)->toBe(T02R_REF)
        ->and(Carbon::parse((string) $rows['holo']->catalog_checked_at)->equalTo(Carbon::parse('2026-01-02 03:04:05+00')))->toBeTrue()
        ->and(Carbon::parse((string) $rows['normal']->catalog_checked_at)->greaterThan(Carbon::parse('2026-01-02 03:04:05+00')))->toBeTrue()
        ->and(Carbon::parse((string) $rows['reverse']->catalog_checked_at)->greaterThan(Carbon::parse('2026-01-02 03:04:05+00')))->toBeTrue();
});

it('answers a miss of a card with no variant rows with one SELECT, no UPDATE and catalog_missing false, on a repeat too', function (): void {
    t02rMiss();
    $statements = t02rVariantStatements();

    $one = $this->getJson('/v1/cards/base1-4');
    $this->travel(61)->seconds();
    t02rMiss();
    $two = $this->getJson('/v1/cards/base1-4');

    expect($one->getStatusCode())->toBe(404)
        ->and($one->json('catalog_missing'))->toBeFalse()
        ->and($two->json('catalog_missing'))->toBeFalse()
        ->and($statements->getArrayCopy())->toBe(['select', 'select'])
        ->and(DB::table('card_variants')->count())->toBe(0);
});
