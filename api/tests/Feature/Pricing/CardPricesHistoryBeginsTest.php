<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;
use Tests\Support\ContractValidator;

// history_begins on each series of GET /v1/cards/{id}/prices (plan P3-i1 T04, D5, review F-P3-03): the day of the
// variant's earliest real price_daily close (is_filled false) in any series_epoch over all days, independent of the
// range; null without a real close, and null on every series while there is no ok run. The map is built from the P1
// fixture day and the synthetic days of tests/Fixtures/tcgcsv-days are ingested with --sync; older rows are inserted
// straight into price_daily. PokéSearch is never called. Every /v1 response is validated against openapi/api.json (C3).

const HB_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

const HB_RANGES = ['1W', '1M', '3M', 'YTD', '1Y'];

/** Ingests synthetic $days in order with --sync. */
function hbIngest(array $days): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => base_path('tests/Fixtures/tcgcsv-days/snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** Inserts one real close of $key on $day. */
function hbReal(string $key, string $day, string $close, int $epoch = 1): void
{
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
        SQL, [$key, $day, $epoch, $close, $close, $close, $close, $day]);
}

/** Inserts one filled row of $key on $day repeating the close of $from. */
function hbFilled(string $key, string $day, string $from, string $close): void
{
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        VALUES (?, 'tcgplayer', ?::date, 1, ?, ?, ?, ?, 0, true, ?::date, ?::date + interval '20 hours 5 minutes')
        SQL, [$key, $day, $close, $close, $close, $close, $from, $from]);
}

/** The series of the response with $key. */
function hbSeries(TestResponse $response, string $key): array
{
    foreach ($response->json('data') as $series) {
        if ($series['variant_key'] === $key) {
            return $series;
        }
    }
    throw new RuntimeException("no series {$key} in the response");
}

/** @return array<string, string|null> variant_key => history_begins of every series of the response */
function hbBegins(TestResponse $response): array
{
    $begins = [];
    foreach ($response->json('data') as $series) {
        expect($series)->toHaveKey('history_begins');
        $begins[$series['variant_key']] = $series['history_begins'];
    }

    return $begins;
}

beforeEach(function (): void {
    config(['cache.limiter' => 'array']);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    Queue::fake([VerifyCatalog::class]);
    $this->seed(SetAliasSeeder::class);
    $code = Artisan::call('pokeprices:map:build', [
        '--day' => '2026-10-11',
        '--dataset' => base_path('tests/Fixtures/tcgcsv/dataset'),
        '--snapshots' => base_path('tests/Fixtures/tcgcsv/snapshots'),
    ]);
    if ($code !== 0) {
        throw new RuntimeException('the fixture map build failed: '.Artisan::output());
    }
    Http::preventStrayRequests();
});

it('places history_begins after treatment and before points', function (): void {
    hbIngest(HB_DAYS);

    $series = hbSeries($this->getJson('/v1/cards/basep-8/prices?range=1W'), 'basep-8:holo:-:-');

    expect(array_keys($series))->toBe(['variant_key', 'finish', 'edition', 'treatment', 'history_begins', 'points']);
});

it('gives a day before meta.from when the variant has real closes older than the window', function (): void {
    hbIngest(HB_DAYS);
    hbReal('basep-8:holo:-:-', '2026-09-20', '20.00');

    $response = $this->getJson('/v1/cards/basep-8/prices?range=1W');
    $series = hbSeries($response, 'basep-8:holo:-:-');

    $response->assertOk();
    expect($response->json('meta.from'))->toBe('2026-10-08')
        ->and($series['history_begins'])->toBe('2026-09-20')
        ->and($series['history_begins'] < $response->json('meta.from'))->toBeTrue()
        ->and($series['points'][0]['day'])->toBe('2026-10-09');
});

it('equals the first point\'s day when the first real close lies inside the window', function (): void {
    hbIngest(HB_DAYS);

    $series = hbSeries($this->getJson('/v1/cards/basep-8/prices?range=1W'), 'basep-8:holo:-:-');

    expect($series['history_begins'])->toBe('2026-10-09')
        ->and($series['points'][0]['day'])->toBe('2026-10-09')
        ->and($series['points'][0]['is_filled'])->toBeFalse();
});

it('gives the old first day, not the first point in range, after a gap longer than the 14-day fill cap', function (): void {
    hbIngest(HB_DAYS);
    $key = 'basep-8:holo:-:-';
    hbReal($key, '2026-09-01', '18.00');
    hbReal($key, '2026-09-02', '18.50');
    // The carry-forward of 09-02 for its 14 days, then nothing from 09-17 to 10-08 (22 days): a gap, no point.
    foreach (range(3, 16) as $d) {
        hbFilled($key, sprintf('2026-09-%02d', $d), '2026-09-02', '18.50');
    }

    $week = $this->getJson('/v1/cards/basep-8/prices?range=1W');
    $month = $this->getJson('/v1/cards/basep-8/prices?range=1M');

    expect(hbSeries($week, $key)['points'][0]['day'])->toBe('2026-10-09')
        ->and(hbSeries($week, $key)['history_begins'])->toBe('2026-09-01')
        // 1M starts on 09-15: its first points are filled rows of the old series, the first real close is still 09-01.
        ->and($month->json('meta.from'))->toBe('2026-09-15')
        ->and(hbSeries($month, $key)['points'][0])->toMatchArray(['day' => '2026-09-15', 'is_filled' => true])
        ->and(hbSeries($month, $key)['history_begins'])->toBe('2026-09-01');
});

