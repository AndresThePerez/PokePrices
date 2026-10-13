<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;
use Tests\Support\CatalogCache;

// Edge cases of the batched card names read (plan P3-i1 T08, D10, R204): mixed cache states in one read, an id the
// batch answer accounts for in neither list, a hostile batch envelope, and a filler that waited on the fill lock.

const T08R_BASE = 'http://192.0.2.10:8081';
const T08R_REF = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

function t08rCard(string $id, string $suffix = ''): array
{
    return ['id' => $id, 'name' => "Name of {$id}{$suffix}", 'supertype' => 'Pokémon', 'number' => '9', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'release_date' => '2023-03-31'];
}

function t08rName(string $id, string $suffix = ''): array
{
    return ['name' => "Name of {$id}{$suffix}", 'set_name' => "Set of {$id}", 'number' => '9'];
}

/** The recorded batch requests' id lists, in order. */
function t08rBatches(array $calls): array
{
    $batches = [];
    foreach ($calls as $url) {
        if (parse_url($url, PHP_URL_PATH) === '/api/v1/cards') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $batches[] = explode(',', (string) $query['ids']);
        }
    }

    return $batches;
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T08R_BASE, 'services.pokesearch.api_key' => null]);
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    $this->prefix = CatalogCache::isolate();

    // id => card; unlisted ids are answered in missing. $this->batch overrides the whole batch answer as [status, body].
    $this->catalog = [];
    $this->batch = null;
    $this->calls = [];
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) {
        $this->calls[] = $request->url();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3,
                'seed' => ['ref' => T08R_REF, 'seeded_at' => '2026-10-11T20:05:45Z'], 'request_id' => 'upstream-meta']);
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

        return Http::response(['data' => $data, 'missing' => $missing, 'seed' => ['ref' => T08R_REF, 'seeded_at' => '2026-10-11T20:05:45Z']]);
    });
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('sends exactly the absent and stale ids when negative, stale, fresh and absent ids share one read, and nothing twice', function (): void {
    $this->catalog = ['s-1' => t08rCard('s-1'), 'f-1' => t08rCard('f-1'), 'a-1' => t08rCard('a-1')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['s-1']);
    $this->travel(3000)->seconds();
    $repository->cardNames(['f-1']);
    $this->travel(700)->seconds();
    $repository->cardNames(['n-1']);
    $this->catalog = array_map(fn (array $card): array => ['name' => $card['name'].' new'] + $card, $this->catalog);
    $this->calls = [];

    $mixed = $repository->cardNames(['n-1', 's-1', 'f-1', 'a-1']);
    $again = $repository->cardNames(['n-1', 's-1', 'f-1', 'a-1']);

    expect(t08rBatches($this->calls))->toBe([['a-1', 's-1']])
        ->and($mixed)->toBe(['cards' => [
            'n-1' => null, 's-1' => t08rName('s-1', ' new'), 'f-1' => t08rName('f-1'), 'a-1' => t08rName('a-1', ' new'),
        ], 'degraded' => false])
        ->and($again)->toBe($mixed);

    // Past the 60 s marker only the negative id is asked again; the refreshed ones are fresh now.
    $this->travel(61)->seconds();
    $repository->cardNames(['n-1', 's-1', 'f-1', 'a-1']);
    expect(t08rBatches($this->calls))->toBe([['a-1', 's-1'], ['n-1']]);
});

