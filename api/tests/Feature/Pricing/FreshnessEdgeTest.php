<?php

declare(strict_types=1);

use App\Domain\Pricing\Freshness;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Adversarial edge cases for the freshness metric (plan P2-i1 T10, D8).

/** Inserts one ingest run for the source and returns its id. */
function t10eRun(string $status, ?string $marker, string $mode = 'sync', ?string $startedAt = null, ?string $finishedAt = null, ?string $day = null): int
{
    $day ??= $marker === null ? '2026-10-12' : CarbonImmutable::parse($marker)->utc()->format('Y-m-d');

    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => $marker, 'mode' => $mode,
        'status' => $status, 'started_at' => $startedAt ?? $marker ?? '2026-10-12T21:00:00Z',
        'finished_at' => $finishedAt, 'counts' => '{}',
    ]);
}

it('reports the newest MARKER, not the newest finished run, when an old day replays last', function (): void {
    t10eRun('ok', '2026-10-12T20:05:44Z', 'queued', '2026-10-12T21:40:00Z', '2026-10-12T21:41:00Z');
    // A replay of the old day 2026-10-09 finishes at 09:00 today: the newest ok run by finished_at, the oldest marker.
    $replay = t10eRun('ok', '2026-10-09T20:05:44Z', 'replay', '2026-10-13T08:59:00Z', '2026-10-13T09:00:00Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-13T10:05:44Z'));

    $data = $this->getJson('/v1/prices/freshness')->json('data');
    expect($data['as_of'])->toBe('2026-10-12T20:05:44Z')
        ->and($data['day'])->toBe('2026-10-12')
        ->and($data['age_hours'])->toBe(14.0)
        ->and($data['within_slo'])->toBeTrue()
        ->and($data['last_run']['id'])->toBe($replay)
        ->and($data['last_run']['day'])->toBe('2026-10-09');
    expect(Artisan::call('pokeprices:prices:freshness'))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('tcgplayer via tcgcsv: as of 2026-10-12T20:05:44Z, 14.0 h old, SLO 26 h: ok');
});

it('stays honest when the newest day is superseded and a replay re-ingested the older days only', function (): void {
    // Newest day's run superseded; the replay's ok runs cover 10-09 and 10-10 (10-11 failed): as_of is the newest OK marker.
    t10eRun('superseded', '2026-10-12T20:05:44Z', 'sync');
    t10eRun('superseded', '2026-10-11T20:05:44Z', 'sync');
    t10eRun('ok', '2026-10-09T20:05:44Z', 'replay', '2026-10-13T08:00:00Z', '2026-10-13T08:01:00Z');
    t10eRun('ok', '2026-10-10T20:05:44Z', 'replay', '2026-10-13T08:02:00Z', '2026-10-13T08:03:00Z');
    t10eRun('failed', '2026-10-11T20:05:44Z', 'replay', '2026-10-13T08:04:00Z', '2026-10-13T08:05:00Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-13T09:05:44Z'));

    $data = $this->getJson('/v1/prices/freshness')->json('data');
    expect($data['as_of'])->toBe('2026-10-10T20:05:44Z')
        ->and($data['age_hours'])->toBe(61.0)
        ->and($data['within_slo'])->toBeFalse()
        ->and($data['last_run']['status'])->toBe('failed');
});

it('judges the microsecond edge of the SLO on the unrounded age and displays it as 26.0', function (): void {
    t10eRun('ok', '2026-10-12T20:05:44Z');
    $marker = CarbonImmutable::parse('2026-10-12T20:05:44Z');

    // 25:59:59.999999 -> within, shown 26.0.
    Carbon::setTestNow($marker->addHours(26)->subMicrosecond());
    $within = $this->getJson('/v1/prices/freshness');
    expect($within->json('data.within_slo'))->toBeTrue()
        ->and($within->json('data.age_hours'))->toBe(26.0)
        ->and($within->getContent())->toContain('"age_hours":26.0,');
    expect(Artisan::call('pokeprices:prices:freshness'))->toBe(0);

    // 26:00:00.000000 -> breached; 26:00:00.000001 -> breached.
    foreach ([$marker->addHours(26), $marker->addHours(26)->addMicrosecond()] as $now) {
        Carbon::setTestNow($now);
        expect($this->getJson('/v1/prices/freshness')->json('data.within_slo'))->toBeFalse()
            ->and(Artisan::call('pokeprices:prices:freshness'))->toBe(1);
    }
});

it('computes as_of, age and measured_at from one instant whatever the application time zone', function (): void {
    t10eRun('ok', '2026-10-12T20:05:44Z');
    $original = date_default_timezone_get();
    date_default_timezone_set('Pacific/Auckland');
    config(['app.timezone' => 'Pacific/Auckland']);
    try {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-13T20:05:44.500000Z')->setTimezone('Pacific/Auckland'));
        $data = Freshness::measure();
        expect($data['as_of'])->toBe('2026-10-12T20:05:44Z')
            ->and($data['measured_at'])->toBe('2026-10-13T20:05:44Z')
            ->and($data['day'])->toBe('2026-10-12')
            ->and($data['age_hours'])->toBe(24.0)
            ->and($data['within_slo'])->toBeTrue();
        $response = $this->getJson('/v1/prices/freshness');
        expect($response->json('data.measured_at'))->toBe('2026-10-13T20:05:44Z')
            ->and($response->json('data.as_of'))->toBe('2026-10-12T20:05:44Z');
    } finally {
        date_default_timezone_set($original);
    }
});

