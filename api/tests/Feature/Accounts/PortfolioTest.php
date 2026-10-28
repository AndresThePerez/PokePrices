<?php

declare(strict_types=1);

use App\Domain\Accounts\Portfolio;
use App\Http\Problems\ProblemDetails;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan P4-i1 T05 criteria 2 to 5, 9 and 10 for the portfolio (D5, D6, D9): every status and body, set-not-add
// quantities, bad quantities, the 100-holding limit (also under a concurrent writer), isolation, at most two SQL
// statements per read, the valuation computed in Postgres numeric (D9's worked example, exactly) and no-store.
// Every /v1 response is also validated against openapi/api.json by Tests\TestCase (C3).

beforeEach(function (): void {
    Accounts::setUp();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function pfGet(object $test, $user): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
}

function pfPut(object $test, $user, string $path, mixed $body): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/'.$path, $body);
}

function pfDelete(object $test, $user, string $path): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/'.$path);
}

it('values D9\'s worked example exactly: 968.91, 79.02, total 1047.93, the unpriced holding null and counted', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12');
    AccountRows::price('ex5-17:reverse:-:-', '26.34', '2026-10-12', '25.00', '2026-10-11', '1.34', '5.36');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);
    AccountRows::hold($user, 'base1-4:holo:1st:-', 2);

    $response = pfGet($this, $user);

    $response->assertOk();
    $data = $response->json('data');
    expect(array_column($data, 'variant_key'))->toBe(['base1-4:holo:1st:-', 'base1-4:holo:unlimited:-', 'ex5-17:reverse:-:-'])
        ->and(array_column($data, 'quantity'))->toBe([2, 1, 3])
        ->and(array_column($data, 'value'))->toBe([null, '968.91', '79.02'])
        ->and($data[0]['price'])->toBeNull()
        ->and($data[2])->toBe([
            'variant_key' => 'ex5-17:reverse:-:-', 'card_id' => 'ex5-17', 'finish' => 'reverse', 'edition' => '-', 'treatment' => '-',
            'card' => ['name' => 'Name of ex5-17', 'set_name' => 'Set of ex5-17', 'number' => '4'],
            'price' => [
                'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
                'close' => '26.34', 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z', 'previous_close' => '25.00',
                'previous_day' => '2026-10-11', 'change' => '1.34', 'change_pct' => '5.36', 'series_epoch' => 1,
            ],
            'quantity' => 3,
            'value' => '79.02',
        ])
        ->and($response->json('meta'))->toBe([
            'count' => 3, 'limit' => 100, 'total_value' => '1047.93', 'priced' => 2, 'unpriced' => 1,
            'first_day' => '2026-10-12', 'last_day' => '2026-10-12', 'source' => 'tcgplayer', 'feed' => 'tcgcsv',
            'basis' => 'market', 'currency' => 'USD', 'degraded' => false,
        ]);
});

it('names the first and last day when the priced holdings\' closes differ by day', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12');
    AccountRows::price('ex5-17:reverse:-:-', '26.34', '2026-10-09');
    AccountRows::price('base1-4:holo:1st:-', '1500.00', '2026-10-11');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);
    AccountRows::hold($user, 'base1-4:holo:1st:-', 1);

    $meta = pfGet($this, $user)->json('meta');

    expect([$meta['first_day'], $meta['last_day'], $meta['total_value'], $meta['priced'], $meta['unpriced']])
        ->toBe(['2026-10-09', '2026-10-12', '2547.93', 3, 0]);
});

it('answers nothing priced with total_value, first_day and last_day null, and an empty portfolio alike', function (): void {
    $user = Accounts::user();
    $empty = pfGet($this, $user)->json('meta');
    AccountRows::hold($user, 'base1-4:holo:1st:-', 2);

    $meta = pfGet($this, $user)->json('meta');

    expect([$meta['total_value'], $meta['first_day'], $meta['last_day'], $meta['priced'], $meta['unpriced'], $meta['count']])->toBe([null, null, null, 0, 1, 1])
        ->and([$empty['total_value'], $empty['first_day'], $empty['last_day'], $empty['priced'], $empty['unpriced'], $empty['count']])->toBe([null, null, null, 0, 0, 0]);
});

it('values 9999 × 9999999999.99 exactly, with no overflow and no float', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '9999999999.99');
    AccountRows::price('ex5-17:reverse:-:-', '0.01');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 9999);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 9999);

    $response = pfGet($this, $user);

    expect($response->json('data.0.value'))->toBe('99989999999900.01')
        ->and($response->json('data.1.value'))->toBe('99.99')
        ->and($response->json('meta.total_value'))->toBe('99990000000000.00')
        ->and((string) $response->getContent())->toContain('"total_value":"99990000000000.00"');
});

