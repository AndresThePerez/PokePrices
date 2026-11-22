<?php

declare(strict_types=1);

use App\Domain\Catalog\CatalogRepository;
use App\Domain\Catalog\VerifyCatalog;
use App\Http\Problems\ProblemDetails;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Edge cases of the card's small image on the three list answers (GET /v1/movers, /v1/watchlist, /v1/portfolio):
// an unusual or hostile image string is carried exactly as the search answer already carries the same field (the
// lists are never weaker than search), no other catalog field reaches the card block, a cached entry of any shape
// reads without an error, a picture the catalog dropped does not survive a refetch, the touched routes refuse bad
// input as problem+json, and one account's writes never reach another account's lists. Every /v1 response is also
// validated against openapi/api.json by Tests\TestCase (C3).

const RIE_BASE = 'http://192.0.2.10:8081';
const RIE_REF = 'dddddddddddddddddddddddddddddddddddddddd';

/** The batch card of $id; true gives the default image, false leaves image_small out, anything else is sent as it is. */
function rieCard(string $id, mixed $image = true, array $extra = []): array
{
    $card = ['id' => $id, 'name' => "Name of {$id}", 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'set_series' => 'Base', 'release_date' => '1999-01-09',
        'image_large' => 'https://images.example.com/large.png'] + $extra;
    if ($image !== false) {
        $card['image_small'] = $image === true ? 'https://images.example.com/'.rawurlencode($id).'.png' : $image;
    }

    return $card;
}

/** The search hit of $id with $image, as PokéSearch's /api/search carries it. */
function rieHit(string $id, string $image): array
{
    return ['id' => $id, 'name' => "Name of {$id}", 'number' => '4', 'supertype' => 'Pokémon', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'release_date' => '1999-01-09', 'image_small' => $image];
}

/** Puts each card id on the movers board (up), $user's watchlist and $user's portfolio, priced on 2026-10-11. */
function rieRows($user, array $cards): void
{
    foreach (array_values($cards) as $i => $card) {
        $key = "{$card}:holo:-:-";
        DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo',
            'edition' => '-', 'treatment' => '-', 'catalog_status' => 'unverified']);
        DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => $i + 1,
            'variant_key' => $key, 'card_id' => $card, 'series_epoch' => 1, 'close' => '11.00', 'previous_close' => '10.00',
            'previous_day' => '2026-10-10', 'change' => '1.00', 'change_pct' => '10.00', 'as_of' => '2026-10-11 20:05:45+00']);
        AccountRows::price($key, '11.00', '2026-10-11', '10.00', '2026-10-10', '1.00', '10.00');
        AccountRows::watch($user, $key);
        AccountRows::hold($user, $key, 2);
    }
}

/** @return array<string, TestResponse> the three answers as $user sees them */
function rieLists(object $test, $user): array
{
    return [
        'movers' => $test->getJson('/v1/movers?direction=up'),
        'watchlist' => AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist'),
        'portfolio' => AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio'),
    ];
}

/** @return array<string, mixed> card_id => card block of every row */
function rieCards(TestResponse $response): array
{
    $cards = [];
    foreach ($response->json('data') as $row) {
        $cards[$row['card_id']] = $row['card'];
    }

    return $cards;
}

