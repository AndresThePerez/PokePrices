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

// A missing day older than pricing.freshness.missing_day_warning_days (14) calendar days, counted in UTC from the
// day of the clock, stays in missing_days but no longer makes the hourly price_freshness line a warning, and is no
// longer a problem of the price job; a missing day of 14 days or newer behaves as before.

/** Inserts one ingest run and returns its id; the marker is 20:05:44 UTC on the run's day. */
function fodRun(string $status, string $day, ?string $startedAt = null, ?string $finishedAt = null): int
{
    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => "{$day}T20:05:44Z", 'mode' => 'queued',
        'status' => $status, 'started_at' => $startedAt ?? "{$day}T21:40:00Z", 'finished_at' => $finishedAt, 'counts' => '{}',
    ]);
}

/** One ok run on every day from $first to $last except the days in $skip. */
function fodOkSpan(string $first, string $last, string ...$skip): void
{
    for ($day = CarbonImmutable::parse($first); $day->lessThanOrEqualTo(CarbonImmutable::parse($last)); $day = $day->addDay()) {
        $iso = $day->format('Y-m-d');
        if (! in_array($iso, $skip, true)) {
            fodRun('ok', $iso, null, "{$iso}T21:41:00Z");
        }
    }
}

/** @return list<MessageLogged> the price_freshness records of one run of the hourly job */
function fodLogged(): array
{
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records): void {
        if ($event->message === 'price_freshness') {
            $records[] = $event;
        }
    });
    (new LogFreshness)->handle();

    return array_values($records);
}

it('keeps a missing day 15 days old in missing_days but neither warns nor lists it as a problem', function (): void {
    fodOkSpan('2026-10-04', '2026-10-20', '2026-10-05');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T21:05:44Z'));

    $data = $this->getJson('/v1/prices/freshness')->assertOk()->json('data');
    $logged = fodLogged();

    expect(config('pricing.freshness.missing_day_warning_days'))->toBe(14)
        ->and($data['missing_days'])->toBe(['2026-10-05'])
        ->and($data['missing_days_count'])->toBe(1)
        ->and($data['within_slo'])->toBeTrue()
        ->and($logged)->toHaveCount(1)
        ->and($logged[0]->level)->toBe('info')
        ->and($logged[0]->context['missing_days'])->toBe(['2026-10-05'])
        ->and($logged[0]->context['missing_days_count'])->toBe(1)
        ->and(PriceJobProblems::all())->toBe([])
        ->and(PriceJobProblems::missingDays())->toBe(['2026-10-05']);
});

it('still warns for a missing day exactly 14 days old, until the UTC day turns', function (): void {
    fodOkSpan('2026-10-04', '2026-10-20', '2026-10-06');

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T23:59:59Z'));
    $fourteen = fodLogged();
    $problems = PriceJobProblems::all();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-21T00:00:00Z'));
    $fifteen = fodLogged();

    expect($fourteen[0]->level)->toBe('warning')
        ->and($fourteen[0]->context['missing_days'])->toBe(['2026-10-06'])
        ->and(array_column($problems, 'key'))->toBe(['missing_day:2026-10-06'])
        ->and($fifteen[0]->level)->toBe('info')
        ->and($fifteen[0]->context['within_slo'])->toBeTrue()
        ->and($fifteen[0]->context['missing_days'])->toBe(['2026-10-06'])
        ->and(PriceJobProblems::all())->toBe([]);
});

it('counts the age in calendar days across the end of a month', function (string $missing, string $level, array $keys): void {
    fodOkSpan('2026-02-10', '2026-03-01', $missing);
    Carbon::setTestNow(CarbonImmutable::parse('2026-03-01T21:05:44Z'));

    expect(fodLogged()[0]->level)->toBe($level)
        ->and(array_column(PriceJobProblems::all(), 'key'))->toBe($keys)
        ->and($this->getJson('/v1/prices/freshness')->json('data.missing_days'))->toBe([$missing]);
})->with([
    '14 days before 1 March' => ['2026-02-15', 'warning', ['missing_day:2026-02-15']],
    '15 days before 1 March' => ['2026-02-14', 'info', []],
]);

