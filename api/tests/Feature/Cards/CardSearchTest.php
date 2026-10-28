<?php

declare(strict_types=1);

use App\Domain\Catalog\RateLimits;
use App\Http\CachePolicy;
use App\Http\Middleware\ConditionalGet;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\CatalogCache;

// GET /v1/cards?q= proxies PokéSearch's /api/search (plan T44, R159). Every upstream call is faked; every
// /v1 response below is also validated against openapi/api.json by Tests\TestCase (C3). Since T42 the search
// goes through the catalog cache on real Redis: each test gets its own key prefix and a cached seed ref, so
// the first search of a q is an upstream call and a repeat is served from the cache (meta.cached true).

const T44_BASE = 'http://192.0.2.10:8081';

const T44_SUMMARY_KEYS = ['id', 'name', 'number', 'rarity', 'supertype', 'set_id', 'set_name', 'release_date', 'image_small'];

beforeEach(function (): void {
    config(['services.pokesearch.url' => T44_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T44_BASE);
    Http::preventStrayRequests();

    // The application's limiter (on the redis-queue "locks" store) is kept for the registration assertions; the
    // requests below count on a fresh in-memory limiter, which gets the tiers the same way, so a test never
    // inherits another run's counters.
    $this->bootLimiter = app(RateLimiter::class);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** One PokéSearch search hit: the fields PokéPrices maps plus some it must drop. */
function t44Hit(int $n): array
{
    return [
        'id' => "sv1-{$n}",
        'name' => "Card {$n}",
        'supertype' => 'Pokémon',
        'subtypes' => ['Basic'],
        'hp' => 70,
        'types' => ['Lightning'],
        'artist' => 'Example Artist',
        'rarity' => 'Common',
        'number' => (string) $n,
        'set_id' => 'sv1',
        'set_name' => 'Scarlet & Violet',
        'set_series' => 'Scarlet & Violet',
        'set_total' => 258,
        'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/sv1/{$n}.png",
        'image_large' => "https://images.example.com/sv1/{$n}_hires.png",
    ];
}

/** A PokéSearch /api/search answer with $count hits, carrying every field PokéPrices must never pass on. */
function t44SearchBody(int $count, int $total = 20000, int $page = 1, int $pages = 400, int $pageSize = 24): array
{
    $results = [];
    for ($n = 1; $n <= $count; $n++) {
        $results[] = t44Hit($n);
    }

    return [
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'page_size' => $pageSize,
        'took_ms' => 17,
        'results' => $results,
        'matched' => array_fill(0, $count, ['name_exact']),
        'highlights' => array_fill(0, $count, ['name' => ['<mark>Card</mark>']]),
        'did_you_mean' => 'card',
        'facets' => ['rarity' => [['value' => 'Common', 'count' => 3]]],
        'dsl' => ['query' => ['match_all' => (object) []]],
    ];
}

function t44FakeSearch(array $body, int $status = 200): void
{
    Http::fake([T44_BASE.'/api/search*' => Http::response($body, $status)]);
}

/** The single request the fake recorded for this test. */
function t44OnlyUpstreamRequest(): ClientRequest
{
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    return $recorded->first()[0];
}

/** @return array<string, string> the upstream request's query string, decoded */
function t44UpstreamQuery(ClientRequest $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/** @return list<string> the Cache-Control directives, sorted (Symfony normalises their order) */
function t44CacheDirectives(TestResponse $response): array
{
    $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    sort($directives);

    return $directives;
}

it('maps the PokéSearch answer to data summaries and meta, passing pages through unchanged', function (): void {
    // 20000 / 24 would be 834 pages; PokéSearch caps at its 9,600-document window and says 400.
    t44FakeSearch(t44SearchBody(2, total: 20000, page: 3, pages: 400, pageSize: 24));

    $response = $this->getJson('/v1/cards?q=card&page=3');

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [
            [
                'id' => 'sv1-1', 'name' => 'Card 1', 'number' => '1', 'rarity' => 'Common', 'supertype' => 'Pokémon',
                'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'release_date' => '2023-03-31',
                'image_small' => 'https://images.example.com/sv1/1.png',
            ],
            [
                'id' => 'sv1-2', 'name' => 'Card 2', 'number' => '2', 'rarity' => 'Common', 'supertype' => 'Pokémon',
                'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'release_date' => '2023-03-31',
                'image_small' => 'https://images.example.com/sv1/2.png',
            ],
        ],
        'meta' => ['total' => 20000, 'page' => 3, 'pages' => 400, 'page_size' => 24, 'degraded' => false, 'cached' => false],
    ]);
});

it('never passes on highlights, matched, facets, dsl, took_ms or any other upstream extra', function (): void {
    t44FakeSearch(t44SearchBody(3));

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    $body = (string) $response->getContent();
    foreach (['highlights', 'matched', 'facets', 'dsl', 'took_ms', 'did_you_mean', 'request_id', 'image_large', 'set_series', 'subtypes', 'artist', '<mark>'] as $leak) {
        expect($body)->not->toContain($leak);
    }
    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('data.0')))->toBe(T44_SUMMARY_KEYS)
        ->and(array_keys($response->json('meta')))->toBe(['total', 'page', 'pages', 'page_size', 'degraded', 'cached']);
});

