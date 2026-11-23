<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Domain\Pricing\LatestPrices;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Adversarial tests for the portfolio estimate (change, percent, counts) and its copy in GET /v1/me: generated
// portfolios checked against exact integer-cent arithmetic done here (no float, no Postgres), the summary and the page
// never drifting, the query count at 0, 1, 100 and 1,000 holdings, money and time edges, and a second account in both
// directions. Every /v1 response is also validated against openapi/api.json by Tests\TestCase.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function t09ePage(object $test, User $user): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
}

function t09eMe(object $test, User $user): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
}

/** "12.34" or "-0.06" as whole cents (an int is exact up to 9.2e18: the sums below stay under 2e17). */
function t09eCents(string $decimal): int
{
    $negative = str_starts_with($decimal, '-');
    [$whole, $fraction] = explode('.', ltrim($decimal, '-'));

    return ($negative ? -1 : 1) * ((int) $whole * 100 + (int) $fraction);
}

/** Whole cents as the two-decimal text Postgres numeric(20,2) prints (no negative zero). */
function t09eMoney(int $cents): string
{
    $digits = (string) abs($cents);
    $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);

    return ($cents < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
}

/**
 * change / previous × 100 rounded once to two decimals, half away from zero, by long division in integers (the
 * quotient's digits are carried as text so a huge ratio cannot overflow): "1.64", "-1.95", "0.00".
 */
function t09ePercent(int $change, int $previous): string
{
    $n = abs($change);
    $whole = intdiv($n, $previous);
    $remainder = $n % $previous;
    $digits = 0;
    for ($i = 0; $i < 4; $i++) {
        $remainder *= 10;
        $digits = $digits * 10 + intdiv($remainder, $previous);
        $remainder %= $previous;
    }
    if (2 * $remainder >= $previous) {
        $digits++;
    }
    if ($digits === 10000) {
        $whole++;
        $digits = 0;
    }
    $hundredths = ltrim((string) $whole.str_pad((string) $digits, 4, '0', STR_PAD_LEFT), '0');
    $hundredths = str_pad($hundredths, 3, '0', STR_PAD_LEFT);
    $text = substr($hundredths, 0, -2).'.'.substr($hundredths, -2);

    return ($change < 0 && $text !== '0.00' ? '-' : '').$text;
}

/**
 * The figures of one generated portfolio, from exact integer cents.
 *
 * @param  list<array{q: int, close: ?string, prev: ?string, day: ?string}>  $holdings
 * @return array<string, mixed>
 */
function t09eExpected(array $holdings): array
{
    $count = count($holdings);
    $priced = 0;
    $noPrevious = 0;
    $total = 0;
    $change = 0;
    $previous = 0;
    $both = 0;
    $days = [];
    foreach ($holdings as $holding) {
        if ($holding['close'] === null) {
            continue;
        }
        $priced++;
        $days[] = $holding['day'];
        $total += $holding['q'] * t09eCents($holding['close']);
        if ($holding['prev'] === null) {
            $noPrevious++;

            continue;
        }
        $both++;
        $change += $holding['q'] * (t09eCents($holding['close']) - t09eCents($holding['prev']));
        $previous += $holding['q'] * t09eCents($holding['prev']);
    }

    return [
        'count' => $count, 'priced' => $priced, 'unpriced' => $count - $priced, 'no_previous_close' => $noPrevious,
        'total_value' => $priced === 0 ? null : t09eMoney($total),
        'change' => $both === 0 ? null : t09eMoney($change),
        'change_pct' => $both === 0 || $previous === 0 ? null : t09ePercent($change, $previous),
        'first_day' => $days === [] ? null : min($days), 'last_day' => $days === [] ? null : max($days),
    ];
}

/** A close in cents from 1 to 10^12 − 1 (0.01 to 9999999999.99), log-uniform so every magnitude shows up. */
function t09eRandomCents(): int
{
    $digits = mt_rand(1, 12);
    $raw = mt_rand(0, 999999) * 1000000 + mt_rand(0, 999999);

    return max(1, $raw % (10 ** $digits));
}

function t09eDecimal(int $cents): string
{
    return t09eMoney($cents);
}

/** @return array{q: int, close: ?string, prev: ?string, day: ?string} */
function t09eHolding(): array
{
    $quantity = [1, 2, 9999, mt_rand(1, 9999), mt_rand(1, 9999)][mt_rand(0, 4)];
    $kind = mt_rand(0, 99);
    if ($kind < 15) {
        return ['q' => $quantity, 'close' => null, 'prev' => null, 'day' => null];
    }
    $closeCents = $kind < 22 ? 0 : t09eRandomCents();
    $day = '2026-10-'.str_pad((string) mt_rand(5, 12), 2, '0', STR_PAD_LEFT);
    $close = t09eDecimal($closeCents);
    if ($kind < 35) {
        return ['q' => $quantity, 'close' => $close, 'prev' => null, 'day' => $day];
    }
    if ($kind < 45) {
        return ['q' => $quantity, 'close' => $close, 'prev' => '0.00', 'day' => $day];
    }
    if ($kind < 55) {
        return ['q' => $quantity, 'close' => $close, 'prev' => $close, 'day' => $day];
    }

    return ['q' => $quantity, 'close' => $close, 'prev' => t09eDecimal(t09eRandomCents()), 'day' => $day];
}

