<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountLock;
use App\Domain\Accounts\DemoAccount;
use App\Domain\Accounts\Portfolio;
use App\Domain\Accounts\VariantKey;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Reviewer edge cases for P4-i1 T05+T06: the key shape against the real card_variants constraints, IDOR on both
// tables, the per-user lock (different users never wait on each other), valuation exactness, the demo's method
// bypass attempts, the seeder's refusal and password handling, and the demo tier's isolation from real users.

beforeEach(function (): void {
    Accounts::setUp();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** @return array<string, list<array<string, mixed>>> every column of the user's rows in both list tables */
function edgeRows(User $user): array
{
    $rows = [];
    foreach (['watchlist_items', 'portfolio_holdings'] as $table) {
        $rows[$table] = DB::table($table)->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn (object $r): array => (array) $r)->all();
    }

    return $rows;
}

function edgeCents(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

it('agrees with the real card_variants constraints on every candidate key (no false negative, no false positive)', function (): void {
    $candidates = [
        'base1-4:holo:unlimited:-', 'ex10-?:holo:-:-', 'a!b.c_d-e:normal:shadowless:cosmos-holo', 'A:reverse:1st:x',
        '9:normal:-:-', '-a:holo:-:-', '.a:holo:-:-', '?a:holo:-:-', '!a:holo:-:-', '_a:holo:-:-',
        'a:HOLO:-:-', 'a:holo:Unlimited:-', 'a:holo:-:Cosmos', 'a:holo:-:cosmos_holo', 'a:holo:-:cosmos--holo',
        'a:holo:-:-cosmos', 'a:holo:-:cosmos-', 'a:holo:-:', 'a:holo::-', 'a::-:-', ':holo:-:-', 'a:holo:-:--',
        'a:holo:-:1', 'a:holo:-:a1-b2-c3', 'a b:holo:-:-', "a:holo:-:-\n", "a\n:holo:-:-", 'é:holo:-:-', 'a:holo:-:é',
        'a:holo:-:０', 'a:foil:-:-', 'a:holo:2nd:-', 'a:holo:-:-:-', 'a:holo:-', str_repeat('a', 128).':holo:-:-',
        str_repeat('a', 129).':holo:-:-', 'a/b:holo:-:-', 'a:holo:-:a/b', 'a+b:holo:-:-', 'a=b:holo:-:-',
    ];

    $disagreements = [];
    foreach ($candidates as $key) {
        $parts = explode(':', $key);
        $database = false;
        if (count($parts) === 4) {
            try {
                DB::transaction(function () use ($key, $parts): void {
                    DB::table('card_variants')->insert([
                        'variant_key' => $key, 'card_id' => $parts[0], 'finish' => $parts[1], 'edition' => $parts[2], 'treatment' => $parts[3],
                    ]);
                    throw new LogicException('accepted');
                });
            } catch (LogicException) {
                $database = true;
            } catch (QueryException) {
                $database = false;
            }
        }
        if (VariantKey::isValid($key) !== $database) {
            $disagreements[] = [$key, 'isValid' => VariantKey::isValid($key), 'database' => $database];
        }
    }

    expect($disagreements)->toBe([]);
});

it('holds the 300-byte limit exactly and refuses a trailing newline, uppercase finish and 8-bit digits on the route as 422, never 500', function (): void {
    $edge = 'a:holo:-:';
    $at = $edge.str_repeat('x', 300 - strlen($edge));
    expect(strlen($at))->toBe(300)
        ->and(VariantKey::isValid($at))->toBeTrue()
        ->and(VariantKey::isValid($at.'x'))->toBeFalse();

    $user = Accounts::user();
    $queries = 0;
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        $queries++;
    });
    foreach (['a:holo:-:-%0A', 'a:HOLO:-:-', 'a:holo:-:%EF%BC%90', $at.'x'] as $path) {
        $put = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/watchlist/'.$path);
        $putHolding = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/'.$path, ['quantity' => 1]);
        expect([$path, $put->getStatusCode(), $put->json('errors.variant_key.0')])->toBe([$path, 422, 'PokéPrices has no variant with this key.'])
            ->and([$path, $putHolding->getStatusCode()])->toBe([$path, 422]);
    }
    expect(AccountRows::watched($user))->toBe([])
        ->and(AccountRows::held($user))->toBe([]);
});

