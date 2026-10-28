<?php

declare(strict_types=1);

use App\Domain\Catalog\RateLimits;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Tests\Support\CatalogCache;

// Reviewer edge tests for T44 (GET /v1/cards?q=). Every upstream call is faked; every /v1 response is also
// validated against openapi/api.json by Tests\TestCase (C3). Since T42 the search is cached on real Redis under
// a per-test prefix with the seed ref primed; a repeated q is a cached answer (meta.cached true).

const T44E_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => T44E_BASE, 'services.pokesearch.api_key' => null]);
    $this->catalogPrefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(T44E_BASE);
    Http::preventStrayRequests();
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

function t44eHit(int $n, array $override = []): array
{
    return $override + [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'number' => (string) $n, 'rarity' => 'Common', 'supertype' => 'Pokémon',
        'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet', 'release_date' => '2023-03-31',
        'image_small' => "https://images.example.com/sv1/{$n}.png",
    ];
}

function t44eBody(array $results = [], array $extra = []): array
{
    return $extra + ['total' => count($results), 'page' => 1, 'pages' => 1, 'page_size' => 24, 'results' => $results];
}

function t44eFake(array $body, int $status = 200): void
{
    // A fresh factory per call: stubs added by Http::fake accumulate and the first match wins.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([T44E_BASE.'/api/search*' => Http::response($body, $status)]);
}

/** @return list<ClientRequest> */
function t44eSent(): array
{
    return Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
}

it('does not read the rate tiers from a path outside api/, which the runtime image does not ship (R160)', function (): void {
    // docker/api/Dockerfile's runtime stage copies only api/ to /workspace/api, so dirname(base_path()) has no gates/.
    $path = RateLimits::path();

    expect(str_starts_with($path, base_path().DIRECTORY_SEPARATOR))->toBeTrue("tier file {$path} must live under ".base_path());
});

it('reaches above base_path() from no file under api/app, since the runtime image holds only api/ (R160)', function (): void {
    $hits = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && preg_match('~dirname\(\s*base_path\(\)\s*\)|base_path\(\s*[\'"]\.\./~', (string) file_get_contents($file->getPathname())) === 1) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});

it('rejects an oversized or unusual q with a 4xx problem and never a 500', function (string $query): void {
    t44eFake(t44eBody());

    $response = $this->getJson('/v1/cards?'.$query);

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json');
    Http::assertNothingSent();
})->with([
    'a 10 kB q' => fn (): string => 'q='.str_repeat('a', 10240),
    '201 multibyte characters' => fn (): string => 'q='.rawurlencode(str_repeat('é', 201)),
    'tab and newline only' => 'q=%09%0A%20',
    'page beyond int range' => 'q=a&page=99999999999999999999',
    'page with a leading zero' => 'q=a&page=01',
    'page as an array' => 'q=a&page[]=1',
    'page_size as an array' => 'q=a&page_size[]=5',
    'an upper-case PAGE' => 'q=a&PAGE=2',
    'a debug parameter alongside valid ones' => 'q=a&page=1&page_size=5&debug=1',
]);

it('forwards a q with accents, plus, ampersand, question mark and bang as one correctly encoded value', function (): void {
    t44eFake(t44eBody([t44eHit(1)]));
    $q = 'é+&?! #=';

    $this->getJson('/v1/cards?q='.rawurlencode($q))->assertOk();

    $sent = t44eSent();
    expect($sent)->toHaveCount(1);
    parse_str((string) parse_url($sent[0]->url(), PHP_URL_QUERY), $query);
    expect($query)->toBe(['q' => $q])
        ->and(substr_count((string) parse_url($sent[0]->url(), PHP_URL_QUERY), '&'))->toBe(substr_count(rawurlencode($q), '&'));
});

it('does not 500 on a q carrying a NUL byte', function (): void {
    t44eFake(t44eBody([t44eHit(1)]));

    $response = $this->getJson('/v1/cards?q=a%00b');

    expect($response->getStatusCode())->toBeLessThan(500);
});

it('forwards nothing but q, page and page_size even for a valid request carrying a hostile header', function (): void {
    t44eFake(t44eBody([t44eHit(1)]));

    $this->withHeaders(['X-Debug' => '1', 'X-Forwarded-Host' => 'evil.example'])->getJson('/v1/cards?q=a&page=2&page_size=3')->assertOk();

    $sent = t44eSent();
    parse_str((string) parse_url($sent[0]->url(), PHP_URL_QUERY), $query);
    expect(array_keys($query))->toBe(['q', 'page', 'page_size']);
});