/** Writes the portfolio into the test database for $user, replacing the last one; returns the holdings' keys. */
function t09eStore(User $user, array $holdings): void
{
    DB::table('portfolio_holdings')->where('user_id', $user->id)->delete();
    DB::table('price_latest')->delete();
    foreach ($holdings as $i => $holding) {
        $key = 'zz'.($i + 1).'-1:holo:-:-';
        if ($holding['close'] === null) {
            AccountRows::variant($key);
        } else {
            $day = $holding['day'];
            $previousDay = date('Y-m-d', strtotime($day.' -1 day'));
            $close = t09eCents($holding['close']);
            $change = $holding['prev'] === null ? null : t09eMoney($close - t09eCents($holding['prev']));
            AccountRows::price($key, $holding['close'], $day, $holding['prev'], $holding['prev'] === null ? null : $previousDay, $change, null);
        }
        AccountRows::hold($user, $key, $holding['q']);
    }
}

it('matches exact integer-cent arithmetic on generated portfolios, on the page and in /v1/me, with no NaN, INF, exponent or -0.00', function (int $seed): void {
    $user = Accounts::user();
    mt_srand($seed);
    for ($round = 0; $round < 25; $round++) {
        $holdings = [];
        for ($i = mt_rand(1, 12); $i > 0; $i--) {
            $holdings[] = t09eHolding();
        }
        t09eStore($user, $holdings);
        $expected = t09eExpected($holdings);

        $page = t09ePage($this, $user);
        $me = t09eMe($this, $user);
        $page->assertOk();
        $me->assertOk();
        $meta = $page->json('meta');
        $figures = $me->json('data.portfolio');
        $actual = array_intersect_key($meta, $expected);
        $label = "seed {$seed} round {$round}: ".json_encode($holdings);

        expect($actual)->toEqual($expected, $label)
            ->and(array_intersect_key($figures, $expected))->toEqual($expected, $label);
        foreach ([$meta, $figures] as $answer) {
            foreach (['total_value', 'change', 'change_pct'] as $key) {
                if ($answer[$key] !== null) {
                    expect($answer[$key])->toMatch('/^-?\d+\.\d{2}$/', $label)->not->toBe('-0.00');
                }
            }
        }
        foreach ([$page, $me] as $response) {
            expect((string) $response->getContent())->not->toMatch('/NaN|INF|Infinity|[0-9]e[+-]?[0-9]|-0\.00"/i', $label);
        }
    }
})->with([101, 202, 303, 404, 505, 606, 707, 808]);

it('keeps the long-division oracle honest: its own cases against hand-worked figures', function (): void {
    // The oracle the generated test trusts, pinned on figures worked by hand.
    expect(t09ePercent(1693, 103100))->toBe('1.64')
        ->and(t09ePercent(-6, 308))->toBe('-1.95')
        ->and(t09ePercent(0, 500))->toBe('0.00')
        ->and(t09ePercent(-1, 100000))->toBe('0.00')
        ->and(t09ePercent(1, 3))->toBe('33.33')
        ->and(t09ePercent(1, 8))->toBe('12.50')
        ->and(t09ePercent(5, 1000000))->toBe('0.00')
        ->and(t09ePercent(5, 100000))->toBe('0.01')
        ->and(t09eMoney(-6))->toBe('-0.06')
        ->and(t09eMoney(5))->toBe('0.05');
});

