<?php

declare(strict_types=1);

use App\Domain\Catalog\VerifyCatalog;
use Carbon\CarbonImmutable;
use Database\Seeders\SetAliasSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Testing\TestResponse;

// Adversarial edge tests for history_begins (plan P3-i1 T04, review round 1): cross-variant and cross-card bleed,
// epoch hostility, filled-only variants, every non-ok run state, the 3-query bound on a hostile shape, ETag behaviour
// after the body changed, and the raw key order. Own helpers (hbe prefix) so the file runs alone.

const HBE_DAYS = ['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-14'];

function hbeIngest(array $days): void
{
    foreach ($days as $day) {
        $code = Artisan::call('pokeprices:prices:ingest', ['--day' => $day, '--sync' => true, '--snapshots' => base_path('tests/Fixtures/tcgcsv-days/snapshots')]);
        if ($code !== 0) {
            throw new RuntimeException("the ingest of {$day} failed: ".Artisan::output());
        }
    }
}

function hbeRow(string $key, string $day, string $close, int $epoch, bool $filled): void
{
    DB::statement(<<<'SQL'
        INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
        VALUES (?, 'tcgplayer', ?::date, ?, ?, ?, ?, ?, ?, ?::boolean, ?::date, ?::date + interval '20 hours 5 minutes')
        SQL, [$key, $day, $epoch, $close, $close, $close, $close, $filled ? 0 : 1, $filled ? 'true' : 'false',
        $filled ? CarbonImmutable::parse($day)->subDay()->format('Y-m-d') : null, $day]);
}

/** @return array<string, string|null> variant_key => history_begins */
function hbeBegins(TestResponse $response): array
{
    $begins = [];
    foreach ($response->json('data') as $series) {
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

it('does not leak an older close of one variant into its prefix-sharing siblings or into another card', function (): void {
    hbeIngest(HBE_DAYS);
    // 'base1-4:holo:unlimited:-' is a string prefix of the black-dot key: an older close on the longer key must stay on it.
    hbeRow('base1-4:holo:unlimited:error-black-dot', '2026-08-01', '5.00', 1, false);
    // And an older close on the plain key must not reach the black-dot variant, the shadowless one or the other card.
    hbeRow('base1-4:holo:unlimited:-', '2026-07-01', '6.00', 1, false);

    $base = $this->getJson('/v1/cards/base1-4/prices?range=1W');
    $other = $this->getJson('/v1/cards/basep-8/prices?range=1W');

    expect(hbeBegins($base))->toBe([
        'base1-4:holo:1st:-' => null,
        'base1-4:holo:shadowless:-' => '2026-10-09',
        'base1-4:holo:unlimited:-' => '2026-07-01',
        'base1-4:holo:unlimited:error-black-dot' => '2026-08-01',
    ])->and(hbeBegins($other))->toBe(['basep-8:holo:-:-' => '2026-10-09']);
});

it('takes the earliest real day over every series_epoch, with epochs out of day order', function (): void {
    hbeIngest(HBE_DAYS);
    $key = 'basep-8:holo:-:-';
    // Epoch 3 is the oldest day, epoch 2 sits between, the ingested epoch-1 rows are the newest: min(day) across epochs.
    hbeRow($key, '2026-08-01', '3.00', 3, false);
    hbeRow($key, '2026-09-05', '4.00', 2, false);

    $response = $this->getJson('/v1/cards/basep-8/prices?range=1Y');
    $series = $response->json('data.0');

    expect($series['history_begins'])->toBe('2026-08-01')
        // The points keep their own epochs; history_begins merges nothing into them.
        ->and(array_values(array_unique(array_column($series['points'], 'series_epoch'))))->toBe([3, 2, 1])
        ->and($series['points'][0])->toMatchArray(['day' => '2026-08-01', 'series_epoch' => 3]);
});

it('gives the first day of a variant that only ever had series_epoch 2 rows', function (): void {
    hbeIngest(['2026-10-09', '2026-10-10']);
    DB::table('price_daily')->where('variant_key', 'basep-8:holo:-:-')->update(['series_epoch' => 2]);
    hbeIngest(['2026-10-11']);

    $series = $this->getJson('/v1/cards/basep-8/prices?range=1W')->json('data.0');

    expect($series['history_begins'])->toBe('2026-10-09')
        ->and(array_values(array_unique(array_column($series['points'], 'series_epoch'))))->toContain(2);
});

it('gives null to a variant whose only rows are filled, and ignores a filled row older than the real ones', function (): void {
    hbeIngest(HBE_DAYS);
    // The 1st-edition variant holds nothing but filled rows (unusual in production, constructible).
    foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $day) {
        hbeRow('base1-4:holo:1st:-', $day, '7.00', 1, true);
    }

    $response = $this->getJson('/v1/cards/base1-4/prices?range=1M');
    $first = collect($response->json('data'))->firstWhere('variant_key', 'base1-4:holo:1st:-');

    expect($first['points'])->not->toBe([])
        ->and(array_unique(array_column($first['points'], 'is_filled')))->toBe([true])
        ->and($first['history_begins'])->toBeNull();
});

it('gives null on every series for every state of the newest run other than an ok run with a marker', function (string $status, ?string $marker): void {
    hbeIngest(HBE_DAYS);
    // Every ingested run becomes superseded, then one run in the state under test is the newest of all.
    DB::table('ingest_runs')->update(['status' => 'superseded']);
    DB::table('ingest_runs')->insert(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-14', 'marker' => $marker,
        'mode' => 'sync', 'status' => $status, 'started_at' => '2026-10-14 20:10:00+00', 'finished_at' => null]);

    foreach (['base1-4', 'basep-8'] as $card) {
        $response = $this->getJson("/v1/cards/{$card}/prices?range=1Y");

        $response->assertOk();
        expect($response->json('meta.to'))->toBeNull()
            ->and(array_unique(array_values(hbeBegins($response)), SORT_REGULAR))->toBe([null]);
    }
})->with([
    'failed with a marker' => ['failed', '2026-10-14 20:05:00+00'],
    'running with a marker' => ['running', '2026-10-14 20:05:00+00'],
    'refused' => ['refused', '2026-10-14 20:05:00+00'],
    'ok without a marker' => ['ok', null],
]);

