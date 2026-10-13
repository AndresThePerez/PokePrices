<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\LogFreshness;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// The freshness metric (plan P2-i1 T10, D8): as_of is the newest marker among the source's ok ingest runs, age_hours
// is shown with one decimal rounded half up, and within_slo is judged on the unrounded age against 26 h.

const T10_MARKER = '2026-10-12T20:05:44Z';

/** Inserts one ingest run and returns its id; the marker's UTC date is the run's day (D1). */
function t10Run(string $status, ?string $marker = T10_MARKER, string $mode = 'sync', ?string $startedAt = null, ?string $finishedAt = null): int
{
    $day = $marker === null ? '2026-10-12' : CarbonImmutable::parse($marker)->utc()->format('Y-m-d');

    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => $marker, 'mode' => $mode,
        'status' => $status, 'started_at' => $startedAt ?? $marker ?? '2026-10-12T21:00:00Z',
        'finished_at' => $finishedAt, 'counts' => '{}',
    ]);
}

/** Travels to $seconds after T10_MARKER. */
function t10TravelSeconds(int $seconds): CarbonImmutable
{
    $now = CarbonImmutable::parse(T10_MARKER)->addSeconds($seconds);
    // What travelTo() does; Laravel's tearDown clears it.
    Carbon::setTestNow($now);

    return $now;
}

/** Runs pokeprices:prices:freshness and returns [exit code, trimmed output]. */
function t10Command(array $options = []): array
{
    $code = Artisan::call('pokeprices:prices:freshness', $options);

    return [$code, trim(Artisan::output())];
}

it('reports 25.9 h as 25.9 and within the SLO', function (): void {
    t10Run('ok');
    t10TravelSeconds(93_240);
    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json('data.age_hours'))->toBe(25.9)
        ->and($response->json('data.within_slo'))->toBeTrue();
});

it('judges 25.96 h within the SLO and displays it as 26.0 (D8: the display never decides the SLO)', function (): void {
    t10Run('ok');
    t10TravelSeconds(93_456);
    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json('data.within_slo'))->toBeTrue()
        ->and($response->json('data.age_hours'))->toBe(26.0)
        ->and((string) $response->getContent())->toContain('"age_hours":26.0');
    [$code, $out] = t10Command();
    expect($code)->toBe(0)
        ->and($out)->toBe('tcgplayer via tcgcsv: as of 2026-10-12T20:05:44Z, 26.0 h old, SLO 26 h: ok');
});

it('judges exactly 26.0 h outside the SLO (strictly under)', function (): void {
    t10Run('ok');
    t10TravelSeconds(93_600);
    $response = $this->getJson('/v1/prices/freshness');
    expect($response->json('data.within_slo'))->toBeFalse()
        ->and($response->json('data.age_hours'))->toBe(26.0);
    [$code, $out] = t10Command();
    expect($code)->toBe(1)
        ->and($out)->toBe('tcgplayer via tcgcsv: as of 2026-10-12T20:05:44Z, 26.0 h old, SLO 26 h: BREACHED');
});

it('rounds the displayed age half up from the unrounded age', function (int $seconds, float $shown): void {
    t10Run('ok');
    t10TravelSeconds($seconds);
    expect($this->getJson('/v1/prices/freshness')->json('data.age_hours'))->toBe($shown);
})->with([
    '25.95 h exactly rounds up' => [93_420, 26.0],
    'one second under 25.95 h rounds down' => [93_419, 25.9],
    'zero' => [0, 0.0],
    '0.05 h rounds up' => [180, 0.1],
    'six days' => [518_400, 144.0],
]);

it('answers the whole closed body with no ETag and Cache-Control no-store', function (): void {
    $runId = t10Run('ok', T10_MARKER, 'queued', '2026-10-12T21:40:00Z', '2026-10-12T21:41:07Z');
    t10TravelSeconds(3_600);
    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json())->toBe(['data' => [
        'source' => 'tcgplayer',
        'feed' => 'tcgcsv',
        'as_of' => '2026-10-12T20:05:44Z',
        'day' => '2026-10-12',
        'age_hours' => 1.0,
        'slo_hours' => 26,
        'within_slo' => true,
        'measured_at' => '2026-10-12T21:05:44Z',
        'last_run' => ['id' => $runId, 'status' => 'ok', 'mode' => 'queued', 'day' => '2026-10-12', 'finished_at' => '2026-10-12T21:41:07Z'],
    ]])
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('is throttled on the public-read tier', function (): void {
    $route = Route::getRoutes()->getByName('prices.freshness');
    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('throttle:public-read')
        ->and($route->gatherMiddleware())->not->toContain('conditional-get');
});

it('answers nulls and within_slo false when no ok run exists', function (): void {
    t10TravelSeconds(60);
    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json('data'))->toBe([
        'source' => 'tcgplayer',
        'feed' => 'tcgcsv',
        'as_of' => null,
        'day' => null,
        'age_hours' => null,
        'slo_hours' => 26,
        'within_slo' => false,
        'measured_at' => '2026-10-12T20:06:44Z',
        'last_run' => null,
    ]);
    [$code, $out] = t10Command();
    expect($code)->toBe(1)
        ->and($out)->toBe('tcgplayer via tcgcsv: no prices ingested');
});

