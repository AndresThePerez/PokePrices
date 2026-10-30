<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use App\Http\Middleware\ConditionalGet;
use App\Http\Problems\ProblemDetails;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\ForbiddenWords;

// GET /v1/cards/{id}/prices?range= (plan P2-i1 T11, review F9, R177(8)): the daily candles of each variant of a card
// from price_daily, the window anchored on the newest ok ingest run, never the clock. The map is built from the P1
// fixture day and the synthetic days of tests/Fixtures/tcgcsv-days are ingested with --sync. PokéSearch is never
// called (stray requests fail). Every /v1 response is also validated against openapi/api.json by Tests\TestCase (C3).

const T11_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

/** Ingests synthetic $days in order with --sync. */
function t11Ingest(array $days): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => base_path('tests/Fixtures/tcgcsv-days/snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

/** The series of the response with $key. */
function t11Series(TestResponse $response, string $key): array
{
    foreach ($response->json('data') as $series) {
        if ($series['variant_key'] === $key) {
            return $series;
        }
    }
    throw new RuntimeException("no series {$key} in the response");
}

/** One point as the API serves it. */
function t11Point(string $day, string $close, ?string $filledFrom, string $asOf, int $epoch = 1): array
{
    return ['day' => $day, 'close' => $close, 'is_filled' => $filledFrom !== null, 'filled_from' => $filledFrom, 'as_of' => $asOf, 'series_epoch' => $epoch];
}

/** Inserts $count variants of $cardId, each with one daily row per day of [$to - $days + 1, $to], and an ok run for $to. */
function t11Synthetic(string $cardId, int $count, int $days, string $to): void
{
    $n = 0;
    foreach (['-', 'pokeball'] as $treatment) {
        foreach (['-', '1st', 'unlimited', 'shadowless'] as $edition) {
            foreach (['normal', 'holo', 'reverse'] as $finish) {
                if ($n++ < $count) {
                    DB::table('card_variants')->insert(['variant_key' => "{$cardId}:{$finish}:{$edition}:{$treatment}", 'card_id' => $cardId,
                        'finish' => $finish, 'edition' => $edition, 'treatment' => $treatment, 'catalog_status' => 'unverified']);
                }
            }
        }
    }
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        SELECT v.variant_key, 'tcgplayer', g.day::date, 1, 1.00, 1.00, 1.00, 1.00, 1, false, NULL, g.day + interval '20 hours 5 minutes'
        FROM card_variants v
        CROSS JOIN generate_series(?::date - (?::integer - 1), ?::date, interval '1 day') AS g(day)
        WHERE v.card_id = ?
        SQL, [$to, $days, $to, $cardId]);
    DB::table('ingest_runs')->insert(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $to, 'marker' => $to.' 20:05:00+00',
        'mode' => 'sync', 'status' => 'ok', 'started_at' => $to.' 20:10:00+00', 'finished_at' => $to.' 20:11:00+00']);
}

/** @return list<string> the Cache-Control directives, sorted */
function t11Directives(TestResponse $response): array
{
    $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    sort($directives);

    return $directives;
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
    // Prices come from Postgres only: any HTTP call, PokéSearch included, fails the test.
});

it('adds the history policy to ConditionalGet and leaves the search and card policies unchanged', function (): void {
    expect(ConditionalGet::POLICIES)->toBe([
        'search' => 'public, max-age=60, s-maxage=300',
        'card' => 'public, max-age=60, stale-while-revalidate=300',
        'history' => 'public, max-age=300, stale-while-revalidate=600',
    ]);
});

it('serves the exact 1W points of a card, a filled point carrying its filled_from and the original as_of', function (): void {
    t11Ingest(T11_DAYS);

    $response = $this->getJson('/v1/cards/basep-13/prices?range=1W');

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [[
            'variant_key' => 'basep-13:holo:-:-',
            'finish' => 'holo',
            'edition' => '-',
            'treatment' => '-',
            'history_begins' => '2026-10-09',
            'points' => [
                t11Point('2026-10-09', '12.00', null, '2026-10-09T20:05:41Z'),
                t11Point('2026-10-10', '12.20', null, '2026-10-10T20:05:42Z'),
                t11Point('2026-10-11', '12.40', null, '2026-10-11T20:05:45Z'),
                // Absent on 10-12 and no folder on 10-13: both repeat the 10-11 close with its marker (D3).
                t11Point('2026-10-12', '12.40', '2026-10-11', '2026-10-11T20:05:45Z'),
                t11Point('2026-10-13', '12.40', '2026-10-11', '2026-10-11T20:05:45Z'),
                t11Point('2026-10-14', '12.90', null, '2026-10-14T20:05:46Z'),
            ],
        ]],
        'meta' => [
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
            'range' => '1W', 'from' => '2026-10-08', 'to' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z',
        ],
    ]);
});