/** The number of batch name requests recorded so far. */
function rieBatchCount(): int
{
    return Http::recorded(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards')->count();
}

/** Writes a fresh cached name entry of $data for $id under the key the read uses. */
function riePutName(string $id, array $data): void
{
    $segment = app(CatalogRepository::class)->seedRef()->segment();
    Cache::store(CatalogRepository::STORE)->put("catalog:v1:cardname:{$segment}:{$id}", ['data' => $data, 'fetched_at' => now()->getTimestamp()], 86400);
}

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    Queue::fake([VerifyCatalog::class]);
    config(['services.pokesearch.url' => RIE_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();

    $this->catalog = [];
    $this->hits = [];
    $this->down = false;
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => RIE_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }
        if ($this->down) {
            return Http::response(['error' => 'down'], 503);
        }
        if ($path === '/api/search') {
            return Http::response(['total' => count($this->hits), 'page' => 1, 'pages' => 1, 'page_size' => 24, 'results' => $this->hits, 'seed' => $seed]);
        }
        if ($path !== '/api/v1/cards') {
            return Http::response(['error' => 'unrouted'], 599);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = [];
        $missing = [];
        foreach (explode(',', (string) $query['ids']) as $id) {
            array_key_exists($id, $this->catalog) ? $data[] = $this->catalog[$id] : $missing[] = $id;
        }

        return Http::response(['data' => $data, 'missing' => $missing, 'seed' => $seed]);
    });
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('carries an unusual image string on every list exactly as the search answer carries the same field, never weaker', function (string $image): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => rieCard('base1-4', $image)];
    $this->hits = [rieHit('base1-4', $image)];
    rieRows($user, ['base1-4']);

    $search = $this->getJson('/v1/cards?q=name');
    $search->assertOk();
    $searched = $search->json('data.0.image_small');
    expect($searched)->toBe($image);

    foreach (rieLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect($response->json('data.0.card.image_small'))->toBe($searched, $list)
            ->and($response->json('meta.degraded'))->toBeFalse($list)
            ->and((string) $response->headers->get('Content-Type'))->toStartWith('application/json');
    }
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'data html' => ['data:text/html,<script>alert(1)</script>'],
    'plain http' => ['http://images.example.com/x.png'],
    'protocol-relative' => ['//images.example.net/x.png'],
    'inner spaces and a newline' => ["https://images.example.com/a b\n.png"],
    'ten thousand characters' => ['https://images.example.com/'.str_repeat('a', 10000 - 27)],
    'a foreign host' => ['https://tracker.example.org/pixel.png?u=1'],
    'html' => ['"><img src=x onerror=alert(1)>'],
]);

it('keeps every other catalog field out of the card block: exactly name, set_name, number and image_small', function (): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => rieCard('base1-4', true, [
        'rarity' => 'Rare Holo', 'artist' => 'Example Artist', 'subtypes' => ['Basic'], 'hp' => 60,
        'image_small_2x' => 'https://images.example.com/2x.png', 'tracking' => 'fake-sentinel-0000',
    ])];
    rieRows($user, ['base1-4']);

    foreach (rieLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect(array_keys($response->json('data.0.card')))->toBe(['name', 'set_name', 'number', 'image_small'], $list)
            ->and((string) $response->getContent())->not->toContain('fake-sentinel-0000')
            ->and((string) $response->getContent())->not->toContain('large.png')
            ->and((string) $response->getContent())->not->toContain('Example Artist');
    }
});

it('reads a cached name whose image_small is of any wrong kind without an error, a request or the field', function (mixed $image): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => rieCard('base1-4')];
    rieRows($user, ['base1-4']);
    riePutName('base1-4', ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4', 'image_small' => $image]);

    foreach (rieLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect($response->json('data.0.card'))->toBe(['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'], $list)
            ->and($response->json('meta.degraded'))->toBeFalse($list);
    }
    expect(rieBatchCount())->toBe(0);
})->with([
    'null' => [null],
    'empty' => [''],
    'whitespace' => [" \t\n"],
    'a number' => [7],
    'a boolean' => [true],
    'a list' => [['https://images.example.com/x.png']],
    'an object' => [['url' => 'https://images.example.com/x.png']],
]);

it('carries a cached new-shape image beside an old-shape name and a card the catalog does not have, in one batch', function (): void {
    $user = Accounts::user();
    $this->catalog = ['sv1-1' => rieCard('sv1-1')];
    rieRows($user, ['base1-4', 'ex5-17', 'sv1-1', 'zz9-9']);
    riePutName('base1-4', ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4']);
    riePutName('ex5-17', ['name' => 'Name of ex5-17', 'set_name' => 'Set of ex5-17', 'number' => '4', 'image_small' => 'https://images.example.com/cached.png']);

    foreach (rieLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect(rieCards($response))->toBe([
            'base1-4' => ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'],
            'ex5-17' => ['name' => 'Name of ex5-17', 'set_name' => 'Set of ex5-17', 'number' => '4', 'image_small' => 'https://images.example.com/cached.png'],
            'sv1-1' => ['name' => 'Name of sv1-1', 'set_name' => 'Set of sv1-1', 'number' => '4', 'image_small' => 'https://images.example.com/sv1-1.png'],
            'zz9-9' => null,
        ], $list)->and($response->json('meta.degraded'))->toBeFalse($list);
    }
});

it('drops a picture the catalog no longer gives on the next refetch, and takes a changed one, also in the last good copy', function (): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => rieCard('base1-4')];
    rieRows($user, ['base1-4']);
    foreach (rieLists($this, $user) as $list => $response) {
        expect($response->json('data.0.card.image_small'))->toBe('https://images.example.com/base1-4.png', $list);
    }

    // Past the hard window: the name is refetched, and the catalog now has no image for it.
    $this->catalog = ['base1-4' => rieCard('base1-4', false)];
    $this->travel(86400 + 301)->seconds();
    foreach (rieLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect($response->json('data.0.card'))->not->toHaveKey('image_small', $list);
    }

    // The catalog then goes down: the last good copy is the refetched one, without the stale picture.
    $this->travel(86400 + 301)->seconds();
    $this->down = true;
    foreach (rieLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect($response->json('meta.degraded'))->toBeTrue($list)
            ->and($response->json('data.0.card'))->toBe(['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'], $list);
    }

    // Back up with a new picture: the new one, never the old one.
    $this->down = false;
    $this->catalog = ['base1-4' => rieCard('base1-4', 'https://images.example.com/new.png')];
    $this->travel(86400 + 301)->seconds();
    foreach (rieLists($this, $user) as $list => $response) {
        expect($response->json('data.0.card.image_small'))->toBe('https://images.example.com/new.png', $list);
    }
});

