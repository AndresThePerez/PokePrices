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

// The portfolio's estimated total with the day's change and its card counts: GET /v1/portfolio's meta carries the
// change in dollars and percent against the previous close and how many priced holdings have no previous close, and
// GET /v1/me carries the same figures as data.portfolio, read in one statement, for the header. Every figure is
// Postgres numeric text, never a float. Every /v1 response is also validated against openapi/api.json by
// Tests\TestCase.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function t09Portfolio(object $test, User $user): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
}

function t09Me(object $test, User $user): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
}

/** @return array<string, mixed> the estimate fields of a portfolio meta, in the order the summary carries them */
function t09Figures(array $meta): array
{
    $keys = ['count', 'priced', 'unpriced', 'no_previous_close', 'total_value', 'change', 'change_pct', 'first_day', 'last_day', 'source', 'feed', 'basis', 'currency'];
    $figures = [];
    foreach ($keys as $key) {
        $figures[$key] = array_key_exists($key, $meta) ? $meta[$key] : 'absent';
    }

    return $figures;
}

/** D9's worked example with previous closes: Charizard ×1 up 18.91, Chimecho ×3 down 0.66 each, a first edition unpriced. */
function t09Worked(User $user): void
{
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12', '950.00', '2026-10-11', '18.91', '1.99');
    AccountRows::price('ex5-17:reverse:-:-', '26.34', '2026-10-12', '27.00', '2026-10-11', '-0.66', '-2.44');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);
    AccountRows::hold($user, 'base1-4:holo:1st:-', 2);
}

it('carries the day\'s change and the counts with the total: 1047.93, +16.93, 1.64 %, three cards, one without a price', function (): void {
    $user = Accounts::user();
    t09Worked($user);

    $response = t09Portfolio($this, $user);

    $response->assertOk();
    // 1 × (968.91 − 950.00) + 3 × (26.34 − 27.00) = 18.91 − 1.98 = 16.93, against 1 × 950.00 + 3 × 27.00 = 1031.00.
    expect($response->json('meta'))->toBe([
        'count' => 3, 'limit' => 100, 'total_value' => '1047.93', 'priced' => 2, 'unpriced' => 1,
        'change' => '16.93', 'change_pct' => '1.64', 'no_previous_close' => 0,
        'first_day' => '2026-10-12', 'last_day' => '2026-10-12', 'source' => 'tcgplayer', 'feed' => 'tcgcsv',
        'basis' => 'market', 'currency' => 'USD', 'degraded' => false,
    ]);
});

it('counts a holding with a close and no previous close in the total, not in the change, and says how many', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12', '950.00', '2026-10-11', '18.91', '1.99');
    AccountRows::price('ex5-17:reverse:-:-', '26.34', '2026-10-12');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);

    $meta = t09Portfolio($this, $user)->json('meta');

    expect([$meta['total_value'], $meta['change'], $meta['change_pct'], $meta['no_previous_close'], $meta['priced'], $meta['unpriced']])
        ->toBe(['1047.93', '18.91', '1.99', 1, 2, 0]);
});

it('multiplies quantities of 1 and of 9999 exactly', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '0.10', '2026-10-12', '0.20', '2026-10-11', '-0.10', '-50.00');
    AccountRows::price('ex5-17:reverse:-:-', '0.30', '2026-10-12', '0.10', '2026-10-11', '0.20', '200.00');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 9999);

    $meta = t09Portfolio($this, $user)->json('meta');

    // 0.10 + 9999 × 0.30 = 2999.80; −0.10 + 9999 × 0.20 = 1999.70 against 0.20 + 9999 × 0.10 = 1000.10: 199.95 %.
    expect([$meta['total_value'], $meta['change'], $meta['change_pct']])->toBe(['2999.80', '1999.70', '199.95'])
        ->and((string) t09Portfolio($this, $user)->getContent())->toContain('"change":"1999.70"');
});

it('values the largest close the column holds times 9999 with no overflow and no float drift', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '9999999999.99', '2026-10-12', '9999999999.98', '2026-10-11', '0.01', '0.00');
    AccountRows::price('ex5-17:reverse:-:-', '9999999999.99', '2026-10-12', '0.01', '2026-10-11', '9999999999.98', null);
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 9999);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 9999);

    $response = t09Portfolio($this, $user);
    $meta = $response->json('meta');

    // Total 2 × 9999 × 9999999999.99; change 9999 × 0.01 + 9999 × 9999999999.98; previous 9999 × 9999999999.98 + 9999 × 0.01.
    expect($meta['total_value'])->toBe('199979999999800.02')
        ->and($meta['change'])->toBe('99989999999900.01')
        ->and($meta['change_pct'])->toBe('100.00')
        ->and((string) $response->getContent())->toContain('"change":"99989999999900.01"');
});