it('drops every upstream extra, including a junk top-level key and a junk key on a hit', function (): void {
    $hit = t44eHit(1, ['highlights' => ['name' => ['<mark>x</mark>']], 'matched' => ['name_exact'], 'junk_hit_key' => 'SENTINEL_HIT']);
    t44eFake(t44eBody([$hit], ['took_ms' => 4, 'facets' => ['a' => 1], 'dsl' => ['q' => 1], 'junk_top_key' => 'SENTINEL_TOP', 'request_id' => 'upstream-req-1', 'generated_at' => '2026-10-12T00:00:00Z']));

    $response = $this->getJson('/v1/cards?q=a');

    $response->assertOk();
    $body = (string) $response->getContent();
    foreach (['SENTINEL_HIT', 'SENTINEL_TOP', 'highlights', 'matched', 'facets', 'dsl', 'took_ms', 'request_id', 'upstream-req-1', 'generated_at', '2026-10-12T'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});

it('answers 502 upstream-contract when a hit lacks image_small, and null rarity when rarity is absent', function (): void {
    t44eFake(t44eBody([array_diff_key(t44eHit(1), ['image_small' => true])]));
    $missing = $this->getJson('/v1/cards?q=a');

    t44eFake(t44eBody([array_diff_key(t44eHit(1), ['rarity' => true])]));
    $absent = $this->getJson('/v1/cards?q=a');

    expect($missing->getStatusCode())->toBe(502)
        ->and($missing->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-contract')
        ->and($absent->getStatusCode())->toBe(200)
        ->and($absent->json('data.0.rarity'))->toBeNull();
});

it('passes pages through even when it contradicts total and page_size, and when the page is past the last', function (): void {
    t44eFake(t44eBody([t44eHit(1)], ['total' => 5, 'page' => 9, 'pages' => 77, 'page_size' => 1]));

    $response = $this->getJson('/v1/cards?q=a&page=9&page_size=1');

    expect($response->json('meta'))->toBe(['total' => 5, 'page' => 9, 'pages' => 77, 'page_size' => 1, 'degraded' => false, 'cached' => false]);
});

it('keeps the ETag deterministic across requests and changes it with the page', function (): void {
    t44eFake(t44eBody([t44eHit(1)], ['page' => 1]));
    $this->getJson('/v1/cards?q=a')->assertOk(); // T42: warm the cache, so a and b are both cached answers
    $a = $this->withHeader('X-Request-Id', 'edge-req-000001')->getJson('/v1/cards?q=a');
    $b = $this->withHeader('X-Request-Id', 'edge-req-000002')->getJson('/v1/cards?q=a');
    t44eFake(t44eBody([t44eHit(2)], ['page' => 2]));
    $this->getJson('/v1/cards?q=a&page=2')->assertOk(); // T42: warm page 2 too, so only the page differs from a
    $c = $this->getJson('/v1/cards?q=a&page=2');

    expect($a->headers->get('ETag'))->toBe($b->headers->get('ETag'))
        ->and($c->headers->get('ETag'))->not->toBe($a->headers->get('ETag'))
        ->and((string) $a->getContent())->not->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/')
        ->and((string) $a->getContent())->not->toContain('request_id');
});

it('treats If-None-Match * as a match, a weak copy of the tag as a match, and a wrong tag as a miss', function (): void {
    t44eFake(t44eBody([t44eHit(1)]));
    $this->getJson('/v1/cards?q=a')->assertOk(); // T42: the cached answer (meta.cached true) is the one revalidated
    $etag = (string) $this->getJson('/v1/cards?q=a')->headers->get('ETag');

    $star = $this->withHeader('If-None-Match', '*')->getJson('/v1/cards?q=a');
    $weak = $this->withHeader('If-None-Match', 'W/'.$etag)->getJson('/v1/cards?q=a');
    $wrong = $this->withHeader('If-None-Match', '"deadbeef"')->getJson('/v1/cards?q=a');
    $right = $this->withHeader('If-None-Match', $etag)->getJson('/v1/cards?q=a');

    expect($wrong->getStatusCode())->toBe(200)
        ->and($right->getStatusCode())->toBe(304)
        ->and($right->getContent())->toBe('')
        ->and($right->headers->get('ETag'))->toBe($etag)
        ->and($weak->getStatusCode())->toBe(304)
        ->and($star->getStatusCode())->toBe(304);
});

it('answers HEAD with the headers of the GET, no body, and 304 on a matching tag', function (): void {
    t44eFake(t44eBody([t44eHit(1)]));
    $this->getJson('/v1/cards?q=a')->assertOk(); // T42: warm the cache, so GET and HEAD carry the same cached answer
    $get = $this->getJson('/v1/cards?q=a');

    $head = $this->call('HEAD', '/v1/cards?q=a');
    $headMatch = $this->call('HEAD', '/v1/cards?q=a', server: ['HTTP_IF_NONE_MATCH' => (string) $get->headers->get('ETag')]);

    expect($head->getStatusCode())->toBe(200)
        ->and($head->getContent())->toBe('')
        ->and($head->headers->get('ETag'))->toBe($get->headers->get('ETag'))
        ->and($headMatch->getStatusCode())->toBe(304);
});

it('sends the cache policy only on the 200, never on a 422, 429 or 502', function (): void {
    t44eFake(t44eBody([t44eHit(1)]));
    $ok = $this->getJson('/v1/cards?q=a');
    $invalid = $this->getJson('/v1/cards');
    t44eFake(['nope' => true]);
    $bad = $this->getJson('/v1/cards?q=b'); // T42: q=a is cached by now; a new q reaches the broken upstream

    expect((string) $ok->headers->get('Cache-Control'))->toContain('s-maxage=300');
    foreach ([$invalid, $bad] as $error) {
        expect($error->headers->has('ETag'))->toBeFalse()
            ->and((string) $error->headers->get('Cache-Control'))->not->toContain('public')
            ->and((string) $error->headers->get('Cache-Control'))->not->toContain('s-maxage');
    }
});

it('answers 503 upstream-unavailable with Retry-After when PokéSearch times out', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $response = $this->getJson('/v1/cards?q=a');

    expect($response->getStatusCode())->toBe(503)
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-unavailable')
        ->and((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((string) $response->getContent())->not->toContain('cURL');
});

it('answers 502 upstream-contract, not a 500, when PokéSearch answers 200 with a body that is not JSON', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([T44E_BASE.'/api/search*' => Http::response('<html>proxy error</html>', 200, ['Content-Type' => 'text/html'])]);

    $response = $this->getJson('/v1/cards?q=a');

    expect($response->getStatusCode())->toBe(502)
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/upstream-contract')
        ->and((string) $response->getContent())->not->toContain('proxy error');
});

it('never throttles /v1/ready, however many probes arrive', function (): void {
    for ($i = 0; $i < 130; $i++) {
        $status = $this->get('/v1/ready')->getStatusCode();
        if ($status === 429) {
            break;
        }
    }

    expect($status)->not->toBe(429)->and($i)->toBe(130);
});

it('counts invalid and upstream-failed searches against the limit and still names too-many-requests on the 61st', function (): void {
    t44eFake(['nope' => true]);
    for ($i = 1; $i <= 60; $i++) {
        $this->getJson('/v1/cards?q=a');
    }

    $response = $this->getJson('/v1/cards?q=a');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->json('type'))->toBe('https://pokeprices.andrestheperez.com/problems/too-many-requests')
        ->and($response->headers->has('Retry-After'))->toBeTrue();
});

it('documents the problem+json Problem reference on 422, 429, 502 and 503, and Retry-After on 429 and 503', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $responses = $spec['paths']['/cards']['get']['responses'];

    foreach (['422', '429', '502', '503'] as $status) {
        expect($responses[$status]['content']['application/problem+json']['schema']['$ref'] ?? null)->toBe('#/components/schemas/Problem');
    }
    expect($responses['429']['headers'])->toHaveKey('Retry-After')
        ->and($responses['503']['headers'])->toHaveKey('Retry-After')
        ->and($responses['304']['headers'])->toHaveKey('ETag')
        ->and($spec['components']['schemas']['Problem']['additionalProperties'] ?? null)->not->toBeNull();
});