it('lists every variant of the card by variant_key, a variant without candles with no points', function (): void {
    t11Ingest(T11_DAYS);

    $response = $this->getJson('/v1/cards/base1-4/prices?range=1W');

    $response->assertOk();
    $keys = array_column($response->json('data'), 'variant_key');
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    expect($keys)->toBe(['base1-4:holo:1st:-', 'base1-4:holo:shadowless:-', 'base1-4:holo:unlimited:-', 'base1-4:holo:unlimited:error-black-dot'])
        ->and($keys)->toBe($sorted)
        ->and(t11Series($response, 'base1-4:holo:unlimited:error-black-dot')['points'])->toBe([])
        ->and(array_column(t11Series($response, 'base1-4:holo:unlimited:-')['points'], 'close'))->toBe(['69.50', '70.10', '70.81', '71.20', '71.20', '72.00'])
        ->and(t11Series($response, 'base1-4:holo:unlimited:-')['points'][4])->toBe(t11Point('2026-10-13', '71.20', '2026-10-12', '2026-10-12T20:05:44Z'));
});

it('shows an epoch change as a series_epoch step', function (): void {
    t11Ingest(['2026-10-09', '2026-10-10']);
    DB::table('source_product_map')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    t11Ingest(['2026-10-11', '2026-10-12', '2026-10-14']);

    $points = t11Series($this->getJson('/v1/cards/basep-8/prices?range=1W'), 'basep-8:holo:-:-')['points'];

    expect(array_map(fn (array $p): array => [$p['day'], $p['series_epoch'], $p['is_filled']], $points))->toBe([
        ['2026-10-09', 1, false],
        ['2026-10-10', 1, false],
        ['2026-10-11', 2, false],
        ['2026-10-12', 2, false],
        ['2026-10-13', 2, true],
        ['2026-10-14', 2, false],
    ]);
});

it('computes every range back from the newest ok run, YTD from January 1 of its year', function (string $range, string $from): void {
    t11Ingest(T11_DAYS);

    $response = $this->getJson("/v1/cards/base1-4/prices?range={$range}");

    $response->assertOk();
    expect($response->json('meta'))->toMatchArray(['range' => $range, 'from' => $from, 'to' => '2026-10-14', 'as_of' => '2026-10-14T20:05:46Z'])
        // Every collected day lies inside each range: YTD crosses no year.
        ->and(array_column(t11Series($response, 'base1-4:holo:unlimited:-')['points'], 'day'))
        ->toBe(['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14']);
})->with([
    '1W' => ['1W', '2026-10-08'],
    '1M' => ['1M', '2026-09-15'],
    '3M' => ['3M', '2026-07-17'],
    'YTD' => ['YTD', '2026-01-01'],
    '1Y' => ['1Y', '2025-10-15'],
]);

it('defaults to 1M', function (): void {
    t11Ingest(T11_DAYS);

    expect($this->getJson('/v1/cards/basep-8/prices')->json('meta'))->toMatchArray(['range' => '1M', 'from' => '2026-09-15', 'to' => '2026-10-14']);
});

it('anchors the window on the newest ok run, never the clock', function (): void {
    t11Ingest(['2026-10-09', '2026-10-10', '2026-10-11']);
    $this->travelTo(CarbonImmutable::parse('2027-03-01T12:00:00Z'));

    $response = $this->getJson('/v1/cards/basep-8/prices?range=1W');

    expect($response->json('meta'))->toMatchArray(['from' => '2026-10-05', 'to' => '2026-10-11', 'as_of' => '2026-10-11T20:05:45Z'])
        ->and(array_column(t11Series($response, 'basep-8:holo:-:-')['points'], 'day'))->toBe(['2026-10-09', '2026-10-10', '2026-10-11']);
});

it('keeps only the rows in [from, to]', function (): void {
    t11Ingest(T11_DAYS);
    $root = '2026-10-14';
    // An old real candle 7 days before to falls outside 1W and inside 1M.
    DB::table('price_daily')->insert(['variant_key' => 'basep-8:holo:-:-', 'source' => 'tcgplayer', 'day' => '2026-10-07', 'series_epoch' => 1,
        'open' => '20.00', 'high' => '20.00', 'low' => '20.00', 'close' => '20.00', 'n_obs' => 1, 'is_filled' => false, 'filled_from' => null,
        'as_of' => '2026-10-07 20:05:40+00']);

    $week = array_column(t11Series($this->getJson('/v1/cards/basep-8/prices?range=1W'), 'basep-8:holo:-:-')['points'], 'day');
    $month = array_column(t11Series($this->getJson('/v1/cards/basep-8/prices?range=1M'), 'basep-8:holo:-:-')['points'], 'day');

    expect($week[0])->toBe('2026-10-09')
        ->and(end($week))->toBe($root)
        ->and($month[0])->toBe('2026-10-07')
        // The gap on 10-08 stays a gap: no invented day.
        ->and($month[1])->toBe('2026-10-09');
});

it('answers nulls and empty series before any day is ingested', function (): void {
    $response = $this->getJson('/v1/cards/basep-8/prices?range=1W');

    $response->assertOk();
    expect($response->json())->toBe([
        'data' => [['variant_key' => 'basep-8:holo:-:-', 'finish' => 'holo', 'edition' => '-', 'treatment' => '-', 'history_begins' => null, 'points' => []]],
        'meta' => [
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
            'range' => '1W', 'from' => null, 'to' => null, 'as_of' => null,
        ],
    ]);
});