it('answers an empty portfolio with no total, no change, no percent and zero counts', function (): void {
    $meta = t09Portfolio($this, Accounts::user())->json('meta');

    expect(t09Figures($meta))->toBe([
        'count' => 0, 'priced' => 0, 'unpriced' => 0, 'no_previous_close' => 0, 'total_value' => null, 'change' => null,
        'change_pct' => null, 'first_day' => null, 'last_day' => null, 'source' => 'tcgplayer', 'feed' => 'tcgcsv',
        'basis' => 'market', 'currency' => 'USD',
    ]);
});

it('answers a portfolio whose only holding is unpriced with no change and no percent, and no division by zero', function (): void {
    $user = Accounts::user();
    AccountRows::hold($user, 'base1-4:holo:1st:-', 2);

    $response = t09Portfolio($this, $user);

    $response->assertOk();
    expect(t09Figures($response->json('meta')))->toBe([
        'count' => 1, 'priced' => 0, 'unpriced' => 1, 'no_previous_close' => 0, 'total_value' => null, 'change' => null,
        'change_pct' => null, 'first_day' => null, 'last_day' => null, 'source' => 'tcgplayer', 'feed' => 'tcgcsv',
        'basis' => 'market', 'currency' => 'USD',
    ]);
});

it('gives a change but no percent when the previous total is 0.00, never an error', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '1.50', '2026-10-12', '0.00', '2026-10-11', '1.50', null);
    AccountRows::price('ex5-17:reverse:-:-', '0.25', '2026-10-12', '0.00', '2026-10-11', '0.25', null);
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 2);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 4);

    $response = t09Portfolio($this, $user);
    $me = t09Me($this, $user);

    $response->assertOk();
    expect([$response->json('meta.total_value'), $response->json('meta.change'), $response->json('meta.change_pct')])->toBe(['4.00', '4.00', null])
        ->and([$me->json('data.portfolio.change'), $me->json('data.portfolio.change_pct')])->toBe(['4.00', null]);
});

it('keeps the percent over the holdings with a nonzero previous total when one previous close is 0.00', function (): void {
    $user = Accounts::user();
    AccountRows::price('base1-4:holo:unlimited:-', '1.50', '2026-10-12', '0.00', '2026-10-11', '1.50', null);
    AccountRows::price('ex5-17:reverse:-:-', '12.00', '2026-10-12', '10.00', '2026-10-11', '2.00', '20.00');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 1);

    $meta = t09Portfolio($this, $user)->json('meta');

    // (1.50 + 2.00) / (0.00 + 10.00) = 35.00 %.
    expect([$meta['change'], $meta['change_pct']])->toBe(['3.50', '35.00']);
});

it('rounds the percent once, half away from zero, from the exact sums', function (): void {
    $user = Accounts::user();
    // The rows' own percents (0.33 and −87.50) are never added: the percent comes from the summed dollars.
    AccountRows::price('base1-4:holo:unlimited:-', '3.01', '2026-10-12', '3.00', '2026-10-11', '0.01', '0.33');
    AccountRows::price('ex5-17:reverse:-:-', '0.01', '2026-10-12', '0.08', '2026-10-11', '-0.07', '-87.50');
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 1);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 1);

    $meta = t09Portfolio($this, $user)->json('meta');

    // (0.01 − 0.07) / (3.00 + 0.08) × 100 = −1.948… → −1.95.
    expect([$meta['change'], $meta['change_pct']])->toBe(['-0.06', '-1.95']);
});

/** One price_daily candle; a filled one carries n_obs 0 and filled_from the day before. */
function t09Candle(string $key, string $day, string $close, int $epoch = 1, bool $filled = false): void
{
    AccountRows::variant($key);
    DB::table('price_daily')->insert([
        'variant_key' => $key, 'source' => 'tcgplayer', 'day' => $day, 'series_epoch' => $epoch,
        'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'n_obs' => $filled ? 0 : 1, 'is_filled' => $filled,
        'filled_from' => $filled ? date('Y-m-d', strtotime($day.' -1 day')) : null, 'as_of' => "{$day} 20:05:45+00",
    ]);
}

