<?php

declare(strict_types=1);

use App\Domain\Accounts\Watchlist;
use App\Http\Problems\ProblemDetails;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan P4-i1 T05 criteria 2 to 4, 6, 7, 9 and 10 for the watchlist (D5, D6, D7): every status and body, %3F keys,
// the 100-item limit (also under a concurrent writer), isolation between users, one SQL statement per read, one
// batched name read, the degraded flag, the shared price block, the account tier and no-store. Every /v1 response
// is also validated against openapi/api.json by Tests\TestCase (C3).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** The watchlist as user $user sees it. */
function wlGet(object $test, $user): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
}

function wlPut(object $test, $user, string $path): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/watchlist/'.$path);
}

function wlDelete(object $test, $user, string $path): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/watchlist/'.$path);
}

it('lists the watchlist with names and the price block, ordered by card_id then variant_key', function (): void {
    $user = Accounts::user();
    AccountRows::price('ex5-17:reverse:-:-', '26.34', '2026-10-12', '25.00', '2026-10-11', '1.34', '5.36');
    AccountRows::price('base1-4:holo:unlimited:-', '968.91');
    AccountRows::watch($user, 'ex5-17:reverse:-:-');
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    AccountRows::watch($user, 'base1-4:holo:1st:-');

    $response = wlGet($this, $user);

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [
            [
                'variant_key' => 'base1-4:holo:1st:-', 'card_id' => 'base1-4', 'finish' => 'holo', 'edition' => '1st', 'treatment' => '-',
                'card' => ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'],
                'price' => null,
                'alert_pct' => null,
            ],
            [
                'variant_key' => 'base1-4:holo:unlimited:-', 'card_id' => 'base1-4', 'finish' => 'holo', 'edition' => 'unlimited', 'treatment' => '-',
                'card' => ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'],
                'price' => [
                    'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
                    'close' => '968.91', 'day' => '2026-10-11', 'as_of' => '2026-10-11T20:05:45Z', 'previous_close' => null,
                    'previous_day' => null, 'change' => null, 'change_pct' => null, 'series_epoch' => 1,
                ],
                'alert_pct' => null,
            ],
            [
                'variant_key' => 'ex5-17:reverse:-:-', 'card_id' => 'ex5-17', 'finish' => 'reverse', 'edition' => '-', 'treatment' => '-',
                'card' => ['name' => 'Name of ex5-17', 'set_name' => 'Set of ex5-17', 'number' => '4'],
                'price' => [
                    'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
                    'close' => '26.34', 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z', 'previous_close' => '25.00',
                    'previous_day' => '2026-10-11', 'change' => '1.34', 'change_pct' => '5.36', 'series_epoch' => 1,
                ],
                'alert_pct' => null,
            ],
        ],
        'meta' => ['count' => 3, 'limit' => 100, 'degraded' => false],
    ]);
});

it('answers an empty watchlist with data [] and count 0', function (): void {
    wlGet($this, Accounts::user())->assertOk()->assertExactJson(['data' => [], 'meta' => ['count' => 0, 'limit' => 100, 'degraded' => false]]);
});

it('adds with PUT: 200 {"data": {"variant_key"}} twice, one row (F3)', function (): void {
    $user = Accounts::user();
    AccountRows::variant('base1-4:holo:unlimited:-');

    $first = wlPut($this, $user, 'base1-4:holo:unlimited:-');
    $second = wlPut($this, $user, 'base1-4:holo:unlimited:-');

    expect($first->getStatusCode())->toBe(200)
        ->and($first->json())->toBe(['data' => ['variant_key' => 'base1-4:holo:unlimited:-']])
        ->and($second->getStatusCode())->toBe(200)
        ->and($second->json())->toBe(['data' => ['variant_key' => 'base1-4:holo:unlimited:-']])
        ->and(AccountRows::watched($user))->toBe(['base1-4:holo:unlimited:-']);
});

it('round-trips keys with ? and ! sent percent-encoded', function (string $key, string $path): void {
    $user = Accounts::user();
    AccountRows::variant($key);

    wlPut($this, $user, $path)->assertOk()->assertExactJson(['data' => ['variant_key' => $key]]);
    expect(wlGet($this, $user)->json('data.0.variant_key'))->toBe($key);
    wlDelete($this, $user, $path)->assertNoContent();
    expect(AccountRows::watched($user))->toBe([]);
})->with([
    'question mark' => ['ex10-?:holo:-:-', 'ex10-%3F:holo:-:-'],
    'exclamation mark' => ['sv1-1!:normal:-:-', 'sv1-1%21:normal:-:-'],
]);