it('reads /v1/me in at most two statements and no catalogue batch at 0, 1, 100 and 1,000 holdings, and its figures equal the page\'s', function (int $holdings): void {
    $user = Accounts::user();
    $rows = [];
    $prices = [];
    $variants = [];
    for ($i = 1; $i <= $holdings; $i++) {
        $key = "yy{$i}-1:holo:-:-";
        $variants[] = [
            'variant_key' => $key, 'card_id' => "yy{$i}-1", 'finish' => 'holo', 'edition' => '-', 'treatment' => '-',
            'catalog_status' => 'unverified',
        ];
        if ($i % 3 !== 0) {
            $prices[] = [
                'variant_key' => $key, 'source' => 'tcgplayer', 'series_epoch' => 1, 'close' => '1.25', 'day' => '2026-10-12',
                'as_of' => '2026-10-12 20:05:45+00', 'previous_close' => $i % 5 === 0 ? null : '1.00',
                'previous_day' => $i % 5 === 0 ? null : '2026-10-11', 'change' => $i % 5 === 0 ? null : '0.25', 'change_pct' => null,
            ];
        }
        $rows[] = ['user_id' => $user->id, 'variant_key' => $key, 'quantity' => $i % 9999 + 1];
    }
    foreach (array_chunk($variants, 200) as $chunk) {
        DB::table('card_variants')->insert($chunk);
    }
    foreach (array_chunk($prices, 200) as $chunk) {
        DB::table('price_latest')->insert($chunk);
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('portfolio_holdings')->insert($chunk);
    }
    AccountRows::signIn($this, $user);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $me = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    $me->assertOk();
    expect(count($queries))->toBeLessThanOrEqual(2)
        ->and(AccountRows::batches())->toBe([])
        ->and($me->json('data.portfolio.count'))->toBe($holdings);
    $page = t09ePage($this, $user)->json('meta');
    $figures = $me->json('data.portfolio');
    // The summary and the page share their SQL: not one figure may drift, at any size.
    expect(array_intersect_key($page, $figures))->toEqual($figures);
    // And both match the integer oracle for these (quantity i % 9999 + 1) holdings.
    $expected = [];
    for ($i = 1; $i <= $holdings; $i++) {
        $expected[] = [
            'q' => $i % 9999 + 1, 'close' => $i % 3 === 0 ? null : '1.25', 'prev' => $i % 3 === 0 || $i % 5 === 0 ? null : '1.00',
            'day' => $i % 3 === 0 ? null : '2026-10-12',
        ];
    }
    expect(array_intersect_key($figures, t09eExpected($expected)))->toEqual(t09eExpected($expected));
})->with([0, 1, 100, 1000]);

it('names the days the total rests on when one close is a week stale, and the previous close ignores a filled day', function (): void {
    $user = Accounts::user();
    // A real close on 10-05 and then filled closes up to 10-12: price_latest keeps the real, week-old one.
    foreach (range(5, 12) as $day) {
        $stamp = '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        AccountRows::variant('base1-4:holo:unlimited:-');
        DB::table('price_daily')->insert([
            'variant_key' => 'base1-4:holo:unlimited:-', 'source' => 'tcgplayer', 'day' => $stamp, 'series_epoch' => 1,
            'open' => '40.00', 'high' => '40.00', 'low' => '40.00', 'close' => '40.00', 'n_obs' => $day === 5 ? 1 : 0, 'is_filled' => $day !== 5,
            'filled_from' => $day === 5 ? null : '2026-10-'.str_pad((string) ($day - 1), 2, '0', STR_PAD_LEFT), 'as_of' => "{$stamp} 20:05:45+00",
        ]);
    }
    AccountRows::variant('ex5-17:reverse:-:-');
    foreach (['2026-10-11' => '10.00', '2026-10-12' => '12.00'] as $stamp => $close) {
        DB::table('price_daily')->insert([
            'variant_key' => 'ex5-17:reverse:-:-', 'source' => 'tcgplayer', 'day' => $stamp, 'series_epoch' => 1,
            'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'n_obs' => 1, 'is_filled' => false,
            'filled_from' => null, 'as_of' => "{$stamp} 20:05:45+00",
        ]);
    }
    LatestPrices::rebuild(DB::connection());
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 3);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 2);

    $meta = t09ePage($this, $user)->json('meta');
    $me = t09eMe($this, $user)->json('data.portfolio');

    // The stale holding has a close and no earlier real close: it is in the total (120.00 + 24.00), not in the change
    // (2 × 2.00 against 2 × 10.00 = 20.00 %), and the total names both days.
    expect([$meta['total_value'], $meta['change'], $meta['change_pct'], $meta['no_previous_close'], $meta['first_day'], $meta['last_day']])
        ->toBe(['144.00', '4.00', '20.00', 1, '2026-10-05', '2026-10-12'])
        ->and(array_intersect_key($meta, $me))->toEqual($me);
});

it('drops a holding from the total, the change and the counts when its variant lost its price after it was added', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '10.00', '2026-10-12', '8.00', '2026-10-11', '2.00', '25.00');
    AccountRows::price('ex5-17:reverse:-:-', '5.00', '2026-10-12', '4.00', '2026-10-11', '1.00', '25.00');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 1);
    $before = t09eMe($this, $user)->json('data.portfolio');
    DB::table('price_latest')->where('variant_key', 'ex5-17:reverse:-:-')->delete();

    $after = t09eMe($this, $user)->json('data.portfolio');
    $page = t09ePage($this, $user)->json('meta');

    expect([$before['total_value'], $before['change'], $before['priced'], $before['unpriced']])->toBe(['15.00', '3.00', 2, 0])
        ->and([$after['total_value'], $after['change'], $after['change_pct'], $after['priced'], $after['unpriced'], $after['count']])
        ->toBe(['10.00', '2.00', '25.00', 1, 1, 2])
        ->and(array_intersect_key($page, $after))->toEqual($after);
});