it('answers rarity null when PokéSearch omits it, as it does for cards without a rarity', function (): void {
    $body = t44SearchBody(1);
    unset($body['results'][0]['rarity']);
    t44FakeSearch($body);

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data.0'))->toHaveKey('rarity')
        ->and($response->json('data.0.rarity'))->toBeNull();
});

it('answers an empty data list for a search with no hits', function (): void {
    t44FakeSearch(t44SearchBody(0, total: 0, page: 1, pages: 0, pageSize: 24));

    $response = $this->getJson('/v1/cards?q=zzzz');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta'))->toBe(['total' => 0, 'page' => 1, 'pages' => 0, 'page_size' => 24, 'degraded' => false, 'cached' => false]);
});

it('forwards exactly q, page and page_size to GET /api/search', function (): void {
    t44FakeSearch(t44SearchBody(1, page: 2, pageSize: 10));

    $this->getJson('/v1/cards?q=pika%20chu&page=2&page_size=10')->assertOk();

    $request = t44OnlyUpstreamRequest();
    expect($request->method())->toBe('GET')
        ->and(parse_url($request->url(), PHP_URL_PATH))->toBe('/api/search')
        ->and(str_starts_with($request->url(), T44_BASE.'/api/search?'))->toBeTrue()
        ->and(t44UpstreamQuery($request))->toBe(['q' => 'pika chu', 'page' => '2', 'page_size' => '10']);
});

it('forwards only q when page and page_size are absent', function (): void {
    t44FakeSearch(t44SearchBody(1));

    $this->getJson('/v1/cards?q=charizard')->assertOk();

    expect(t44UpstreamQuery(t44OnlyUpstreamRequest()))->toBe(['q' => 'charizard']);
});

it('accepts the boundaries of q, page and page_size', function (string $query): void {
    t44FakeSearch(t44SearchBody(1));

    $this->getJson('/v1/cards?'.$query)->assertOk();

    Http::assertSentCount(1);
})->with([
    'q of 1 character' => 'q=a',
    'q of 200 characters' => 'q='.str_repeat('a', 200),
    'q of 200 multibyte characters' => 'q='.rawurlencode(str_repeat('é', 200)),
    'page 1' => 'q=a&page=1',
    'page_size 1' => 'q=a&page_size=1',
    'page_size 100' => 'q=a&page_size=100',
]);

