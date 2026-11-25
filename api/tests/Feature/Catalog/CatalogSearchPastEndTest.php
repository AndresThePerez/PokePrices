<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// A catalogue search answer whose page lies past its own last page is returned once and never stored, so the
// out-of-range read is always fresh and can never be older than the last page it points at. Every other answer is
// cached as before. The catalogue is faked as a function of a mutable result size, as PokéSearch answers: a page
// past the end is echoed as the window's last page with no rows.

const PAST_END_BASE = 'http://192.0.2.10:8081';

beforeEach(function (): void {
    config(['services.pokesearch.url' => PAST_END_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();
    CatalogCache::primeSeedRef(PAST_END_BASE);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->total = 1286;
    $this->asked = [];
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([PAST_END_BASE.'/api/search*' => function (ClientRequest $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->asked[] = (int) ($query['page'] ?? 1);

        return Http::response(pastEndAnswer($this->total, (int) ($query['page'] ?? 1)));
    }]);
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** The catalogue's answer for $page of a $total-match result, 24 a page; a page past the last is echoed (to 400, the window) with no rows. */
function pastEndAnswer(int $total, int $page): array
{
    $pages = intdiv($total + 23, 24);
    $page = $pages === 0 ? 1 : min($page, 400);
    $first = ($page - 1) * 24 + 1;
    $last = min($total, $page * 24);
    $rows = array_map(fn (int $n): array => [
        'id' => "sv1-{$n}", 'name' => "Card {$n}", 'supertype' => 'Pokémon', 'rarity' => 'Common',
        'number' => (string) $n, 'set_id' => 'sv1', 'set_name' => 'Scarlet & Violet',
        'release_date' => '2023-03-31', 'image_small' => "https://images.example.com/sv1/{$n}.png",
    ], $first > $last ? [] : range($first, $last));

    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'page_size' => 24, 'took_ms' => 3, 'results' => $rows];
}

/** The catalogue's answer for a page past the end, as the contract allows it: the page asked for, rows none. */
function pastEndEcho(int $total, int $page): array
{
    return ['total' => $total, 'page' => $page, 'pages' => intdiv($total + 23, 24), 'page_size' => 24, 'took_ms' => 3, 'results' => []];
}

function pastEndKey(object $test, string $q, int $page): string
{
    $segment = app(CatalogRepository::class)->seedRef()->segment();

    return $test->prefix."catalog:v1:search:{$segment}:".sha1("{$q}|{$page}|");
}

it('answers the last page, not 503, when the last-page entry is refreshed from a catalogue that shrank', function (): void {
    $this->getJson('/v1/cards?q=card&page=4800')->assertOk();

    // The last page's entry ends its window and is read again after the result shrank to 10 pages.
    Cache::store(CatalogRepository::STORE)->forget('catalog:v1:search:'.app(CatalogRepository::class)->seedRef()->segment().':'.sha1('card|54|'));
    $this->total = 240;
    $this->asked = [];

    $response = $this->getJson('/v1/cards?q=card&page=4800');

    $response->assertOk();
    expect($response->json('meta.page'))->toBe(10)
        ->and($response->json('meta.pages'))->toBe(10)
        ->and($response->json('data'))->toHaveCount(24)
        ->and($this->asked)->toBe([4800, 10]);
});

it('never stores an answer whose page is past its last page, and reads the catalogue again next time', function (): void {
    $repository = app(CatalogRepository::class);

    $first = $repository->search('card', 4800);
    $second = $repository->search('card', 4800);

    expect($first->data['page'])->toBe(400)
        ->and($first->data['pages'])->toBe(54)
        ->and($first->cached)->toBeFalse()
        ->and($second->cached)->toBeFalse()
        ->and($this->asked)->toBe([4800, 4800])
        ->and(Redis::connection('cache')->exists(pastEndKey($this, 'card', 4800)))->toBe(0)
        ->and(CatalogCache::keys('cache', $this->prefix))->not->toContain(pastEndKey($this, 'card', 4800));
});

it('still keeps the last-good copy of a past-the-end answer for an outage', function (): void {
    Http::fake([PAST_END_BASE.'/api/search*' => Http::response(pastEndEcho(1286, 4800))]);
    $repository = app(CatalogRepository::class);

    $repository->search('card', 4800);

    expect(Redis::connection('cache')->exists($this->prefix.'catalog:v1:lastgood:search:'.sha1('card|4800|')))->toBe(1);
});

it('caches the last page as before and serves it from the cache', function (): void {
    $repository = app(CatalogRepository::class);

    $first = $repository->search('card', 54);
    $second = $repository->search('card', 54);

    expect($first->data['page'])->toBe(54)
        ->and($first->data['pages'])->toBe(54)
        ->and($first->cached)->toBeFalse()
        ->and($second->cached)->toBeTrue()
        ->and($this->asked)->toBe([54])
        ->and(Redis::connection('cache')->exists(pastEndKey($this, 'card', 54)))->toBe(1);
});

it('caches an empty result (page 1 of 0 pages) as before', function (): void {
    $this->total = 0;
    $repository = app(CatalogRepository::class);

    $first = $repository->search('zzzzqqqq', 1);
    $second = $repository->search('zzzzqqqq', 1);

    expect($first->data['pages'])->toBe(0)
        ->and($first->data['page'])->toBe(1)
        ->and($second->cached)->toBeTrue()
        ->and($this->asked)->toBe([1])
        ->and(Redis::connection('cache')->exists(pastEndKey($this, 'zzzzqqqq', 1)))->toBe(1);
});

it('caches an in-range page with the same key and still hits it', function (): void {
    $repository = app(CatalogRepository::class);

    $repository->search('card', 3);
    $hit = $repository->search('  CARD ', 3);

    expect($hit->cached)->toBeTrue()
        ->and($this->asked)->toBe([3])
        ->and(Redis::connection('cache')->exists(pastEndKey($this, 'card', 3)))->toBe(1);
});