it('prices a close of 0.00 as priced: counted, valued 0.00, never unpriced and never a percent from nothing', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '0.00', '2026-10-12');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 9999);

    $meta = t09ePage($this, $user)->json('meta');

    expect([$meta['count'], $meta['priced'], $meta['unpriced'], $meta['total_value'], $meta['change'], $meta['change_pct'], $meta['no_previous_close']])
        ->toBe([1, 1, 0, '0.00', null, null, 1]);
});

it('keeps account B apart from A\'s variant keys: B naming them reads and changes nothing of A\'s, and A\'s figures stay put', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12', '950.00', '2026-10-11', '18.91', '1.99');
    AccountRows::price('ex5-17:reverse:-:-', '26.34', '2026-10-12', '27.00', '2026-10-11', '-0.66', '-2.44');
    AccountRows::hold($a, 'base1-4:holo:unlimited:-', 5);
    AccountRows::hold($a, 'ex5-17:reverse:-:-', 9999);
    $aBefore = t09eMe($this, $a)->json('data.portfolio');
    $heldBefore = AccountRows::held($a);

    // B deletes A's variant keys (nothing of B's to delete: 204, idempotent), then sets one of its own on the same key.
    $delete = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/base1-4:holo:unlimited:-');
    $put = AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 1]);
    $bFigures = t09eMe($this, $b)->json('data.portfolio');
    $bPage = t09ePage($this, $b);

    expect($delete->getStatusCode())->toBe(204)
        ->and($put->getStatusCode())->toBe(200)
        ->and(AccountRows::held($a))->toBe($heldBefore)
        ->and(t09eMe($this, $a)->json('data.portfolio'))->toBe($aBefore)
        ->and([$bFigures['count'], $bFigures['total_value'], $bFigures['change']])->toBe([1, '26.34', '-0.66'])
        ->and(array_column($bPage->json('data'), 'variant_key'))->toBe(['ex5-17:reverse:-:-'])
        ->and($bPage->json('data.0.quantity'))->toBe(1);
});

it('answers a signed-out visitor 401 on /v1/me and /v1/portfolio with no figure, and an unverified account its own empty estimate', function (): void {
    $out = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    $outPage = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
    $unverified = Accounts::user(['email' => 'new@example.com', 'email_verified_at' => null]);
    $other = Accounts::user(['email' => 'other@example.com']);
    AccountRows::price('base1-4:holo:unlimited:-', '10.00', '2026-10-12', '8.00', '2026-10-11', '2.00', '25.00');
    AccountRows::hold($other, 'base1-4:holo:unlimited:-', 2);

    $me = t09eMe($this, $unverified);

    expect([$out->getStatusCode(), $outPage->getStatusCode()])->toBe([401, 401])
        ->and((string) $out->getContent())->not->toContain('total_value')
        ->and((string) $outPage->getContent())->not->toContain('total_value')
        ->and($me->json('data.email_verified'))->toBeFalse()
        ->and([$me->json('data.portfolio.count'), $me->json('data.portfolio.total_value')])->toBe([0, null]);
});

it('refuses the demo\'s undo path on the server: re-adding a watchlist row, setting its alert and a quantity save change no row', function (): void {
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    foreach (array_keys(DemoAccount::PORTFOLIO) as $key) {
        AccountRows::price($key, '10.00', '2026-10-12', '8.00', '2026-10-11', '2.00', '25.00');
    }
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    $demo = User::query()->where('is_demo', true)->firstOrFail();
    $watched = AccountRows::watched($demo);
    $held = AccountRows::held($demo);
    $alerts = DB::table('watchlist_items')->where('user_id', $demo->id)->orderBy('variant_key')->get()->all();

    $watchPut = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/watchlist/zz9-9:holo:-:-');
    $alertPut = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/alerts/ex5-17:reverse:-:-', ['pct' => 20]);
    $holdPut = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 1]);
    $holdDelete = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/ex5-17:reverse:-:-');

    foreach ([$watchPut, $alertPut, $holdPut, $holdDelete] as $response) {
        expect($response->getStatusCode())->toBe(403)
            ->and($response->json('type'))->toBe(ProblemDetails::BASE.'demo-read-only');
    }
    expect(AccountRows::watched($demo))->toBe($watched)
        ->and(AccountRows::held($demo))->toBe($held)
        ->and(DB::table('watchlist_items')->where('user_id', $demo->id)->orderBy('variant_key')->get()->all())->toEqual($alerts)
        ->and(t09eMe($this, $demo)->json('data.portfolio.count'))->toBe(count($held));
});