it('rejects anything but q (1-200), page (>= 1) and page_size (1-100) with a 422 problem and sends nothing upstream', function (string $query, string $field): void {
    t44FakeSearch(t44SearchBody(1));

    $response = $this->getJson('/v1/cards'.($query === '' ? '' : '?'.$query));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/validation-failed')
        ->and($response->json('status'))->toBe(422)
        ->and($response->json('errors'))->toHaveKey($field)
        ->and($response->headers->has('ETag'))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'q missing' => ['', 'q'],
    'q empty' => ['q=', 'q'],
    'q blank' => ['q=%20%20', 'q'],
    'q of 201 characters' => ['q='.str_repeat('a', 201), 'q'],
    'q as an array' => ['q[]=pikachu', 'q'],
    'page 0' => ['q=a&page=0', 'page'],
    'page negative' => ['q=a&page=-1', 'page'],
    'page not a number' => ['q=a&page=abc', 'page'],
    'page fractional' => ['q=a&page=1.5', 'page'],
    'page_size 0' => ['q=a&page_size=0', 'page_size'],
    'page_size 101' => ['q=a&page_size=101', 'page_size'],
    'page_size not a number' => ['q=a&page_size=ten', 'page_size'],
    'debug' => ['q=a&debug=1', 'debug'],
    'an unknown parameter' => ['q=a&sort=name', 'sort'],
    'an upstream filter' => ['q=a&set=sv1', 'set'],
]);