it('refuses a bad limit or direction on movers and a signed-out watchlist or portfolio as problem+json', function (): void {
    foreach (['limit=0', 'limit=51', 'limit=abc', 'limit=', 'direction=UP', 'direction='] as $query) {
        $response = $this->getJson('/v1/movers?'.$query);
        expect([$query, $response->getStatusCode()])->toBe([$query, 422])
            ->and((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and((string) $response->getContent())->not->toContain('image_small');
    }
    foreach (['/v1/watchlist', '/v1/portfolio'] as $path) {
        $response = $this->withHeaders(Accounts::FIRST_PARTY)->getJson($path);
        expect([$path, $response->getStatusCode()])->toBe([$path, 401])
            ->and((string) $response->headers->get('Content-Type'))->toStartWith('application/problem+json')
            ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
            ->and((string) $response->getContent())->not->toContain('images.example.com');
    }
});

it('never shows the demo account another account\'s rows or pictures, before, during and after that account\'s writes', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $other = Accounts::user(['email' => 'other@example.com']);
    $this->catalog = [
        'base1-4' => rieCard('base1-4', 'https://images.example.com/demo.png'),
        'sv1-1' => rieCard('sv1-1', 'https://images.example.com/other-only.png'),
    ];
    AccountRows::variant('base1-4:holo:-:-');
    AccountRows::variant('sv1-1:holo:-:-');
    AccountRows::price('base1-4:holo:-:-', '11.00');
    AccountRows::price('sv1-1:holo:-:-', '13.00');
    AccountRows::watch($demo, 'base1-4:holo:-:-');
    AccountRows::hold($demo, 'base1-4:holo:-:-', 1);

    $read = function () use ($demo): array {
        return [
            'watchlist' => AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist'),
            'portfolio' => AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio'),
        ];
    };
    $before = array_map(fn (TestResponse $r): mixed => $r->json('data'), $read());

    $writes = [
        AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/watchlist/sv1-1:holo:-:-'),
        AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/sv1-1:holo:-:-', ['quantity' => 3]),
        AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/watchlist/base1-4:holo:-:-'),
        AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/base1-4:holo:-:-', ['quantity' => 9]),
    ];
    foreach ($writes as $write) {
        expect($write->getStatusCode())->toBe(200)
            ->and((string) $write->getContent())->not->toContain('demo.png');
    }
    $during = $read();
    $otherWatch = AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');

    AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/watchlist/base1-4:holo:-:-')->assertNoContent();
    AccountRows::signIn($this, $other)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/base1-4:holo:-:-')->assertNoContent();
    $after = $read();

    expect($before['watchlist'][0]['card']['image_small'] ?? null)->toBe('https://images.example.com/demo.png')
        ->and($before['portfolio'][0]['quantity'])->toBe(1)
        ->and(array_keys(rieCards($otherWatch)))->toBe(['base1-4', 'sv1-1']);
    foreach (['during' => $during, 'after' => $after] as $when => $answers) {
        foreach ($answers as $list => $response) {
            expect($response->json('data'))->toBe($before[$list], "{$when} {$list}")
                ->and((string) $response->getContent())->not->toContain('other-only.png');
        }
    }
});