it('does not serve an id the batch omits from both lists past its hard window, and never marks it negative', function (): void {
    $this->catalog = ['x-1' => t08rCard('x-1')];
    $repository = app(CatalogRepository::class);
    $repository->cardNames(['x-1']);
    $this->batch = [200, ['data' => [], 'missing' => []]];
    $this->travel(3600)->seconds();

    $stale = $repository->cardNames(['x-1', 'y-1']);
    $this->travel(86399 - 3600)->seconds();
    $before = count(t08rBatches($this->calls));
    $lastLive = $repository->cardNames(['x-1']);
    $none = array_values(array_filter(CatalogCache::keys('cache', $this->prefix), fn (string $key): bool => str_ends_with($key, ':none')));
    $this->travel(1)->seconds();
    $gone = $repository->cardNames(['x-1']);

    expect($stale)->toBe(['cards' => ['x-1' => t08rName('x-1'), 'y-1' => null], 'degraded' => false])
        ->and($lastLive['cards'])->toBe(['x-1' => t08rName('x-1')])
        ->and(count(t08rBatches($this->calls)))->toBe($before + 1)
        ->and($none)->toBe([])
        ->and($gone)->toBe(['cards' => ['x-1' => null], 'degraded' => false]);
});

it('maps a card that PokéSearch answers under an id nobody asked for to null and caches it nowhere', function (array $answer): void {
    $this->batch = [200, ['data' => $answer, 'missing' => []]];

    $names = app(CatalogRepository::class)->cardNames(['aa-1']);

    $stored = array_values(array_filter(CatalogCache::keys('cache', $this->prefix), fn (string $key): bool => str_contains($key, 'cardname')));
    expect($names)->toBe(['cards' => ['aa-1' => null], 'degraded' => false])
        ->and($stored)->toBe([]);
})->with([
    'another id' => [[t08rCard('evil-9')]],
    'the asked id in another case' => [[t08rCard('AA-1')]],
    'an id that is not a string' => [[['id' => 1] + t08rCard('aa-1')]],
    'cards that are not objects' => [['aa-1', null, 7, [], [[]]]],
]);

it('survives non-string entries in missing and still names the good card of the same answer', function (): void {
    $this->batch = [200, ['data' => [t08rCard('aa-1')], 'missing' => [5, null, ['aa-2'], true, 'not-asked-1']]];

    $names = app(CatalogRepository::class)->cardNames(['aa-1', 'aa-2']);

    $stored = array_values(array_filter(CatalogCache::keys('cache', $this->prefix), fn (string $key): bool => str_contains($key, ':none') || str_contains($key, 'not-asked')));
    expect($names)->toBe(['cards' => ['aa-1' => t08rName('aa-1'), 'aa-2' => null], 'degraded' => false])
        ->and($stored)->toBe([]);
});

it('answers from what another filler wrote while it waited on the fill lock, with no batch request', function (string $written): void {
    $repository = app(CatalogRepository::class);
    $repository->seedRef();
    $this->calls = [];
    $key = 'catalog:v1:cardname:'.T08R_REF.':aa-1';
    $lock = Cache::store('redis')->lock('catalog:v1:fill:cardnames:'.sha1('aa-1'), 1);
    expect($lock->get())->toBeTrue();
    // The other filler lands its answer right after this read saw the id absent, and releases (expires) its lock.
    $done = false;
    Event::listen(CacheMissed::class, function (CacheMissed $event) use (&$done, $key, $written): void {
        if ($done || $event->key !== $key) {
            return;
        }
        $done = true;
        $written === 'name'
            ? Cache::store('redis')->put($key, ['data' => t08rName('aa-1', ' by the other filler'), 'fetched_at' => now()->getTimestamp()], 86400)
            : Cache::store('redis')->put("{$key}:none", now()->getTimestamp(), 60);
    });

    $answer = $repository->cardNames(['aa-1']);

    expect($done)->toBeTrue()
        ->and($answer)->toBe(['cards' => ['aa-1' => $written === 'name' ? t08rName('aa-1', ' by the other filler') : null], 'degraded' => false])
        ->and(t08rBatches($this->calls))->toBe([])
        ->and(Redis::connection('cache')->exists($this->prefix.'catalog:v1:lastgood:cardname:aa-1'))->toBe(0);
})->with([
    'a name' => ['name'],
    'a negative marker' => ['none'],
]);
