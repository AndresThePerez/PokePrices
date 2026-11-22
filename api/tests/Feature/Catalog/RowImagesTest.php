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

// The card's small image on the three list answers (GET /v1/movers, /v1/watchlist, /v1/portfolio): carried when the
// catalog gives one, left out when it does not, and left out without an error for a cached name written before the
// field existed. PokéSearch is faked by path with an image per card id; every catalog key lives under a prefix
// unique to the test. Every /v1 response is also validated against openapi/api.json by Tests\TestCase (C3), so
// each answer here, with and without the field, is checked against the exported contract.

const RI_BASE = 'http://192.0.2.10:8081';
const RI_REF = 'ffffffffffffffffffffffffffffffffffffffff';

/** The image URL the fake catalog gives card $id. */
function riImage(string $id): string
{
    return 'https://images.example.com/'.rawurlencode($id).'.png';
}

/** The batch card of $id: true gives it riImage($id), false leaves image_small out, any other value is sent as it is. */
function riCard(string $id, mixed $image = true): array
{
    $card = ['id' => $id, 'name' => "Name of {$id}", 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'zz',
        'set_name' => "Set of {$id}", 'set_series' => 'Base', 'release_date' => '1999-01-09', 'image_large' => 'https://images.example.com/large.png'];
    if ($image !== false) {
        $card['image_small'] = $image === true ? riImage($id) : $image;
    }

    return $card;
}

/** The name block the answers carry for $id, with its image unless $image is false. */
function riName(string $id, bool $image = true): array
{
    $name = ['name' => "Name of {$id}", 'set_name' => "Set of {$id}", 'number' => '4'];

    return $image ? $name + ['image_small' => riImage($id)] : $name;
}

/** One price_movers row of 2026-10-11 for "<card>:holo:-:-" (its card_variants row added when new). */
function riMover(int $rank, string $card): void
{
    $key = "{$card}:holo:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'holo',
        'edition' => '-', 'treatment' => '-', 'catalog_status' => 'unverified']);
    DB::table('price_movers')->insert(['day' => '2026-10-11', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => $rank,
        'variant_key' => $key, 'card_id' => $card, 'series_epoch' => 1, 'close' => '11.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-10', 'change' => '1.00', 'change_pct' => '10.00', 'as_of' => '2026-10-11 20:05:45+00']);
}

/** The three answers as $user sees them, keyed by list. */
function riLists(object $test, $user): array
{
    return [
        'movers' => $test->getJson('/v1/movers?direction=up'),
        'watchlist' => AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist'),
        'portfolio' => AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio'),
    ];
}

/** card_id => card block of every row of an answer. */
function riCards(TestResponse $response): array
{
    $cards = [];
    foreach ($response->json('data') as $row) {
        $cards[$row['card_id']] = $row['card'];
    }

    return $cards;
}

/** Puts $card ids on the movers board, $user's watchlist and $user's portfolio. */
function riRows($user, array $cards): void
{
    foreach (array_values($cards) as $i => $card) {
        riMover($i + 1, $card);
        AccountRows::price("{$card}:holo:-:-", '11.00', '2026-10-11', '10.00', '2026-10-10', '1.00', '10.00');
        AccountRows::watch($user, "{$card}:holo:-:-");
        AccountRows::hold($user, "{$card}:holo:-:-", 2);
    }
}

/** The ids of every recorded batch request, in order. */
function riBatches(): array
{
    return Http::recorded(fn (ClientRequest $r): bool => parse_url($r->url(), PHP_URL_PATH) === '/api/v1/cards')
        ->map(function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return explode(',', (string) $query['ids']);
        })->values()->all();
}

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    Queue::fake([VerifyCatalog::class]);
    config(['services.pokesearch.url' => RI_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();

    // id => batch card; unlisted ids are answered in missing. $this->down fails every request after the seed ref.
    $this->catalog = [];
    $this->down = false;
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => RI_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }
        if ($this->down) {
            return Http::response(['error' => 'down'], 503);
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

it('carries image_small on each mover, watchlist and portfolio row when the catalog gives one', function (): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => riCard('base1-4'), 'ex10-?' => riCard('ex10-?')];
    riRows($user, ['base1-4', 'ex10-?']);

    foreach (riLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect(riCards($response))->toBe(['base1-4' => riName('base1-4'), 'ex10-?' => riName('ex10-?')], $list)
            ->and(array_keys($response->json('data.0.card')))->toBe(['name', 'set_name', 'number', 'image_small'], $list);
    }
});

it('leaves image_small out, and names the card, when the catalog gives none, a blank one or one that is not a string', function (mixed $image): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => riCard('base1-4', $image), 'ex5-17' => riCard('ex5-17')];
    riRows($user, ['base1-4', 'ex5-17']);

    foreach (riLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect(riCards($response))->toBe(['base1-4' => riName('base1-4', false), 'ex5-17' => riName('ex5-17')], $list)
            ->and($response->json('meta.degraded'))->toBeFalse($list);
    }
})->with([
    'absent' => [false],
    'empty' => [''],
    'whitespace' => ['   '],
    'a number' => [42],
    'null' => [null],
    'a list' => [['https://images.example.com/x.png']],
]);