it('does not count a superseded, failed or refused run as the newest ok run', function (): void {
    t11Ingest(['2026-10-09', '2026-10-10']);
    foreach (['superseded', 'failed', 'refused'] as $status) {
        DB::table('ingest_runs')->insert(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-20', 'marker' => '2026-10-20 20:05:00+00',
            'mode' => 'sync', 'status' => $status, 'started_at' => '2026-10-20 20:10:00+00']);
    }

    expect($this->getJson('/v1/cards/basep-8/prices?range=1W')->json('meta'))->toMatchArray(['to' => '2026-10-10', 'as_of' => '2026-10-10T20:05:42Z']);
});

it('answers a valid id PokéPrices holds no variants for with 200 and data [], never calling PokéSearch', function (): void {
    t11Ingest(T11_DAYS);

    $response = $this->getJson('/v1/cards/zz9-999/prices?range=1W');

    $response->assertOk();
    expect($response->json('data'))->toBe([])
        ->and($response->json('meta'))->toMatchArray(['from' => '2026-10-08', 'to' => '2026-10-14']);
    Http::assertNothingSent();
});

it('answers 404 card-not-found for an id outside the card id pattern', function (): void {
    $response = $this->getJson('/v1/cards/-bad/prices');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'card-not-found');
});

it('answers 422 naming range for anything but 1W, 1M, 3M, YTD and 1Y; there is no 1D', function (string $query): void {
    t11Ingest(['2026-10-11']);

    $response = $this->getJson("/v1/cards/basep-8/prices?{$query}");

    expect($response->getStatusCode())->toBe(422)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toHaveKey('range');
})->with([
    '1D' => ['range=1D'],
    '1d' => ['range=1d'],
    '24H' => ['range=24H'],
    'empty' => ['range='],
    'lower-case 1w' => ['range=1w'],
    'ALL' => ['range=ALL'],
    'an array' => ['range[]=1W'],
]);

it('sends a body-hash ETag with the history policy and answers a matching If-None-Match with 304', function (): void {
    t11Ingest(['2026-10-10', '2026-10-11']);

    $first = $this->getJson('/v1/cards/basep-8/prices?range=1W');
    $etag = (string) $first->headers->get('ETag');
    $matched = $this->getJson('/v1/cards/basep-8/prices?range=1W', ['If-None-Match' => $etag]);

    expect($first->getStatusCode())->toBe(200)
        ->and($etag)->toBe('"'.hash('xxh128', (string) $first->getContent()).'"')
        ->and(t11Directives($first))->toBe(['max-age=300', 'public', 'stale-while-revalidate=600'])
        ->and($matched->getStatusCode())->toBe(304)
        ->and((string) $matched->getContent())->toBe('')
        ->and($matched->headers->get('ETag'))->toBe($etag);

    t11Ingest(['2026-10-12']);
    $after = $this->getJson('/v1/cards/basep-8/prices?range=1W', ['If-None-Match' => $etag]);
    expect($after->getStatusCode())->toBe(200)
        ->and($after->headers->get('ETag'))->not->toBe($etag);
});

it('reads the history with exactly 3 queries at 1 variant x 7 points and 24 variants x 365 points', function (): void {
    t11Synthetic('zz1-1', 1, 7, '2026-10-14');
    t11Synthetic('zz1-2', 24, 365, '2026-10-14');

    $counts = [];
    foreach (['zz1-1' => [1, 7, '1W'], 'zz1-2' => [24, 365, '1Y']] as $id => [$variants, $points, $range]) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson("/v1/cards/{$id}/prices?range={$range}");
        DB::disableQueryLog();
        $response->assertOk();
        expect($response->json('data'))->toHaveCount($variants)
            ->and(array_map(fn (array $s): int => count($s['points']), $response->json('data')))->toBe(array_fill(0, $variants, $points));
        $counts[$id] = count(DB::getQueryLog());
    }

    expect($counts)->toBe(['zz1-1' => 3, 'zz1-2' => 3]);
});

it('routes the history outside the PokéSearch group with throttle:public-read and conditional-get:history', function (): void {
    $route = Route::getRoutes()->getByName('cards.prices');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('v1/cards/{id}/prices')
        ->and($route->gatherMiddleware())->toContain('throttle:public-read')
        ->and($route->gatherMiddleware())->toContain('conditional-get:history')
        ->and($route->getAction('pokesearch'))->toBeNull();
});

it('documents the operation: the range enum, no upstream errors and the data [] meaning of a valid id', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $operation = $spec['paths']['/cards/{id}/prices']['get'];
    $range = collect($operation['parameters'])->firstWhere('name', 'range');
    $description = (string) $operation['description'];

    expect(array_keys($operation['responses']))->toBe([200, 304, 404, 422, 429])
        ->and($range['in'])->toBe('query')
        ->and($range['schema']['enum'])->toBe(['1W', '1M', '3M', 'YTD', '1Y'])
        ->and($description)->toContain('data: []')
        ->and($description)->toContain('GET /v1/cards/{id}')
        ->and(ForbiddenWords::payloadMatches($description))->toBe([]);
});