it('sets the quantity with PUT, never adds: 200 {"data": {"variant_key", "quantity"}} each time, one row', function (): void {
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');

    $first = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 3]);
    $second = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 3]);
    $third = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 9999]);

    expect([$first->getStatusCode(), $second->getStatusCode(), $third->getStatusCode()])->toBe([200, 200, 200])
        ->and($first->json())->toBe(['data' => ['variant_key' => 'ex5-17:reverse:-:-', 'quantity' => 3]])
        ->and($third->json())->toBe(['data' => ['variant_key' => 'ex5-17:reverse:-:-', 'quantity' => 9999]])
        ->and(AccountRows::held($user))->toBe(['ex5-17:reverse:-:-' => 9999]);
});

it('refuses a bad quantity with 422 errors.quantity and changes nothing', function (mixed $body): void {
    $user = Accounts::user();
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);

    $response = pfPut($this, $user, 'ex5-17:reverse:-:-', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($response->json('errors')))->toBe(['quantity'])
        ->and(AccountRows::held($user))->toBe(['ex5-17:reverse:-:-' => 3]);
})->with([
    'zero' => [['quantity' => 0]],
    '10000' => [['quantity' => 10000]],
    '1.5' => [['quantity' => 1.5]],
    '"abc"' => [['quantity' => 'abc']],
    'missing' => [[]],
    'a numeric string' => [['quantity' => '3']],
    'true' => [['quantity' => true]],
    'null' => [['quantity' => null]],
    'a list' => [['quantity' => [3]]],
    'negative' => [['quantity' => -1]],
]);

it('refuses a well-formed key PokéPrices has no variant for with 422 errors.variant_key', function (): void {
    $user = Accounts::user();

    $response = pfPut($this, $user, 'zz9-999:holo:-:-', ['quantity' => 1]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['variant_key' => ['PokéPrices has no variant with this key.']])
        ->and(AccountRows::held($user))->toBe([]);
});

it('refuses the 101st holding with 422 and keeps 100; changing a held quantity still answers 200', function (): void {
    $user = Accounts::user();
    for ($i = 1; $i <= 100; $i++) {
        AccountRows::hold($user, "zz1-{$i}:holo:-:-", 1);
    }
    AccountRows::variant('aa1-1:holo:-:-');

    $full = pfPut($this, $user, 'aa1-1:holo:-:-', ['quantity' => 1]);
    $change = pfPut($this, $user, 'zz1-7:holo:-:-', ['quantity' => 5]);

    expect($full->getStatusCode())->toBe(422)
        ->and($full->json('errors'))->toBe(['variant_key' => ['Your portfolio holds at most 100 variants.']])
        ->and($change->getStatusCode())->toBe(200)
        ->and(count(AccountRows::held($user)))->toBe(100)
        ->and(AccountRows::held($user)['zz1-7:holo:-:-'])->toBe(5);
});

it('removes with DELETE: 204, and 204 again when the holding is absent', function (): void {
    $user = Accounts::user();
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);

    $codes = [
        pfDelete($this, $user, 'ex5-17:reverse:-:-')->getStatusCode(),
        pfDelete($this, $user, 'ex5-17:reverse:-:-')->getStatusCode(),
        pfDelete($this, $user, 'zz9-999:holo:-:-')->getStatusCode(),
    ];

    expect($codes)->toBe([204, 204, 204])->and(AccountRows::held($user))->toBe([]);
});

it('keeps users apart: B never lists A\'s holdings, and B\'s PUT and DELETE of the same key leave A\'s as they were', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    AccountRows::price('ex5-17:reverse:-:-', '26.34');
    AccountRows::hold($a, 'ex5-17:reverse:-:-', 3);
    $before = pfGet($this, $a)->json();

    $bList = pfGet($this, $b);
    pfPut($this, $b, 'ex5-17:reverse:-:-', ['quantity' => 7])->assertOk();
    pfDelete($this, $b, 'ex5-17:reverse:-:-')->assertNoContent();

    expect($bList->json('data'))->toBe([])
        ->and($bList->json('meta.total_value'))->toBeNull()
        ->and(pfGet($this, $a)->json())->toBe($before)
        ->and(AccountRows::held($a))->toBe(['ex5-17:reverse:-:-' => 3])
        ->and(AccountRows::held($b))->toBe([]);
});