it('uses the previous close each row shows: across a missing day, past a filled close, and none across a series-epoch change', function (): void {
    $user = Accounts::user();
    // A missing day: 2026-10-10, then nothing on 10-11, then 10-12.
    t09Candle('base1-4:holo:unlimited:-', '2026-10-10', '900.00');
    t09Candle('base1-4:holo:unlimited:-', '2026-10-12', '968.91');
    // A filled close on 10-11 between two real ones: the previous close is the real one of 10-10.
    t09Candle('ex5-17:reverse:-:-', '2026-10-10', '20.00');
    t09Candle('ex5-17:reverse:-:-', '2026-10-11', '20.00', 1, true);
    t09Candle('ex5-17:reverse:-:-', '2026-10-12', '26.34');
    // A series-epoch change between the two closes: no previous close.
    t09Candle('base1-4:holo:1st:-', '2026-10-11', '5000.00', 1);
    t09Candle('base1-4:holo:1st:-', '2026-10-12', '5500.00', 2);
    LatestPrices::rebuild(DB::connection());
    AccountRows::hold($user, 'base1-4:holo:unlimited:-', 2);
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 3);
    AccountRows::hold($user, 'base1-4:holo:1st:-', 1);

    $response = t09Portfolio($this, $user);
    $rows = [];
    foreach ($response->json('data') as $row) {
        $rows[$row['variant_key']] = $row;
    }
    $fromRows = '0.00';
    $previous = '0.00';
    foreach ($rows as $row) {
        if ($row['price']['previous_close'] !== null) {
            // Decimal arithmetic in Postgres numeric, from the strings each row shows.
            $fromRows = (string) DB::scalar('SELECT (?::numeric + ?::numeric * (?::numeric - ?::numeric))::numeric(20,2)::text', [$fromRows, $row['quantity'], $row['price']['close'], $row['price']['previous_close']]);
            $previous = (string) DB::scalar('SELECT (?::numeric + ?::numeric * ?::numeric)::numeric(20,2)::text', [$previous, $row['quantity'], $row['price']['previous_close']]);
        }
    }

    expect([$rows['base1-4:holo:unlimited:-']['price']['previous_close'], $rows['base1-4:holo:unlimited:-']['price']['previous_day']])->toBe(['900.00', '2026-10-10'])
        ->and([$rows['ex5-17:reverse:-:-']['price']['previous_close'], $rows['ex5-17:reverse:-:-']['price']['previous_day']])->toBe(['20.00', '2026-10-10'])
        ->and($rows['base1-4:holo:1st:-']['price']['previous_close'])->toBeNull()
        // 2 × 68.91 + 3 × 6.34 = 156.84 against 2 × 900.00 + 3 × 20.00 = 1860.00: 8.43 %.
        ->and($response->json('meta.change'))->toBe($fromRows)->toBe('156.84')
        ->and($previous)->toBe('1860.00')
        ->and($response->json('meta.change_pct'))->toBe('8.43')
        ->and($response->json('meta.no_previous_close'))->toBe(1)
        ->and($response->json('meta.total_value'))->toBe('7516.84');
});

it('answers GET /v1/me with the page\'s own figures as data.portfolio', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    t09Worked($user);
    AccountRows::price('ex13-3:reverse:-:-', '4.00', '2026-10-11');
    AccountRows::hold($user, 'ex13-3:reverse:-:-', 5);

    $page = t09Portfolio($this, $user)->json('meta');
    $me = t09Me($this, $user);

    $me->assertOk();
    expect(array_keys($me->json('data')))->toBe(['email', 'email_verified', 'two_factor', 'read_only', 'portfolio'])
        ->and($me->json('data.portfolio'))->toBe(t09Figures($page))
        ->and($me->json('data.portfolio'))->toBe([
            'count' => 4, 'priced' => 3, 'unpriced' => 1, 'no_previous_close' => 1, 'total_value' => '1067.93',
            'change' => '16.93', 'change_pct' => '1.64', 'first_day' => '2026-10-11', 'last_day' => '2026-10-12',
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD',
        ]);
});

it('reads the summary in one statement whatever the portfolio holds, and never calls PokéSearch', function (int $holdings): void {
    $user = Accounts::user();
    for ($i = 1; $i <= $holdings; $i++) {
        $key = "zz{$i}-1:holo:-:-";
        $i % 2 === 1 ? AccountRows::price($key, '1.25', '2026-10-12', '1.00', '2026-10-11', '0.25', '25.00') : AccountRows::variant($key);
        AccountRows::hold($user, $key, $i);
    }
    AccountRows::signIn($this, $user);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    $response->assertOk();
    $portfolio = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'portfolio_holdings')));
    expect($portfolio)->toHaveCount(1)
        ->and(count($queries))->toBeLessThanOrEqual(2)
        ->and(AccountRows::batches())->toBe([])
        ->and($response->json('data.portfolio.count'))->toBe($holdings);
})->with([0, 1, 100]);