it('answers 200 and a history_begins far in the past in plain YYYY-MM-DD form', function (): void {
    hbeIngest(HBE_DAYS);
    hbeRow('basep-8:holo:-:-', '2001-02-03', '1.00', 1, false);

    $response = $this->getJson('/v1/cards/basep-8/prices?range=1W');

    $response->assertOk();
    expect($response->json('data.0.history_begins'))->toBe('2001-02-03');
});

it('still reads exactly 3 queries on a card whose four variants carry a year of mixed epochs', function (): void {
    hbeIngest(HBE_DAYS);
    foreach (['base1-4:holo:shadowless:-', 'base1-4:holo:unlimited:-', 'base1-4:holo:1st:-', 'base1-4:holo:unlimited:error-black-dot'] as $i => $key) {
        DB::statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            SELECT ?, 'tcgplayer', g.day::date, 1 + (extract(day FROM g.day)::integer % 4), 2.00, 2.00, 2.00, 2.00, 1, false, NULL,
                   g.day + interval '20 hours 5 minutes'
            FROM generate_series('2025-10-20'::date + (?::integer), '2026-10-05'::date, interval '1 day') AS g(day)
            ON CONFLICT DO NOTHING
            SQL, [$key, $i]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJson('/v1/cards/base1-4/prices?range=1Y');
    DB::disableQueryLog();

    $response->assertOk();
    expect(count(DB::getQueryLog()))->toBe(3)
        ->and(hbeBegins($response))->toBe([
            'base1-4:holo:1st:-' => '2025-10-22',
            'base1-4:holo:shadowless:-' => '2025-10-20',
            'base1-4:holo:unlimited:-' => '2025-10-21',
            'base1-4:holo:unlimited:error-black-dot' => '2025-10-23',
        ]);
});

it('does not answer 304 to an ETag of the body from before history_begins existed, and keeps a fresh ETag stable', function (): void {
    hbeIngest(HBE_DAYS);

    $first = $this->getJson('/v1/cards/basep-8/prices?range=1W');
    $body = json_decode((string) $first->getContent(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($body['data'] as $i => $series) {
        unset($body['data'][$i]['history_begins']);
    }
    $oldEtag = '"'.hash('xxh128', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'"';

    $stale = $this->getJson('/v1/cards/basep-8/prices?range=1W', ['If-None-Match' => $oldEtag]);
    $again = $this->getJson('/v1/cards/basep-8/prices?range=1W');
    $fresh = $this->getJson('/v1/cards/basep-8/prices?range=1W', ['If-None-Match' => (string) $first->headers->get('ETag')]);

    expect($oldEtag)->not->toBe((string) $first->headers->get('ETag'))
        ->and($stale->getStatusCode())->toBe(200)
        ->and($stale->headers->get('ETag'))->toBe($first->headers->get('ETag'))
        ->and($again->headers->get('ETag'))->toBe($first->headers->get('ETag'))
        ->and($fresh->getStatusCode())->toBe(304)
        ->and($first->headers->get('Cache-Control'))->toContain('max-age=300')
        ->and($first->headers->get('Cache-Control'))->toContain('stale-while-revalidate=600');
});

it('writes history_begins between treatment and points in the raw JSON of every series, empty card included', function (): void {
    hbeIngest(HBE_DAYS);

    $raw = (string) $this->getJson('/v1/cards/base1-4/prices?range=1W')->getContent();
    $count = preg_match_all('/"treatment":"[^"]*","history_begins":(null|"\d{4}-\d{2}-\d{2}"),"points":\[/', $raw);
    $empty = $this->getJson('/v1/cards/zz9-9/prices?range=1W');

    expect($count)->toBe(4)
        ->and(substr_count($raw, '"history_begins"'))->toBe(4)
        ->and($empty->getStatusCode())->toBe(200)
        ->and($empty->json('data'))->toBe([]);
});