it('refuses a well-formed key PokéPrices has no variant for with 422 errors.variant_key', function (): void {
    $user = Accounts::user();

    $response = wlPut($this, $user, 'zz9-999:holo:-:-');

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toBe(['variant_key' => ['PokéPrices has no variant with this key.']])
        ->and(AccountRows::watched($user))->toBe([]);
});

it('refuses the 101st variant with 422 and keeps 100; a key already there still answers 200', function (): void {
    $user = Accounts::user();
    for ($i = 1; $i <= 100; $i++) {
        AccountRows::watch($user, "zz1-{$i}:holo:-:-");
    }
    AccountRows::variant('aa1-1:holo:-:-');

    $full = wlPut($this, $user, 'aa1-1:holo:-:-');
    $again = wlPut($this, $user, 'zz1-7:holo:-:-');

    expect($full->getStatusCode())->toBe(422)
        ->and($full->json('errors'))->toBe(['variant_key' => ['Your watchlist holds at most 100 variants.']])
        ->and($again->getStatusCode())->toBe(200)
        ->and(count(AccountRows::watched($user)))->toBe(100);
});

it('removes with DELETE: 204, and 204 again when the item is absent', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');

    $first = wlDelete($this, $user, 'base1-4:holo:unlimited:-');
    $second = wlDelete($this, $user, 'base1-4:holo:unlimited:-');
    $never = wlDelete($this, $user, 'zz9-999:holo:-:-');

    expect([$first->getStatusCode(), $second->getStatusCode(), $never->getStatusCode()])->toBe([204, 204, 204])
        ->and($first->getContent())->toBe('')
        ->and(AccountRows::watched($user))->toBe([]);
});

it('keeps users apart: B never lists A\'s rows, and B\'s PUT and DELETE of the same key leave A\'s list as it was', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    AccountRows::watch($a, 'base1-4:holo:unlimited:-');
    AccountRows::watch($a, 'ex5-17:reverse:-:-');
    $before = wlGet($this, $a)->json();

    $bList = wlGet($this, $b);
    wlPut($this, $b, 'base1-4:holo:unlimited:-')->assertOk();
    wlDelete($this, $b, 'base1-4:holo:unlimited:-')->assertNoContent();
    wlDelete($this, $b, 'ex5-17:reverse:-:-')->assertNoContent();

    expect($bList->json('data'))->toBe([])
        ->and(wlGet($this, $a)->json())->toBe($before)
        ->and(AccountRows::watched($a))->toBe(['base1-4:holo:unlimited:-', 'ex5-17:reverse:-:-'])
        ->and(AccountRows::watched($b))->toBe([]);
});

it('reads the list in one SQL statement at 1 and at 100 items, and names it with one batch of the distinct card ids', function (int $items): void {
    $user = Accounts::user();
    // Two variants per card (holo, reverse), so the 100 items name 50 distinct cards; every other item is priced.
    $ids = [];
    for ($i = 1; $i <= $items; $i++) {
        $card = 'zz'.intdiv($i + 1, 2).'-1';
        $key = $card.($i % 2 === 1 ? ':holo:-:-' : ':reverse:-:-');
        $i % 2 === 1 ? AccountRows::price($key, '1.00') : AccountRows::variant($key);
        AccountRows::watch($user, $key);
        $ids[$card] = $card;
    }
    $ids = array_values($ids);
    sort($ids, SORT_STRING);
    AccountRows::signIn($this, $user);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (! preg_match('/\bfrom\s+"?users"?\b/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });

    $response = wlGet($this, $user);

    $response->assertOk();
    expect($queries)->toHaveCount(1)
        ->and(AccountRows::batches())->toBe([$ids])
        ->and($response->json('meta.count'))->toBe($items)
        ->and(count($response->json('data')))->toBe($items);
})->with([1, 100]);

it('answers 200 with null names and meta.degraded true while PokéSearch is down with no last-good copy', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '968.91');
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    AccountRows::$down = true;

    $response = wlGet($this, $user);

    $response->assertOk();
    expect($response->json('data.0.card'))->toBeNull()
        ->and($response->json('data.0.price.close'))->toBe('968.91')
        ->and($response->json('meta'))->toBe(['count' => 1, 'limit' => 100, 'degraded' => true]);
});