it('never lets user B read, change or remove user A\'s rows through either table, byte for byte', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    $key = 'ex10-?:holo:-:-';
    AccountRows::watch($a, $key);
    AccountRows::hold($a, $key, 4);
    DB::table('portfolio_holdings')->where('user_id', $a->id)->update(['updated_at' => '2026-01-02 03:04:05+00', 'created_at' => '2026-01-01 00:00:00+00']);
    $before = edgeRows($a);
    $encoded = 'ex10-%3F:holo:-:-';

    $bWatch = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/watchlist/'.$encoded);
    $bHold = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/'.$encoded, ['quantity' => 9]);
    $bHoldAgain = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/'.$encoded, ['quantity' => 2]);

    expect([$bWatch->getStatusCode(), $bHold->getStatusCode(), $bHoldAgain->getStatusCode()])->toBe([200, 200, 200])
        ->and(edgeRows($a))->toBe($before)
        ->and(AccountRows::held($b))->toBe([$key => 2]);

    AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/watchlist/'.$encoded)->assertNoContent();
    AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/'.$encoded)->assertNoContent();
    AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/'.$encoded)->assertNoContent();

    expect(edgeRows($a))->toBe($before)
        ->and(AccountRows::watched($b))->toBe([])
        ->and(AccountRows::held($b))->toBe([]);
    $listing = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
    expect($listing->json('data'))->toBe([])
        ->and($listing->json('meta.count'))->toBe(0);
});

it('serialises two writers of one user but never two different users (the lock is per user, not a table lock)', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    AccountRows::variant('aa1-1:holo:-:-');
    config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
    $rival = DB::connection('pgsql_rival');
    $rival->statement("SET lock_timeout = '250ms'");
    $seen = [];
    DB::listen(function (QueryExecuted $query) use (&$seen, $rival, $a, $b): void {
        if ($seen !== [] || $query->connectionName === 'pgsql_rival' || ! str_contains($query->sql, 'count(*)') || ! str_contains($query->sql, 'portfolio_holdings')) {
            return;
        }
        $seen['started'] = true;
        foreach (['other user' => $b->id, 'same user' => $a->id] as $label => $id) {
            try {
                $rival->transaction(function () use ($rival, $id): void {
                    AccountLock::forUser($rival, $id);
                });
                $seen[$label] = 'acquired';
            } catch (QueryException $e) {
                $seen[$label] = (string) $e->getCode();
            }
        }
    });

    $error = (new Portfolio(DB::connection()))->set($a->id, 'aa1-1:holo:-:-', 1);
    DB::purge('pgsql_rival');

    expect($error)->toBeNull()
        ->and($seen['other user'] ?? null)->toBe('acquired')
        ->and($seen['same user'] ?? null)->toBe('55P03');
});

it('values 100 mixed holdings exactly in Postgres numeric: every value and the total equal integer-cent arithmetic', function (): void {
    $user = Accounts::user();
    $expected = [];
    $total = 0;
    for ($n = 1; $n <= 100; $n++) {
        $key = "pf{$n}-1:holo:-:-";
        $cents = ($n * 7919 + 13) % 99999 + ($n % 3 === 0 ? 0 : 5); // many awkward values: x.x5, x.x1, 0.0x
        $quantity = ($n * 101) % 9999 + 1;
        AccountRows::price($key, edgeCents($cents), '2026-10-12');
        AccountRows::hold($user, $key, $quantity);
        $expected[$key] = edgeCents($cents * $quantity);
        $total += $cents * $quantity;
    }

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');

    $values = [];
    foreach ($response->json('data') as $holding) {
        $values[$holding['variant_key']] = $holding['value'];
    }
    ksort($values);
    ksort($expected);
    expect($values)->toBe($expected)
        ->and($response->json('meta.total_value'))->toBe(edgeCents($total))
        ->and($response->json('meta.priced'))->toBe(100)
        ->and($response->json('meta.unpriced'))->toBe(0);
});

it('keeps a zero close priced: value "0.00" and total "0.00", distinct from an unpriced holding\'s null', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '0.00', '2026-10-12');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 9999);
    AccountRows::hold($user, 'base1-4:holo:1st:-', 1);

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');

    expect(array_column($response->json('data'), 'value', 'variant_key'))->toBe(['base1-4:holo:1st:-' => null, 'base1-4:holo:unlimited:-' => '0.00'])
        ->and($response->json('meta.total_value'))->toBe('0.00')
        ->and($response->json('meta.priced'))->toBe(1)
        ->and($response->json('meta.unpriced'))->toBe(1)
        ->and($response->json('meta.first_day'))->toBe('2026-10-12');
});

it('gives each holding its own row\'s series_epoch and days when the latest closes sit in different epochs, never a blend', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '10.00', '2026-10-12');
    AccountRows::price('ex5-17:reverse:-:-', '20.00', '2026-09-30');
    DB::table('price_latest')->where('variant_key', 'ex5-17:reverse:-:-')->update(['series_epoch' => 3]);
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 2);

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');

    $rows = [];
    foreach ($response->json('data') as $holding) {
        $rows[$holding['variant_key']] = [$holding['price']['series_epoch'], $holding['price']['day'], $holding['price']['as_of'], $holding['value']];
    }
    expect($rows)->toBe([
        'base1-4:holo:unlimited:-' => [1, '2026-10-12', '2026-10-12T20:05:45Z', '10.00'],
        'ex5-17:reverse:-:-' => [3, '2026-09-30', '2026-09-30T20:05:45Z', '40.00'],
    ])
        ->and($response->json('meta.first_day'))->toBe('2026-09-30')
        ->and($response->json('meta.last_day'))->toBe('2026-10-12');
});

