<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\LogFreshness;
use App\Domain\Pricing\PriceJobProblems;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// The age boundary of a missing price day: the 14-day line holds at both ends of a UTC calendar day, across a year, for
// a long run of missing days, after a re-collection, beside a refused folder, and for a configured number of 0 or less.

/** One ok run on every day from $first to $last except the days in $skip. */
function fodxOkSpan(string $first, string $last, string ...$skip): void
{
    for ($day = CarbonImmutable::parse($first); $day->lessThanOrEqualTo(CarbonImmutable::parse($last)); $day = $day->addDay()) {
        $iso = $day->format('Y-m-d');
        if (! in_array($iso, $skip, true)) {
            DB::table('ingest_runs')->insert([
                'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $iso, 'marker' => "{$iso}T20:05:44Z", 'mode' => 'queued',
                'status' => 'ok', 'started_at' => "{$iso}T21:40:00Z", 'finished_at' => "{$iso}T21:41:00Z", 'counts' => '{}',
            ]);
        }
    }
}

/** The level of the one price_freshness record the hourly job writes at the clock's instant. */
function fodxLevel(): string
{
    $levels = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$levels): void {
        if ($event->message === 'price_freshness') {
            $levels[] = $event->level;
        }
    });
    (new LogFreshness)->handle();

    return implode(',', $levels);
}

it('judges the 14-day line by the UTC date, at the first and the last second of a day', function (string $at, string $level, array $keys): void {
    // 13 days before 2026-10-20 is the 7th, 14 the 6th, 15 the 5th; each missing day is tried alone at its own span.
    fodxOkSpan('2026-10-01', '2026-10-20', '2026-10-07', '2026-10-06', '2026-10-05');
    Carbon::setTestNow(CarbonImmutable::parse($at));

    $answer = $this->getJson('/v1/prices/freshness')->assertOk()->json('data');

    expect(fodxLevel())->toBe($level)
        ->and(array_column(PriceJobProblems::all(), 'key'))->toBe($keys)
        ->and($answer['missing_days'])->toBe(['2026-10-05', '2026-10-06', '2026-10-07'])
        ->and($answer['missing_days_count'])->toBe(3);
})->with([
    'first second of the 20th' => ['2026-10-20T00:00:01Z', 'warning', ['missing_day:2026-10-06', 'missing_day:2026-10-07']],
    'last second of the 20th' => ['2026-10-20T23:59:59Z', 'warning', ['missing_day:2026-10-06', 'missing_day:2026-10-07']],
    'first second of the 21st' => ['2026-10-21T00:00:01Z', 'warning', ['missing_day:2026-10-07']],
    // 27.9 hours after the newest marker, so the newest day is late as well.
    'last second of the 21st' => ['2026-10-21T23:59:59Z', 'warning', ['late:2026-10-20', 'missing_day:2026-10-07']],
]);

it('counts a missing day across a year boundary as 14 days old, then 15', function (string $at, string $level, array $keys): void {
    fodxOkSpan('2025-12-20', '2026-01-15', '2025-12-31');
    Carbon::setTestNow(CarbonImmutable::parse($at));

    expect(fodxLevel())->toBe($level)
        ->and(array_column(PriceJobProblems::all(), 'key'))->toBe($keys)
        ->and(PriceJobProblems::missingDays())->toBe(['2025-12-31']);
})->with([
    '14 days on 14 January' => ['2026-01-14T12:00:00Z', 'warning', ['missing_day:2025-12-31']],
    '15 days on 15 January' => ['2026-01-15T12:00:00Z', 'info', []],
]);

