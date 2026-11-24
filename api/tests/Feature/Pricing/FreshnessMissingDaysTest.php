<?php

declare(strict_types=1);

use App\Domain\Pricing\Freshness;
use App\Domain\Pricing\Jobs\LogFreshness;
use App\Http\Problems\ProblemDetails;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Facades\Redis;

// The freshness answer names the calendar days between the first and the newest ok day that have no ok run
// (missing_days, oldest first, the newest Freshness::MISSING_DAYS_CAP of them, with missing_days_count beside it), and
// the hourly price_freshness line is a warning while a day is missing.

/** One ok run on each of the days (marker 20:05:44 UTC), then the clock an hour after the newest marker. */
function fmdOk(string ...$days): void
{
    foreach ($days as $day) {
        DB::table('ingest_runs')->insert([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => "{$day}T20:05:44Z", 'mode' => 'queued',
            'status' => 'ok', 'started_at' => "{$day}T21:40:00Z", 'finished_at' => "{$day}T21:41:07Z", 'counts' => '{}',
        ]);
    }
    Carbon::setTestNow(CarbonImmutable::parse(max($days).'T21:05:44Z'));
}

/** @return list<MessageLogged> the price_freshness records one run of the hourly job logs */
function fmdLogged(): array
{
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records): void {
        if ($event->message === 'price_freshness') {
            $records[] = $event;
        }
    });
    (new LogFreshness)->handle();

    // A listener of an earlier call stays registered; it only fills that call's own list.
    return array_values($records);
}

it('answers an empty missing_days and a count of 0 when no day is missing, the earlier fields byte for byte', function (): void {
    fmdOk('2026-10-10', '2026-10-11', '2026-10-12');
    $runId = (int) DB::table('ingest_runs')->max('id');

    $response = $this->getJson('/v1/prices/freshness');

    $response->assertOk();
    expect((string) $response->getContent())->toBe(
        '{"data":{"source":"tcgplayer","feed":"tcgcsv","as_of":"2026-10-12T20:05:44Z","day":"2026-10-12","age_hours":1.0,'
        .'"slo_hours":26,"within_slo":true,"measured_at":"2026-10-12T21:05:44Z","last_run":{"id":'.$runId
        .',"status":"ok","mode":"queued","day":"2026-10-12","finished_at":"2026-10-12T21:41:07Z"},'
        .'"missing_days":[],"missing_days_count":0}}',
    );
});

it('answers an empty missing_days when no price day was ever ingested', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T21:05:44Z'));

    $data = $this->getJson('/v1/prices/freshness')->json('data');

    expect($data['missing_days'])->toBe([])
        ->and($data['missing_days_count'])->toBe(0)
        ->and($data['as_of'])->toBeNull();
});

it('names the missing days as ISO dates, oldest first, and leaves every other field as it was', function (): void {
    fmdOk('2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12');
    $whole = $this->getJson('/v1/prices/freshness')->json('data');
    DB::table('ingest_runs')->whereIn('day', ['2026-10-09', '2026-10-11'])->update(['status' => 'failed']);

    $response = $this->getJson('/v1/prices/freshness');
    $data = $response->json('data');

    $response->assertOk();
    expect($data['missing_days'])->toBe(['2026-10-09', '2026-10-11'])
        ->and($data['missing_days_count'])->toBe(2)
        ->and($data['within_slo'])->toBeTrue()
        ->and(array_diff_key($data, ['missing_days' => 1, 'missing_days_count' => 1]))
        ->toBe(array_diff_key($whole, ['missing_days' => 1, 'missing_days_count' => 1]))
        ->and((string) $response->getContent())->toContain('"missing_days":["2026-10-09","2026-10-11"],"missing_days_count":2}}');
});

it('caps missing_days at the newest thirty and still counts them all', function (): void {
    // 2026-08-02 to 2026-10-11 are missing: 71 days.
    fmdOk('2026-08-01', '2026-10-12');

    $data = $this->getJson('/v1/prices/freshness')->json('data');

    expect(Freshness::MISSING_DAYS_CAP)->toBe(30)
        ->and($data['missing_days'])->toHaveCount(30)
        ->and($data['missing_days'][0])->toBe('2026-09-12')
        ->and($data['missing_days'][29])->toBe('2026-10-11')
        ->and($data['missing_days'])->toBe(array_values(array_unique($data['missing_days'])))
        ->and($data['missing_days_count'])->toBe(71);
    $sorted = $data['missing_days'];
    sort($sorted, SORT_STRING);
    expect($data['missing_days'])->toBe($sorted);

    // Exactly the cap: nothing is cut.
    DB::table('ingest_runs')->where('day', '2026-08-01')->update(['day' => '2026-09-11', 'marker' => '2026-09-11T20:05:44Z']);
    $atCap = $this->getJson('/v1/prices/freshness')->json('data');
    expect($atCap['missing_days'])->toBe($data['missing_days'])
        ->and($atCap['missing_days_count'])->toBe(30);
});