it('answers GET /v1/me signed out exactly as before: the 401 problem, no portfolio', function (): void {
    $response = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and((string) $response->getContent())->not->toContain('portfolio');
});

it('keeps a second account apart: B never sees A\'s figures on /v1/me or /v1/portfolio, and B\'s writes leave A\'s as they were', function (): void {
    $a = Accounts::user(['email' => 'a@example.com']);
    $b = Accounts::user(['email' => 'b@example.com']);
    t09Worked($a);
    $aMe = t09Me($this, $a)->json('data.portfolio');
    $aPage = t09Portfolio($this, $a)->json('meta');

    $bMe = t09Me($this, $b)->json('data.portfolio');
    $bPage = t09Portfolio($this, $b)->json('meta');
    AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 7])->assertOk();
    AccountRows::signIn($this, $b)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/base1-4:holo:unlimited:-')->assertNoContent();
    $bAfter = t09Me($this, $b)->json('data.portfolio');

    expect([$bMe['count'], $bMe['total_value'], $bMe['change'], $bMe['change_pct']])->toBe([0, null, null, null])
        ->and([$bPage['count'], $bPage['total_value'], $bPage['change'], $bPage['change_pct']])->toBe([0, null, null, null])
        ->and([$bAfter['count'], $bAfter['total_value'], $bAfter['change']])->toBe([1, '184.38', '-4.62'])
        ->and(t09Me($this, $a)->json('data.portfolio'))->toBe($aMe)
        ->and(t09Portfolio($this, $a)->json('meta'))->toBe($aPage)
        ->and($aMe['total_value'])->toBe('1047.93');
});

it('lets the read-only demo read its own figures and still refuses every portfolio write', function (): void {
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    foreach (array_keys(DemoAccount::PORTFOLIO) as $key) {
        AccountRows::price($key, '10.00', '2026-10-12', '8.00', '2026-10-11', '2.00', '25.00');
    }
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    $demo = User::query()->where('is_demo', true)->firstOrFail();
    $person = Accounts::user(['email' => 'person@example.com']);
    AccountRows::price('base1-4:holo:unlimited:-', '968.91', '2026-10-12', '950.00', '2026-10-11', '18.91', '1.99');
    AccountRows::hold($person, 'base1-4:holo:unlimited:-', 4);
    AccountRows::hold($person, 'ex5-17:reverse:-:-', 1);
    $held = AccountRows::held($demo);

    $me = t09Me($this, $demo);
    $page = t09Portfolio($this, $demo)->json('meta');
    $put = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 9]);
    $delete = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/portfolio/ex5-17:reverse:-:-');

    // Eight cards of five variants at 10.00, each up 2.00 from 8.00: 80.00, +16.00 against 64.00, 25.00 %.
    expect($held)->toEqual(DemoAccount::PORTFOLIO)
        ->and($me->json('data.read_only'))->toBeTrue()
        ->and($me->json('data.portfolio'))->toBe(t09Figures($page))
        ->and([$me->json('data.portfolio.count'), $me->json('data.portfolio.total_value'), $me->json('data.portfolio.change'), $me->json('data.portfolio.change_pct')])
        ->toBe([5, '80.00', '16.00', '25.00'])
        ->and([$put->getStatusCode(), $put->json('type')])->toBe([403, ProblemDetails::BASE.'demo-read-only'])
        ->and([$delete->getStatusCode(), $delete->json('type')])->toBe([403, ProblemDetails::BASE.'demo-read-only'])
        ->and(AccountRows::held($demo))->toBe($held)
        ->and(AccountRows::held($person))->toBe(['base1-4:holo:unlimited:-' => 4, 'ex5-17:reverse:-:-' => 1]);
});

it('answers 422 for a bad quantity and 500 as problem+json on /v1/portfolio when the holdings cannot be read', function (): void {
    $user = Accounts::user();
    t09Worked($user);

    $bad = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 0]);
    DB::statement('ALTER TABLE portfolio_holdings RENAME TO portfolio_holdings_t09_gone');
    $page = t09Portfolio($this, $user);

    expect([$bad->getStatusCode(), $bad->json('type')])->toBe([422, ProblemDetails::BASE.'validation-failed'])
        ->and($page->getStatusCode())->toBe(500)
        ->and($page->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($page->json('type'))->toBe(ProblemDetails::BASE.'server-error')
        ->and((string) $page->getContent())->not->toContain('1047.93');
});