it('lists twenty missing days in a row ending yesterday and lets only the newest fourteen decide', function (): void {
    // Ok on 29 September and on 20 October; 30 September to 19 October (20 days) have no ok run.
    fodxOkSpan('2026-09-29', '2026-09-29');
    fodxOkSpan('2026-10-20', '2026-10-20');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T21:05:44Z'));

    $answer = $this->getJson('/v1/prices/freshness')->assertOk()->json('data');
    $days = array_column(PriceJobProblems::all(), 'day');

    expect($answer['missing_days_count'])->toBe(20)
        ->and($answer['missing_days'])->toHaveCount(20)
        ->and($answer['missing_days'][0])->toBe('2026-09-30')
        ->and($answer['missing_days'][19])->toBe('2026-10-19')
        ->and($days)->toHaveCount(14)
        ->and($days[0])->toBe('2026-10-06')
        ->and($days[13])->toBe('2026-10-19')
        ->and(fodxLevel())->toBe('warning');
});

it('drops an old missing day from missing_days once a re-collection gives it an ok run', function (): void {
    fodxOkSpan('2026-10-01', '2026-10-20', '2026-10-05');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T21:05:44Z'));
    $before = $this->getJson('/v1/prices/freshness')->json('data.missing_days');

    fodxOkSpan('2026-10-05', '2026-10-05');
    $after = $this->getJson('/v1/prices/freshness')->json('data');

    expect($before)->toBe(['2026-10-05'])
        ->and($after['missing_days'])->toBe([])
        ->and($after['missing_days_count'])->toBe(0)
        ->and(PriceJobProblems::all())->toBe([])
        ->and(fodxLevel())->toBe('info');
});

it('keeps a refused folder of an aged-out missing day reported while only the missing-day entry is cleared', function (): void {
    $url = 'https://discord.com/api/webhooks/123456789012345678/SENTINELTOKEN-0007';
    config(['alerts.discord.price_job_webhook_url' => $url]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    AlertRows::captureLogs();
    $posted = [];
    Http::fake(function (ClientRequest $request) use (&$posted) {
        $posted[] = (string) json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['content'];

        return Http::response('', 204);
    });
    fodxOkSpan('2026-10-01', '2026-10-20', '2026-10-06');
    DB::table('ingest_runs')->insert([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-06', 'marker' => null, 'mode' => 'queued',
        'status' => 'refused', 'started_at' => '2026-10-07T08:40:00Z', 'finished_at' => '2026-10-07T08:40:01Z',
        'error' => 'missing_file:SUCCESS', 'counts' => '{}',
    ]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T22:00:00Z'));
    Artisan::call('pokeprices:prices:report-problems');
    $first = trim(Artisan::output());
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-21T00:00:00Z'));
    Artisan::call('pokeprices:prices:report-problems');
    $second = trim(Artisan::output());
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-22T00:00:00Z'));
    Artisan::call('pokeprices:prices:report-problems');
    $third = trim(Artisan::output());

    $open = DB::table('reported_price_problems')->whereNull('cleared_at')->pluck('key')->all();

    expect($first)->toBe('{"posted":2,"cleared":0,"failed":0}')
        ->and($second)->toBe('{"posted":0,"cleared":1,"failed":0}')
        ->and($third)->toBe('{"posted":1,"cleared":0,"failed":0}')
        ->and($open)->toHaveCount(2)
        ->and(implode("\n", $open))->toContain('refused:2026-10-06')
        ->and(implode("\n", $open))->not->toContain('missing_day')
        ->and(implode("\n", $posted))->not->toContain('SENTINELTOKEN-0007');
});

it('does not throw and never warns for a missing day when the configured number is 0 or negative', function (int $days, string $level, array $keys): void {
    config(['pricing.freshness.missing_day_warning_days' => $days]);
    fodxOkSpan('2026-10-01', '2026-10-20', '2026-10-19', '2026-10-10');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T12:00:00Z'));

    $answer = $this->getJson('/v1/prices/freshness')->assertOk()->json('data');

    expect(fodxLevel())->toBe($level)
        ->and(array_column(PriceJobProblems::all(), 'key'))->toBe($keys)
        ->and($answer['missing_days'])->toBe(['2026-10-10', '2026-10-19']);
})->with([
    'zero keeps only today' => [0, 'info', []],
    'minus three keeps none' => [-3, 'info', []],
    'one keeps yesterday' => [1, 'warning', ['missing_day:2026-10-19']],
]);