it('answers the 503 upstream-unavailable problem with Retry-After when PokéSearch fails', function (): void {
    Http::fake([T44_BASE.'/api/search*' => Http::response(['error' => 'es down'], 503)]);

    $response = $this->getJson('/v1/cards?q=card');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('answers the 502 upstream-contract problem when PokéSearch answers outside its contract', function (int $status, mixed $body): void {
    Http::fake([T44_BASE.'/api/search*' => Http::response($body, $status)]);

    $response = $this->getJson('/v1/cards?q=card');

    expect($response->getStatusCode())->toBe(502)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-contract')
        ->and($response->headers->has('ETag'))->toBeFalse();
})->with([
    'a 400' => [400, ['error' => ['code' => 'invalid_param']]],
    'a list body' => [200, [1, 2]],
    'no results' => [200, array_diff_key(t44SearchBody(1), ['results' => true])],
    'results not a list' => [200, ['results' => ['a' => t44Hit(1)]] + t44SearchBody(1)],
    'pages missing' => [200, array_diff_key(t44SearchBody(1), ['pages' => true])],
    'total not an integer' => [200, ['total' => '12'] + t44SearchBody(1)],
    'a hit without an id' => [200, ['results' => [array_diff_key(t44Hit(1), ['id' => true])]] + t44SearchBody(1)],
    'a hit with a numeric name' => [200, ['results' => [['name' => 7] + t44Hit(1)]] + t44SearchBody(1)],
    'a hit with a non-string rarity' => [200, ['results' => [['rarity' => ['x']] + t44Hit(1)]] + t44SearchBody(1)],
]);

it('sends a strong body-hash ETag and the public cache policy on a 200', function (): void {
    t44FakeSearch(t44SearchBody(2));

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->headers->get('ETag'))->toBe('"'.hash('xxh128', (string) $response->getContent()).'"')
        ->and(t44CacheDirectives($response))->toBe(['max-age=60', 'public', 's-maxage=300']);
});

it('gives the same ETag to the same answer whatever the request id', function (): void {
    t44FakeSearch(t44SearchBody(2));
    $this->getJson('/v1/cards?q=card')->assertOk(); // T42: warm the cache, so both reads below are cached answers

    $first = $this->withHeader('X-Request-Id', 'req-t44-etag-0001')->getJson('/v1/cards?q=card');
    $second = $this->withHeader('X-Request-Id', 'req-t44-etag-0002')->getJson('/v1/cards?q=card');

    expect($first->headers->get('ETag'))->not->toBeNull()
        ->and($second->headers->get('ETag'))->toBe($first->headers->get('ETag'))
        ->and($second->getContent())->toBe($first->getContent());
});

it('answers 304 with the ETag and no body when If-None-Match matches', function (): void {
    t44FakeSearch(t44SearchBody(2));
    $this->getJson('/v1/cards?q=card')->assertOk(); // T42: the cached answer (meta.cached true) is the one revalidated
    $etag = (string) $this->getJson('/v1/cards?q=card')->headers->get('ETag');

    $response = $this->withHeader('If-None-Match', $etag)->getJson('/v1/cards?q=card');

    expect($response->getStatusCode())->toBe(304)
        ->and($response->getContent())->toBe('')
        ->and($response->headers->get('ETag'))->toBe($etag)
        ->and(t44CacheDirectives($response))->toBe(['max-age=60', 'public', 's-maxage=300']);
});

it('answers 304 when the matching validator is one of several in If-None-Match', function (): void {
    t44FakeSearch(t44SearchBody(2));
    $this->getJson('/v1/cards?q=card')->assertOk(); // T42: the cached answer (meta.cached true) is the one revalidated
    $etag = (string) $this->getJson('/v1/cards?q=card')->headers->get('ETag');

    $response = $this->withHeader('If-None-Match', '"0000", '.$etag)->getJson('/v1/cards?q=card');

    expect($response->getStatusCode())->toBe(304);
});

it('answers 200 with the body when If-None-Match does not match', function (): void {
    t44FakeSearch(t44SearchBody(2));

    $response = $this->withHeader('If-None-Match', '"00000000000000000000000000000000"')->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('sends no-store and no ETag on a degraded body and never answers it 304', function (): void {
    // The route marks its answer degraded the way the controllers do (Q1-i5 T02): conditional-get reads the request
    // attribute, not the body.
    Route::middleware(ConditionalGet::class)->get('/v1/__t44_degraded', function (Request $request): array {
        $request->attributes->set(CachePolicy::DEGRADED, true);

        return ['data' => [], 'meta' => ['total' => 0, 'page' => 1, 'pages' => 0, 'page_size' => 24, 'degraded' => true, 'cached' => true]];
    });
    $first = $this->getJson('/v1/__t44_degraded');
    $hash = '"'.hash('xxh128', (string) $first->getContent()).'"';

    $second = $this->withHeader('If-None-Match', $hash)->getJson('/v1/__t44_degraded');

    expect($first->getStatusCode())->toBe(200)
        ->and($first->headers->has('ETag'))->toBeFalse()
        ->and(t44CacheDirectives($first))->toContain('no-store')
        ->and(t44CacheDirectives($first))->not->toContain('public')
        ->and((string) $first->headers->get('Cache-Control'))->not->toContain('max-age')
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->headers->has('ETag'))->toBeFalse()
        ->and($second->json('meta.degraded'))->toBeTrue();
});

it('tags a 200 but leaves an error from the same route without an ETag or the public cache policy', function (): void {
    Route::middleware(ConditionalGet::class)->get('/v1/__t44_error', fn (Request $request): array => $request->validate(['x' => 'required']));

    $ok = $this->getJson('/v1/__t44_error?x=1');
    $error = $this->getJson('/v1/__t44_error');

    expect($ok->getStatusCode())->toBe(200)
        ->and($ok->headers->has('ETag'))->toBeTrue()
        ->and($error->getStatusCode())->toBe(422)
        ->and($error->headers->has('ETag'))->toBeFalse()
        ->and(t44CacheDirectives($error))->not->toContain('public');
    $stale = $this->withHeader('If-None-Match', (string) $ok->headers->get('ETag'))->getJson('/v1/__t44_error');
    expect($stale->getStatusCode())->toBe(422);
});

it('registers the search and public-read limiters at boot from config/rate_limits.php, which ships inside api/', function (): void {
    $tiers = config('rate_limits');
    $request = Request::create('/v1/cards', 'GET', server: ['REMOTE_ADDR' => '192.0.2.30']);

    expect($tiers)->toBe([
        'public-read' => ['per_minute' => 120, 'by' => 'ip'],
        'search' => ['per_minute' => 60, 'by' => 'ip'],
    ])
        ->and(RateLimits::path())->toBe(base_path('config/rate_limits.php'))
        ->and(is_file(RateLimits::path()))->toBeTrue();
    foreach ($tiers as $name => $tier) {
        $limiter = $this->bootLimiter->limiter($name);
        expect($limiter)->toBeInstanceOf(Closure::class);
        $limit = $limiter($request);
        expect($limit)->toBeInstanceOf(Limit::class)
            ->and($limit->maxAttempts)->toBe($tier['per_minute'])
            ->and($limit->decaySeconds)->toBe(60)
            ->and($limit->key)->toBe('192.0.2.30');
    }
})->group('S8');

it('fails the boot on a malformed tier rather than running unthrottled', function (mixed $config): void {
    expect(fn () => RateLimits::tiers($config))->toThrow(RuntimeException::class);
})->with([
    'no tiers' => [null],
    'an empty map' => [[]],
    'a list' => [[['per_minute' => 60, 'by' => 'ip']]],
    'per_minute 0' => [['search' => ['per_minute' => 0, 'by' => 'ip']]],
    'per_minute as a string' => [['search' => ['per_minute' => '60', 'by' => 'ip']]],
    'keyed by something other than ip' => [['search' => ['per_minute' => 60, 'by' => 'user']]],
    'an extra field' => [['search' => ['per_minute' => 60, 'by' => 'ip', 'burst' => 5]]],
    'a tier that is not a map' => [['search' => 60]],
])->group('S8');

it('puts throttle:search, conditional-get and the PokéSearch tag on the search route and no throttle on /v1/ready', function (): void {
    $search = Route::getRoutes()->match(Request::create('/v1/cards', 'GET'));
    $ready = Route::getRoutes()->match(Request::create('/v1/ready', 'GET'));

    expect($search->gatherMiddleware())->toContain('throttle:search')
        ->and($search->gatherMiddleware())->toContain('conditional-get')
        ->and($search->getAction('pokesearch'))->toBeTrue()
        ->and(array_filter($ready->gatherMiddleware(), fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'throttle')))->toBe([]);
})->group('S8');

it('answers the 61st search in a minute from one IP with the 429 problem and Retry-After', function (): void {
    t44FakeSearch(t44SearchBody(1));

    // T42: a distinct q per request, so each is a cache miss and still one upstream call.
    for ($i = 1; $i <= 60; $i++) {
        $this->getJson('/v1/cards?q=card'.$i)->assertOk();
    }
    $response = $this->getJson('/v1/cards?q=card');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/too-many-requests')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $response->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($response->headers->has('ETag'))->toBeFalse();
    Http::assertSentCount(60);

    $other = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.31'])->getJson('/v1/cards?q=card');
    expect($other->getStatusCode())->toBe(200);
})->group('S8');

it('makes exactly one PokéSearch call per search, for 1 and for 24 results', function (int $count): void {
    t44FakeSearch(t44SearchBody($count));

    $response = $this->getJson('/v1/cards?q=card');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount($count);
    Http::assertSentCount(1);
})->with([1, 24])->group('P8');

it('documents GET /cards with closed summary and meta schemas and the 304, 422, 429, 502 and 503 answers', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/cards']['get'] ?? null;

    expect($operation)->not->toBeNull()
        ->and(array_map('strval', array_keys($operation['responses'])))->toBe(['200', '304', '422', '429', '502', '503'])
        ->and($spec['components']['schemas'])->toHaveKey('Problem');
    $body = $operation['responses']['200']['content']['application/json']['schema'];
    $body = isset($body['$ref']) ? $spec['components']['schemas'][basename($body['$ref'])] : $body;
    $item = $body['properties']['data']['items'];
    $item = isset($item['$ref']) ? $spec['components']['schemas'][basename($item['$ref'])] : $item;
    expect($body['additionalProperties'])->toBeFalse()
        ->and($item['additionalProperties'])->toBeFalse()
        ->and(array_keys($item['properties']))->toBe(T44_SUMMARY_KEYS)
        ->and($body['properties']['meta']['additionalProperties'])->toBeFalse()
        ->and(array_keys($body['properties']['meta']['properties']))->toBe(['total', 'page', 'pages', 'page_size', 'degraded', 'cached'])
        ->and(array_column($operation['parameters'], 'name'))->toBe(['q', 'page', 'page_size']);
});