it('refuses a demo write sent as a POST with a method-override header or _method field, changing no row', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $key = 'base1-4:holo:unlimited:-';
    AccountRows::hold($demo, $key, 1);
    AccountRows::watch($demo, $key);
    AccountRows::variant('ex5-17:reverse:-:-');
    $before = edgeRows($demo);
    $other = Accounts::user(['email' => 'person@example.com']);

    // Symfony honours the override only after enableHttpMethodParameterOverride(), which Request::capture() calls
    // in production; turn it on here and put the static back afterwards.
    Request::enableHttpMethodParameterOverride();
    try {
        $server = ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => Accounts::ORIGIN.'/', 'HTTP_ORIGIN' => Accounts::ORIGIN];
        $control = AccountRows::signIn($this, $other)->call('POST', '/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 5], [], [], $server);
        $header = AccountRows::signIn($this, $demo)->call('POST', '/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 5], [], [], $server);
        $field = AccountRows::signIn($this, $demo)->call('POST', '/v1/watchlist/ex5-17:reverse:-:-', ['_method' => 'PUT'], [], [], ['HTTP_ACCEPT' => 'application/json'] + $server);
        $delete = AccountRows::signIn($this, $demo)->call('POST', '/v1/portfolio/'.$key, ['_method' => 'DELETE'], [], [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE'] + $server);
        $fortify = AccountRows::signIn($this, $demo)->call('POST', '/user/two-factor-authentication', ['_method' => 'DELETE'], [], [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE'] + $server);
    } finally {
        (function (): void {
            self::$httpMethodParameterOverride = false;
        })->bindTo(null, Symfony\Component\HttpFoundation\Request::class)();
    }

    expect($control->getStatusCode())->toBe(200)
        ->and($header->getStatusCode())->toBe(403)
        ->and($header->json('type'))->toBe(ProblemDetails::BASE.'demo-read-only')
        ->and($field->getStatusCode())->toBe(403)
        ->and($delete->getStatusCode())->toBe(403)
        ->and($fortify->getStatusCode())->toBe(403)
        ->and(edgeRows($demo))->toBe($before);
});

it('refuses to seed over a non-demo account that holds the demo email, whatever its case, and leaves it untouched', function (): void {
    $person = Accounts::user(['email' => 'Demo@Example.com']);
    $before = (array) DB::table('users')->where('id', $person->id)->first();
    config(['accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);

    expect(fn () => DemoAccount::seed(fn (string $line): null => null))->toThrow(RuntimeException::class);

    expect((array) DB::table('users')->where('id', $person->id)->first())->toBe($before)
        ->and(DB::table('users')->where('is_demo', true)->count())->toBe(0)
        ->and(DB::table('watchlist_items')->count() + DB::table('portfolio_holdings')->count())->toBe(0);
});

it('changes the demo password only when one is configured, and keeps the stored hash when it is unset again', function (): void {
    config(['accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $lines = [];
    DemoAccount::seed(function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    $random = (string) DB::table('users')->where('is_demo', true)->value('password');

    config(['accounts.demo.password' => 'fake-demo-password-1111']);
    DemoAccount::seed(fn (string $line): null => null);
    $set = (string) DB::table('users')->where('is_demo', true)->value('password');
    config(['accounts.demo.password' => null]);
    DemoAccount::seed(fn (string $line): null => null);
    $kept = (string) DB::table('users')->where('is_demo', true)->value('password');

    expect($set)->not->toBe($random)
        ->and(Hash::check('fake-demo-password-1111', $set))->toBeTrue()
        ->and($kept)->toBe($set)
        ->and(DB::table('users')->where('is_demo', true)->count())->toBe(1)
        ->and(implode("\n", $lines))->not->toContain('fake-demo-password');
});

it('keeps the demo\'s per-IP tier apart from real users: a spent demo bucket never throttles a person on the same IP', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $person = Accounts::user(['email' => 'person@example.com']);
    $ip = ['REMOTE_ADDR' => '192.0.2.77'];
    for ($i = 0; $i < 30; $i++) {
        AccountRows::signIn($this, $demo)->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertOk();
    }

    $spent = AccountRows::signIn($this, $demo)->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    $real = AccountRows::signIn($this, $person)->withServerVariables($ip)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    expect($spent->getStatusCode())->toBe(429)
        ->and($real->getStatusCode())->toBe(200)
        ->and($real->json('data.read_only'))->toBeFalse();
});