it('lets a new missing day decide while an old one is listed beside it', function (): void {
    fodOkSpan('2026-10-04', '2026-10-20', '2026-10-05', '2026-10-15');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-20T21:05:44Z'));

    $data = $this->getJson('/v1/prices/freshness')->json('data');
    $logged = fodLogged();

    expect($data['missing_days'])->toBe(['2026-10-05', '2026-10-15'])
        ->and($data['missing_days_count'])->toBe(2)
        ->and($logged[0]->level)->toBe('warning')
        ->and($logged[0]->context['missing_days'])->toBe(['2026-10-05', '2026-10-15'])
        ->and(array_column(PriceJobProblems::all(), 'key'))->toBe(['missing_day:2026-10-15']);
});

it('leaves a late newest day and a run stuck on an old day as they were', function (): void {
    fodOkSpan('2026-10-04', '2026-10-20', '2026-10-05');
    $stuck = fodRun('running', '2026-10-05', '2026-10-05T21:40:00Z');
    // 27.9 hours after the newest marker: past the limit of 26.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-22T00:00:00Z'));

    $logged = fodLogged();

    expect($logged[0]->level)->toBe('warning')
        ->and($logged[0]->context['within_slo'])->toBeFalse()
        ->and(array_column(PriceJobProblems::all(), 'key'))->toBe(['late:2026-10-20', "stuck:2026-10-05:run:{$stuck}"]);
});

it('posts a reported missing day cleared once when it turns 15 days old, and nothing after', function (): void {
    $url = 'https://discord.com/api/webhooks/123456789012345678/SENTINELTOKEN-0007';
    config(['alerts.discord.price_job_webhook_url' => $url]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    AlertRows::captureLogs();
    $posted = [];
    Http::fake(function (ClientRequest $request) use (&$posted) {
        $posted[] = (string) json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR)['content'];

        return Http::response('', 204);
    });
    fodOkSpan('2026-10-04', '2026-10-20', '2026-10-06');

    $runs = [];
    foreach (['2026-10-20T22:00:00Z', '2026-10-20T23:00:00Z', '2026-10-21T00:00:00Z', '2026-10-21T01:00:00Z', '2026-10-22T00:00:00Z'] as $at) {
        Carbon::setTestNow(CarbonImmutable::parse($at));
        $before = count($posted);
        Artisan::call('pokeprices:prices:report-problems');
        $runs[$at] = [trim(Artisan::output()), array_slice($posted, $before)];
    }

    expect($runs['2026-10-20T22:00:00Z'])->toBe(['{"posted":1,"cleared":0,"failed":0}', ['PokéPrices price job problem: No price run finished for 2026-10-06. Seen since 2026-10-20T22:00:00Z.']])
        ->and($runs['2026-10-20T23:00:00Z'])->toBe(['{"posted":0,"cleared":0,"failed":0}', []])
        ->and($runs['2026-10-21T00:00:00Z'])->toBe(['{"posted":0,"cleared":1,"failed":0}', ['PokéPrices price job problem cleared: No price run finished for 2026-10-06. That was seen since 2026-10-20T22:00:00Z and is over as of 2026-10-21T00:00:00Z.']])
        ->and($runs['2026-10-21T01:00:00Z'])->toBe(['{"posted":0,"cleared":0,"failed":0}', []])
        // A day later only the late newest day is new; the aged-out day does not start again.
        ->and($runs['2026-10-22T00:00:00Z'][1])->toBe(['PokéPrices price job problem: The newest prices are from 2026-10-20, which is past the limit of 26 hours. Seen since 2026-10-22T00:00:00Z.'])
        ->and(DB::table('reported_price_problems')->where('key', 'missing_day:2026-10-06')->count())->toBe(1)
        ->and(DB::table('reported_price_problems')->where('key', 'missing_day:2026-10-06')->whereNull('cleared_at')->count())->toBe(0);
});