it('carries, key for key, the card page\'s price block for the same variant', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12', '950.00', '2026-10-10', '18.91', '1.99');
    AccountRows::variant('base1-4:holo:1st:-');
    AccountRows::watch($user, 'base1-4:holo:unlimited:-');
    AccountRows::watch($user, 'base1-4:holo:1st:-');

    $card = $this->getJson('/v1/cards/base1-4');
    $list = wlGet($this, $user);

    $card->assertOk();
    $pagePrices = [];
    foreach ($card->json('data.variants') as $variant) {
        $pagePrices[$variant['variant_key']] = $variant['price'];
    }
    $listPrices = [];
    foreach ($list->json('data') as $item) {
        $listPrices[$item['variant_key']] = $item['price'];
    }
    ksort($pagePrices);
    ksort($listPrices);
    expect($listPrices)->toBe($pagePrices)
        ->and($listPrices['base1-4:holo:unlimited:-'])->not->toBeNull();
});

it('answers GET /v1/watchlist with Cache-Control no-store, private and no ETag (F9)', function (): void {
    $response = wlGet($this, Accounts::user());

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('answers signed out with 401 on every watchlist route', function (string $method, string $path): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->json($method, $path);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
})->with([
    ['GET', '/v1/watchlist'],
    ['PUT', '/v1/watchlist/base1-4:holo:unlimited:-'],
    ['DELETE', '/v1/watchlist/base1-4:holo:unlimited:-'],
]);

it('keys the account tier by user: the 121st request of one user in a minute is 429, another user is unaffected', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    // Three addresses, so the 60-per-IP account-guest bucket that runs first is never the one spent.
    $ips = ['192.0.2.21', '192.0.2.22', '192.0.2.23'];
    for ($i = 0; $i < 120; $i++) {
        AccountRows::signIn($this, $a)->withServerVariables(['REMOTE_ADDR' => $ips[$i % 3]])->withHeaders(Accounts::FIRST_PARTY)
            ->getJson('/v1/me')->assertOk();
    }

    $last = AccountRows::signIn($this, $a)->withServerVariables(['REMOTE_ADDR' => '192.0.2.24'])->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $other = AccountRows::signIn($this, $b)->withServerVariables(['REMOTE_ADDR' => '192.0.2.24'])->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');

    expect($last->getStatusCode())->toBe(429)
        ->and($last->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($last->headers->has('Retry-After'))->toBeTrue()
        ->and($other->getStatusCode())->toBe(200);
});

it('holds the per-user lock across the count, so a second writer cannot slip in before the insert (F6)', function (): void {
    $user = Accounts::user();
    for ($i = 1; $i <= 99; $i++) {
        AccountRows::watch($user, "zz1-{$i}:holo:-:-");
    }
    AccountRows::variant('aa1-1:holo:-:-');
    AccountRows::variant('aa1-2:holo:-:-');
    // A second session on the same database, as a second php-fpm worker would hold; it gives up on a lock after 250 ms.
    config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_rival');
    $rival->statement("SET lock_timeout = '250ms'");
    $outcome = null;
    DB::listen(function (QueryExecuted $query) use (&$outcome, $rival, $user): void {
        if ($outcome !== null || $query->connectionName === 'pgsql_rival' || ! preg_match('/count\(\*\)/i', $query->sql) || ! str_contains($query->sql, 'watchlist_items')) {
            return;
        }
        $outcome = 'counting';
        try {
            $outcome = 'rival finished: '.var_export((new Watchlist($rival))->add($user->id, 'aa1-2:holo:-:-'), true);
        } catch (QueryException $e) {
            $outcome = (string) $e->getCode();
        }
    });

    $error = (new Watchlist(DB::connection()))->add($user->id, 'aa1-1:holo:-:-');
    DB::purge('pgsql_rival');

    // 55P03 lock_not_available: the rival waited on the per-user lock the first writer held while it counted.
    expect($outcome)->toBe('55P03')
        ->and($error)->toBeNull()
        ->and(count(AccountRows::watched($user)))->toBe(100);
});