it('does not count a superseded run as ok, while a replay\'s own ok run counts', function (): void {
    t10Run('ok', '2026-10-11T20:05:45Z', 'sync');
    $superseded = t10Run('superseded', T10_MARKER, 'sync');
    t10TravelSeconds(60);
    $data = $this->getJson('/v1/prices/freshness')->json('data');
    expect($data['as_of'])->toBe('2026-10-11T20:05:45Z')
        ->and($data['day'])->toBe('2026-10-11')
        ->and($data['last_run']['id'])->toBe($superseded)
        ->and($data['last_run']['status'])->toBe('superseded');

    t10Run('ok', T10_MARKER, 'replay');
    $data = $this->getJson('/v1/prices/freshness')->json('data');
    expect($data['as_of'])->toBe('2026-10-12T20:05:44Z')
        ->and($data['day'])->toBe('2026-10-12')
        ->and($data['age_hours'])->toBe(0.0);
});

it('does not move as_of for a newer failed, refused, running or skipped run', function (string $status): void {
    t10Run('ok', '2026-10-11T20:05:45Z');
    $newer = t10Run($status, T10_MARKER, 'queued', '2026-10-12T21:40:00Z');
    t10TravelSeconds(0);
    $data = $this->getJson('/v1/prices/freshness')->json('data');
    expect($data['as_of'])->toBe('2026-10-11T20:05:45Z')
        ->and($data['age_hours'])->toBe(24.0)
        ->and($data['within_slo'])->toBeTrue()
        ->and($data['last_run'])->toBe(['id' => $newer, 'status' => $status, 'mode' => 'queued', 'day' => '2026-10-12', 'finished_at' => null]);
})->with(['failed', 'refused', 'running', 'skipped_unchanged']);

it('ignores a refused run that never read a marker', function (): void {
    t10Run('ok', '2026-10-11T20:05:45Z');
    t10Run('refused', null, 'sync', '2026-10-12T22:00:00Z');
    t10TravelSeconds(0);
    expect($this->getJson('/v1/prices/freshness')->json('data.as_of'))->toBe('2026-10-11T20:05:45Z');
});

it('takes as_of from the run a real sync ingest of a collected day writes', function (): void {
    $code = Artisan::call('pokeprices:prices:ingest', ['--day' => '2026-10-12', '--sync' => true, '--snapshots' => base_path('tests/Fixtures/tcgcsv-days/snapshots')]);
    expect($code)->toBe(0);
    t10TravelSeconds(7_200);
    [$exit, $out] = t10Command();
    expect($exit)->toBe(0)
        ->and($out)->toBe('tcgplayer via tcgcsv: as of 2026-10-12T20:05:44Z, 2.0 h old, SLO 26 h: ok');
});

it('prints the endpoint\'s data as one JSON line with --json and keeps the exit code', function (): void {
    t10Run('ok');
    t10TravelSeconds(100_000);
    [$code, $out] = t10Command(['--json' => true]);
    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    expect($code)->toBe(1)
        ->and($data)->toBe($this->getJson('/v1/prices/freshness')->json('data'))
        ->and($data['age_hours'])->toBe(27.8)
        ->and($data['within_slo'])->toBeFalse()
        ->and($out)->toContain('"age_hours":27.8');
});

it('logs price_freshness at info within the SLO and at warning when breached, through the JSON formatter', function (?int $seconds, string $level, bool $within, ?float $age): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'pp-t10-log-');
    config(['logging.default' => 'stderr_json', 'logging.channels.stderr_json.handler_with.stream' => $file]);
    Log::forgetChannel('stderr_json');
    if ($seconds !== null) {
        t10Run('ok');
    }
    t10TravelSeconds($seconds ?? 0);
    (new LogFreshness)->handle();
    $lines = array_map(fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    @unlink($file);
    $records = array_values(array_filter($lines, fn (array $l): bool => $l['message'] === 'price_freshness'));
    expect($records)->toHaveCount(1)
        ->and($records[0]['level'])->toBe($level)
        ->and($records[0]['context'])->toBe([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'as_of' => $seconds === null ? null : '2026-10-12T20:05:44Z',
            'age_hours' => $age, 'slo_hours' => 26, 'within_slo' => $within,
        ]);
})->with([
    'within (25.96 h shown 26.0)' => [93_456, 'info', true, 26.0],
    'breached' => [93_600, 'warning', false, 26.0],
    'no ok run' => [null, 'warning', false, null],
]);

it('schedules LogFreshness hourly as pokeprices-freshness on the redis default queue', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => $event->description === 'pokeprices-freshness')->values();
    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 * * * *');
});