it('answers no prices when only refused and failed runs exist, and still names the last run', function (): void {
    t10eRun('refused', null, 'sync', '2026-10-12T22:00:00Z');
    $failed = t10eRun('failed', '2026-10-12T20:05:44Z', 'queued', '2026-10-12T23:00:00Z', '2026-10-12T23:01:00Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-13T00:00:00Z'));

    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json('data.as_of'))->toBeNull()
        ->and($response->json('data.day'))->toBeNull()
        ->and($response->json('data.age_hours'))->toBeNull()
        ->and($response->json('data.within_slo'))->toBeFalse()
        ->and($response->json('data.last_run.id'))->toBe($failed)
        ->and($response->json('data.last_run.status'))->toBe('failed');
    expect(Artisan::call('pokeprices:prices:freshness', ['--json' => true]))->toBe(1);
    $json = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    expect($json['as_of'])->toBeNull()->and($json['within_slo'])->toBeFalse();
});

it('reports no prices while a replay has superseded every run and only a running replay run exists', function (): void {
    t10eRun('superseded', '2026-10-12T20:05:44Z');
    t10eRun('superseded', '2026-10-11T20:05:44Z');
    $running = t10eRun('running', '2026-10-09T20:05:44Z', 'replay', '2026-10-13T09:00:00Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-13T09:01:00Z'));

    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json('data.as_of'))->toBeNull()
        ->and($response->json('data.within_slo'))->toBeFalse()
        ->and($response->json('data.last_run.id'))->toBe($running)
        ->and($response->json('data.last_run.status'))->toBe('running')
        ->and($response->json('data.last_run.mode'))->toBe('replay');
});

it('never answers 304 or an ETag to a conditional request and keeps no-store on HEAD', function (): void {
    t10eRun('ok', '2026-10-12T20:05:44Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T21:05:44Z'));

    $first = $this->getJson('/v1/prices/freshness');
    foreach (['*', '"abc"', 'W/"abc"'] as $etag) {
        $again = $this->getJson('/v1/prices/freshness', ['If-None-Match' => $etag, 'If-Modified-Since' => 'Wed, 01 Jan 2031 00:00:00 GMT']);
        expect($again->getStatusCode())->toBe(200)
            ->and($again->headers->has('ETag'))->toBeFalse()
            ->and($again->headers->has('Last-Modified'))->toBeFalse()
            ->and((string) $again->headers->get('Cache-Control'))->toContain('no-store');
    }
    expect($first->headers->has('ETag'))->toBeFalse();
    $this->call('HEAD', '/v1/prices/freshness')->assertOk();
});

it('breaks ties between ok runs of the same marker by the newest id and keeps a whole-hour age a float', function (): void {
    t10eRun('ok', '2026-10-12T20:05:44Z', 'sync');
    $newest = t10eRun('ok', '2026-10-12T20:05:44Z', 'replay', '2026-10-13T08:00:00Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T22:05:44Z'));

    $response = $this->getJson('/v1/prices/freshness');
    expect($response->json('data.as_of'))->toBe('2026-10-12T20:05:44Z')
        ->and($response->json('data.last_run.id'))->toBe($newest)
        ->and($response->getContent())->toContain('"age_hours":2.0,')
        ->and($response->getContent())->not->toContain('"age_hours":2,');
});

it('survives a clock behind the marker with a float age, a boolean verdict and no negative zero', function (): void {
    // A clock slightly behind the marker (skew): the answer is still a 200 with a float age and a boolean verdict.
    t10eRun('ok', '2026-10-12T20:05:44Z');
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-12T20:05:43Z'));
    $response = $this->getJson('/v1/prices/freshness');
    $response->assertOk();
    expect($response->json('data.age_hours'))->toBeFloat()
        ->and($response->json('data.age_hours'))->toBeLessThanOrEqual(0.0)
        ->and($response->json('data.within_slo'))->toBeBool()
        ->and($response->getContent())->not->toContain('-0.0');
});

it('keeps integers and numeric strings unchanged in the log line while a zero-fraction float keeps its .0', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'pp-t10e-log-');
    config(['logging.default' => 'stderr_json', 'logging.channels.stderr_json.handler_with.stream' => $file]);
    Log::forgetChannel('stderr_json');
    Log::info('blast_radius', ['int' => 12, 'float' => 12.0, 'string' => '12.0', 'zero' => 0, 'duration_ms' => 0.0]);
    $raw = (string) file_get_contents($file);
    @unlink($file);

    expect($raw)->toContain('"int":12,')
        ->and($raw)->toContain('"float":12.0,')
        ->and($raw)->toContain('"string":"12.0"')
        ->and($raw)->toContain('"zero":0')
        ->and(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['duration_ms'])->toBe(0.0);
});