it('reads a cached name written before the field existed: no image, no error and no request for it, beside a fresh one with its image', function (): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => riCard('base1-4'), 'ex5-17' => riCard('ex5-17')];
    riRows($user, ['base1-4', 'ex5-17']);
    // The seed ref first, so the old-shape entry can be written under the key the read will use.
    $segment = app(CatalogRepository::class)->seedRef()->segment();
    $old = ['data' => ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'], 'fetched_at' => now()->getTimestamp()];
    Cache::store(CatalogRepository::STORE)->put("catalog:v1:cardname:{$segment}:base1-4", $old, 86400);

    foreach (riLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect(riCards($response))->toBe(['base1-4' => riName('base1-4', false), 'ex5-17' => riName('ex5-17')], $list)
            ->and($response->json('meta.degraded'))->toBeFalse($list);
    }
    // Only the uncached id was asked for, once; the old-shape entry was read as it is.
    expect(riBatches())->toBe([['ex5-17']]);
});

it('reads an old-shape last-good copy while PokéSearch is down: that name without an image, a newer copy with its image, degraded', function (): void {
    $user = Accounts::user();
    $this->catalog = ['ex5-17' => riCard('ex5-17')];
    riRows($user, ['base1-4', 'ex5-17']);
    riLists($this, $user);
    $old = ['data' => ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'], 'fetched_at' => now()->getTimestamp()];
    Cache::store(CatalogRepository::STORE)->put('catalog:v1:lastgood:cardname:base1-4', $old, 7 * 86400);
    // Past every fresh and hard window, the negative marker and the seed ref's, inside the 7-day last-good window.
    $this->travel(86400 + 301)->seconds();
    $this->down = true;

    foreach (riLists($this, $user) as $list => $response) {
        $response->assertOk();
        expect(riCards($response))->toBe(['base1-4' => riName('base1-4', false), 'ex5-17' => riName('ex5-17')], $list)
            ->and($response->json('meta.degraded'))->toBeTrue($list);
    }
});

it('answers an invalid direction with 422 problem+json, and PokéSearch down with the degraded lists (images from the last good copy) and the card page\'s 503 problem', function (): void {
    $user = Accounts::user();
    $this->catalog = ['base1-4' => riCard('base1-4')];
    riRows($user, ['base1-4']);
    $healthy = riLists($this, $user);
    // Past every fresh and hard window and the seed ref's, inside the 7-day last-good window.
    $this->travel(86400 + 301)->seconds();
    $this->down = true;

    $invalid = $this->getJson('/v1/movers?direction=sideways');
    $down = riLists($this, $user);
    $cardPage = $this->getJson('/v1/cards/base1-4');

    expect($invalid->getStatusCode())->toBe(422)
        ->and((string) $invalid->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($invalid->json('errors'))->toHaveKey('direction')
        ->and($cardPage->getStatusCode())->toBe(503)
        ->and((string) $cardPage->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($cardPage->json('type'))->toBe(ProblemDetails::BASE.'upstream-unavailable')
        ->and($cardPage->headers->get('Retry-After'))->not->toBeNull()
        ->and((string) $cardPage->getContent())->not->toContain('images.example.com');
    foreach ($healthy as $list => $response) {
        expect($response->json('data.0.card'))->toBe(riName('base1-4'), $list);
    }
    foreach ($down as $list => $response) {
        expect($response->getStatusCode())->toBe(200, $list)
            ->and($response->json('meta.degraded'))->toBeTrue($list)
            ->and($response->json('data.0.card'))->toBe(riName('base1-4'), $list);
    }
});

it('never gives one account another account\'s rows or images, through the same cached names', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    $this->catalog = ['base1-4' => riCard('base1-4'), 'ex5-17' => riCard('ex5-17'), 'sv1-1' => riCard('sv1-1')];
    AccountRows::price('base1-4:holo:-:-', '11.00');
    AccountRows::price('ex5-17:holo:-:-', '12.00');
    AccountRows::price('sv1-1:holo:-:-', '13.00');
    AccountRows::watch($a, 'base1-4:holo:-:-');
    AccountRows::hold($a, 'ex5-17:holo:-:-', 1);
    AccountRows::watch($b, 'sv1-1:holo:-:-');
    AccountRows::hold($b, 'sv1-1:holo:-:-', 3);
    AccountRows::watch($b, 'ex5-17:holo:-:-');

    $aWatch = AccountRows::signIn($this, $a)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $aHold = AccountRows::signIn($this, $a)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
    $bWatch = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $bHold = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');

    expect(riCards($aWatch))->toBe(['base1-4' => riName('base1-4')])
        ->and(riCards($aHold))->toBe(['ex5-17' => riName('ex5-17')])
        ->and(riCards($bWatch))->toBe(['ex5-17' => riName('ex5-17'), 'sv1-1' => riName('sv1-1')])
        ->and(riCards($bHold))->toBe(['sv1-1' => riName('sv1-1')]);
});