it('never begins history on a filled row', function (): void {
    hbIngest(HB_DAYS);
    // A filled row older than every real close (its source close is not held): it is no collected close.
    hbFilled('basep-8:holo:-:-', '2026-10-05', '2026-10-01', '9.99');

    $series = hbSeries($this->getJson('/v1/cards/basep-8/prices?range=1M'), 'basep-8:holo:-:-');

    expect($series['points'][0])->toMatchArray(['day' => '2026-10-05', 'is_filled' => true])
        ->and($series['history_begins'])->toBe('2026-10-09');
});

it('keeps the epoch-1 first day after the mapping moves to series_epoch 2', function (): void {
    hbIngest(['2026-10-09', '2026-10-10']);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    hbIngest(['2026-10-11', '2026-10-12', '2026-10-14']);

    $series = hbSeries($this->getJson('/v1/cards/basep-8/prices?range=1W'), 'basep-8:holo:-:-');

    expect(array_map(fn (array $p): array => [$p['day'], $p['series_epoch']], $series['points']))->toContain(['2026-10-09', 1], ['2026-10-11', 2])
        ->and($series['history_begins'])->toBe('2026-10-09');
});

it('gives null to a variant with no rows', function (): void {
    hbIngest(HB_DAYS);

    $response = $this->getJson('/v1/cards/base1-4/prices?range=1Y');
    $empty = hbSeries($response, 'base1-4:holo:unlimited:error-black-dot');

    expect($empty['points'])->toBe([])
        ->and($empty['history_begins'])->toBeNull()
        ->and(hbSeries($response, 'base1-4:holo:unlimited:-')['history_begins'])->toBe('2026-10-09');
});

it('gives null on every series without an ok run, even with real rows left by a superseded run', function (): void {
    hbIngest(HB_DAYS);
    DB::table('ingest_runs')->update(['status' => 'superseded']);
    // The real closes are still in price_daily; only the ok run is gone.
    expect(DB::table('price_daily')->where('variant_key', 'base1-4:holo:unlimited:-')->where('is_filled', false)->count())->toBe(5);

    foreach (['base1-4', 'basep-8'] as $card) {
        $response = $this->getJson("/v1/cards/{$card}/prices?range=1W");

        $response->assertOk();
        $begins = hbBegins($response);
        expect($response->json('meta.to'))->toBeNull()
            ->and($begins)->not->toBe([])
            ->and(array_unique(array_values($begins), SORT_REGULAR))->toBe([null])
            ->and(array_merge(...array_column($response->json('data'), 'points')))->toBe([]);
    }
});

it('gives the same history_begins for all five ranges', function (): void {
    hbIngest(HB_DAYS);
    // Older than 1Y's from (2025-10-15): outside every window.
    hbReal('basep-8:holo:-:-', '2025-06-01', '15.00');

    $perRange = [];
    foreach (['basep-8', 'base1-4'] as $card) {
        foreach (HB_RANGES as $range) {
            $response = $this->getJson("/v1/cards/{$card}/prices?range={$range}");
            $response->assertOk();
            $perRange[$card][$range] = hbBegins($response);
        }
    }

    expect($perRange['basep-8'])->toBe(array_fill_keys(HB_RANGES, ['basep-8:holo:-:-' => '2025-06-01']))
        ->and(array_unique($perRange['base1-4'], SORT_REGULAR))->toHaveCount(1)
        ->and($perRange['base1-4']['1W'])->toMatchArray(['base1-4:holo:unlimited:-' => '2026-10-09', 'base1-4:holo:unlimited:error-black-dot' => null]);
});

it('still reads the history with exactly 3 queries while serving history_begins', function (): void {
    hbIngest(HB_DAYS);
    hbReal('basep-8:holo:-:-', '2025-06-01', '15.00');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/v1/cards/basep-8/prices?range=1W');
    DB::disableQueryLog();

    $response->assertOk();
    expect(count(DB::getQueryLog()))->toBe(3)
        ->and(hbSeries($response, 'basep-8:holo:-:-')['history_begins'])->toBe('2025-06-01');
});

it('documents history_begins as a required nullable date between treatment and points, and the range independence', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $series = $spec['components']['schemas']['CardPricesResource']['items'];
    $description = (string) $spec['paths']['/cards/{id}/prices']['get']['description'];

    expect(array_keys($series['properties']))->toBe(['variant_key', 'finish', 'edition', 'treatment', 'history_begins', 'points'])
        ->and($series['properties']['history_begins'])->toBe(['type' => ['string', 'null'], 'format' => 'date'])
        ->and($series['required'])->toContain('history_begins')
        ->and($series['additionalProperties'])->toBeFalse()
        ->and($description)->toContain("history_begins is the day of the variant's first collected close in any series epoch, or null when PokéPrices holds none; it does not depend on range.");
});

it('validates 200 bodies carrying history_begins against the contract (C3) and rejects one without it', function (): void {
    $validator = ContractValidator::forSpec(base_path('../openapi/api.json'));
    $meta = ['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
        'range' => '1W', 'from' => '2026-10-08', 'to' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z'];
    $series = ['variant_key' => 'basep-8:holo:-:-', 'finish' => 'holo', 'edition' => '-', 'treatment' => '-'];
    $body = fn (array $extra): JsonResponse => new JsonResponse(['data' => [$series + $extra + ['points' => []]], 'meta' => $meta]);

    expect($validator->validate('GET', '/v1/cards/basep-8/prices', $body(['history_begins' => '2026-10-09']))['validated'])->toBeTrue()
        ->and($validator->validate('GET', '/v1/cards/basep-8/prices', $body(['history_begins' => null]))['validated'])->toBeTrue()
        ->and($validator->validate('GET', '/v1/cards/basep-8/prices', $body([]))['validated'])->toBeFalse();
});