it('prints the same missing days with --json', function (): void {
    fmdOk('2026-10-09', '2026-10-12');

    Artisan::call('pokeprices:prices:freshness', ['--json' => true]);
    $printed = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($printed['missing_days'])->toBe(['2026-10-10', '2026-10-11'])
        ->and($printed['missing_days_count'])->toBe(2)
        ->and($printed)->toBe($this->getJson('/v1/prices/freshness')->json('data'));
});

it('answers a real 429 once the public-read limit is spent and a real 503 while the limiter store is down', function (): void {
    fmdOk('2026-10-09', '2026-10-12');
    $forget = function (): void {
        app()->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
        Cache::forgetDriver(['redis', 'locks']);
        app()->forgetInstance(RateLimiter::class);
        RateLimiterFacade::clearResolvedInstance(RateLimiter::class);
    };
    $limiter = config('cache.limiter');
    // A limiter of this test's own, in memory: the store the suite shares is never spent.
    config(['cache.limiter' => 'array']);
    $forget();
    try {
        $this->getJson('/v1/prices/freshness')->assertOk();
        // The rest of this address's minute goes on the limiter itself, under the key the throttle middleware counts.
        $limit = (int) config('rate_limits.public-read.per_minute');
        for ($i = 1; $i < $limit; $i++) {
            app(RateLimiter::class)->hit(md5('public-read'.'127.0.0.1'), 60);
        }
        $spent = $this->getJson('/v1/prices/freshness');

        // The "locks" store on the "default" Redis role, pointed at an address nothing answers on.
        config(['cache.limiter' => 'locks', 'database.redis.default.host' => '192.0.2.10']);
        $forget();
        $down = $this->getJson('/v1/prices/freshness');
    } finally {
        config(['cache.limiter' => $limiter, 'database.redis.default.host' => env('REDIS_HOST', 'redis-queue')]);
        $forget();
    }

    expect($limit)->toBeGreaterThan(1)
        ->and($spent->getStatusCode())->toBe(429)
        ->and($spent->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($spent->headers->has('Retry-After'))->toBeTrue()
        ->and((string) $spent->getContent())->not->toContain('missing_days');

    expect($down->getStatusCode())->toBe(503)
        ->and($down->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($down->json('type'))->toBe(ProblemDetails::BASE.'http-503')
        ->and((string) $down->getContent())->not->toContain('missing_days');
});

it('documents missing_days and its count on the freshness answer of the committed contract', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $schema = $spec['components']['schemas']['PriceFreshnessResource'];

    expect($schema['properties']['missing_days'])->toBe(['type' => 'array', 'items' => ['type' => 'string']])
        ->and($schema['properties']['missing_days_count'])->toBe(['type' => 'integer'])
        ->and($schema['required'])->toContain('missing_days', 'missing_days_count')
        ->and($schema['additionalProperties'])->toBeFalse()
        ->and(array_keys($spec['paths']['/prices/freshness']['get']['responses']))->toBe([200, 429, 503])
        ->and($spec['paths']['/prices/freshness']['get']['description'])->toContain('missing_days');
});

it('logs price_freshness as one warning naming the missing days while within the SLO, and again with a new day', function (): void {
    fmdOk('2026-10-08', '2026-10-09', '2026-10-11', '2026-10-12');

    $first = fmdLogged();

    expect($first)->toHaveCount(1)
        ->and($first[0]->level)->toBe('warning')
        ->and($first[0]->context)->toBe([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'as_of' => '2026-10-12T20:05:44Z', 'age_hours' => 1.0,
            'slo_hours' => 26, 'within_slo' => true, 'missing_days' => ['2026-10-10'], 'missing_days_count' => 1,
        ]);

    // A second day goes missing: the next hourly line names both.
    DB::table('ingest_runs')->where('day', '2026-10-09')->update(['status' => 'superseded']);
    $second = fmdLogged();

    expect($second)->toHaveCount(1)
        ->and($second[0]->level)->toBe('warning')
        ->and($second[0]->context['missing_days'])->toBe(['2026-10-09', '2026-10-10'])
        ->and($second[0]->context['missing_days_count'])->toBe(2);
});

it('logs price_freshness at info, without the missing days, once the gap is closed', function (): void {
    fmdOk('2026-10-09', '2026-10-11', '2026-10-12');
    expect(fmdLogged()[0]->level)->toBe('warning');

    fmdOk('2026-10-10');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T21:05:44Z'));
    $closed = fmdLogged();

    expect($closed)->toHaveCount(1)
        ->and($closed[0]->level)->toBe('info')
        ->and($closed[0]->context)->toBe([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'as_of' => '2026-10-12T20:05:44Z', 'age_hours' => 1.0,
            'slo_hours' => 26, 'within_slo' => true,
        ]);
});