it('reads the portfolio in at most two SQL statements at 1 and at 100 holdings, with one batched name read', function (int $holdings): void {
    $user = Accounts::user();
    for ($i = 1; $i <= $holdings; $i++) {
        $key = "zz{$i}-1:holo:-:-";
        $i % 2 === 1 ? AccountRows::price($key, '1.25') : AccountRows::variant($key);
        AccountRows::hold($user, $key, $i);
    }
    AccountRows::signIn($this, $user);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (! preg_match('/\bfrom\s+"?users"?\b/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });

    $response = pfGet($this, $user);

    $response->assertOk();
    expect(count($queries))->toBeLessThanOrEqual(2)
        ->and(AccountRows::batches())->toHaveCount(1)
        ->and($response->json('meta.count'))->toBe($holdings)
        ->and($response->json('meta.priced'))->toBe(intdiv($holdings + 1, 2));
})->with([1, 100]);

it('answers 200 with null names and meta.degraded true while PokéSearch is down', function (): void {
    $user = Accounts::user();
    AccountRows::price('ex5-17:reverse:-:-', '26.34');
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);
    AccountRows::$down = true;

    $response = pfGet($this, $user);

    $response->assertOk();
    expect($response->json('data.0.card'))->toBeNull()
        ->and($response->json('data.0.value'))->toBe('79.02')
        ->and($response->json('meta.degraded'))->toBeTrue();
});

it('answers GET /v1/portfolio with Cache-Control no-store, private and no ETag (F9)', function (): void {
    $response = pfGet($this, Accounts::user());

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

it('answers signed out with 401 on every portfolio route', function (string $method, string $path): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->json($method, $path, ['quantity' => 1]);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
})->with([
    ['GET', '/v1/portfolio'],
    ['PUT', '/v1/portfolio/ex5-17:reverse:-:-'],
    ['DELETE', '/v1/portfolio/ex5-17:reverse:-:-'],
]);

it('holds the per-user lock across the count, so a second writer cannot slip in before the insert (F6)', function (): void {
    $user = Accounts::user();
    for ($i = 1; $i <= 99; $i++) {
        AccountRows::hold($user, "zz1-{$i}:holo:-:-", 1);
    }
    AccountRows::variant('aa1-1:holo:-:-');
    AccountRows::variant('aa1-2:holo:-:-');
    config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_rival');
    $rival->statement("SET lock_timeout = '250ms'");
    $outcome = null;
    DB::listen(function (QueryExecuted $query) use (&$outcome, $rival, $user): void {
        if ($outcome !== null || $query->connectionName === 'pgsql_rival' || ! preg_match('/count\(\*\)/i', $query->sql) || ! str_contains($query->sql, 'portfolio_holdings')) {
            return;
        }
        $outcome = 'counting';
        try {
            (new Portfolio($rival))->set($user->id, 'aa1-2:holo:-:-', 1);
            $outcome = 'rival finished';
        } catch (QueryException $e) {
            $outcome = (string) $e->getCode();
        }
    });

    (new Portfolio(DB::connection()))->set($user->id, 'aa1-1:holo:-:-', 1);
    DB::purge('pgsql_rival');

    expect($outcome)->toBe('55P03')
        ->and(count(AccountRows::held($user)))->toBe(100);
});

it('caps the quantity at accounts.limits.quantity_max: 10 is set, 11 answers 422 errors.quantity (Q1-i4 T03)', function (): void {
    config(['accounts.limits.quantity_max' => 10]);
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');

    $ten = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 10]);
    $eleven = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 11]);

    expect($ten->getStatusCode())->toBe(200)
        ->and($eleven->getStatusCode())->toBe(422)
        ->and($eleven->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($eleven->json('errors')))->toBe(['quantity'])
        ->and(AccountRows::held($user))->toBe(['ex5-17:reverse:-:-' => 10]);
});

it('never lets a quantity_max above the database\'s 9999 reach Postgres: 9999 is set, 10000 answers 422, not 500 (Q1-i4 T03)', function (): void {
    config(['accounts.limits.quantity_max' => 20000]);
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');

    $max = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 9999]);
    $over = pfPut($this, $user, 'ex5-17:reverse:-:-', ['quantity' => 10000]);

    expect($max->getStatusCode())->toBe(200)
        ->and($over->getStatusCode())->toBe(422)
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and(array_keys($over->json('errors')))->toBe(['quantity'])
        ->and(AccountRows::held($user))->toBe(['ex5-17:reverse:-:-' => 9999]);
});
