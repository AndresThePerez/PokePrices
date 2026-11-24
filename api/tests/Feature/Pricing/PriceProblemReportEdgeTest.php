<?php

declare(strict_types=1);

use App\Domain\Pricing\PriceJobProblems;
use App\Domain\Pricing\ReportedPriceProblems;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// Edge cases of the hourly price-job report (pokeprices:prices:report-problems): what a failed write, a partly failed
// run, a lost "cleared" message, a second session and a killed run do to "once when it starts, once when it clears",
// and that the address stays out of everything a failure leaves behind. HTTP is faked and discord.com resolves to
// 192.0.2.10; the address below is made up.

const PPRE_URL = 'https://discord.com/api/webhooks/100000000000000000/SENTINELTOKEN-EDGE-0000';

/** The answer the fake gives from now on (set), or the current one. */
function ppreAnswer(?Closure $set = null): Closure
{
    static $answer = null;
    if ($set !== null) {
        $answer = $set;
    }

    return $answer ?? fn () => Http::response('', 204);
}

/**
 * The content of every envelope a request carried so far in this test (attempts, whatever the answer).
 *
 * @return list<string>
 */
function ppreAttempts(?string $add = null, bool $reset = false): array
{
    static $attempts = [];
    if ($reset) {
        $attempts = [];
    }
    if ($add !== null) {
        $attempts[] = $add;
    }

    return $attempts;
}

/**
 * One run of the command.
 *
 * @return array{0: int, 1: string, 2: list<string>} the exit code, the output, and the contents it tried to post
 */
function ppreRun(): array
{
    $before = count(ppreAttempts());
    $code = Artisan::call('pokeprices:prices:report-problems');

    return [$code, trim(Artisan::output()), array_slice(ppreAttempts(), $before)];
}

/** Inserts one ingest run and returns its id; the marker is 20:05:44 UTC on the run's day. */
function ppreIngest(string $status, string $day, ?string $startedAt = null, ?string $finishedAt = null): int
{
    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => "{$day}T20:05:44Z", 'mode' => 'queued',
        'status' => $status, 'started_at' => $startedAt ?? "{$day}T21:40:00Z", 'finished_at' => $finishedAt, 'counts' => '{}',
    ]);
}

function ppreOk(string ...$days): void
{
    foreach ($days as $day) {
        ppreIngest('ok', $day, null, "{$day}T21:41:00Z");
    }
}

function ppreAt(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant));
}

/** A second database session, as another process would have. */
function ppreSession(string $name): ConnectionInterface
{
    config(["database.connections.{$name}" => config('database.connections.pgsql')]);

    return DB::connection($name);
}

/** @return list<string> the keys of the reports, oldest first; only the open ones unless $all */
function ppreKeys(bool $all = false): array
{
    return DB::table('reported_price_problems')->when(! $all, fn ($q) => $q->whereNull('cleared_at'))->orderBy('id')->pluck('key')->all();
}

/** Every string an exception chain shows: messages, the trace as text and the string arguments of its frames. */
function ppreExceptionText(Throwable $e): string
{
    $strings = [];
    $collect = function (mixed $value, int $depth) use (&$collect, &$strings): void {
        if (is_string($value)) {
            $strings[] = $value;
        } elseif (is_array($value) && $depth < 4) {
            foreach ($value as $inner) {
                $collect($inner, $depth + 1);
            }
        }
    };
    for ($at = $e; $at !== null; $at = $at->getPrevious()) {
        $strings[] = $at->getMessage();
        $strings[] = $at->getTraceAsString();
        foreach ($at->getTrace() as $frame) {
            $collect($frame['args'] ?? [], 0);
        }
    }

    return implode("\n", $strings);
}

beforeEach(function (): void {
    ppreAnswer(fn () => Http::response('', 204));
    ppreAttempts(reset: true);
    config(['alerts.discord.price_job_webhook_url' => PPRE_URL]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    AlertRows::captureLogs();
    Http::fake(function (ClientRequest $request) {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        ppreAttempts($request->url() === PPRE_URL ? (string) $body['content'] : 'UNEXPECTED REQUEST');

        return ppreAnswer()($request);
    });
});

it('posts a message again when Discord took it and its row could not be written: the error names no address, the lock is given back and the next run records it', function (): void {
    ppreOk('2026-10-11', '2026-10-12');
    ppreAt('2026-10-13T23:00:00Z');
    $broken = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$broken): void {
        if ($broken && str_contains($query, 'INSERT INTO reported_price_problems')) {
            throw new RuntimeException('the database went away');
        }
    });

    $thrown = null;
    try {
        ppreRun();
    } catch (Throwable $e) {
        $thrown = $e;
    }
    $afterFailure = [count(ppreAttempts()), DB::table('reported_price_problems')->count()];
    $other = ppreSession('ppre_other');
    try {
        $lockFree = ReportedPriceProblems::tryLock($other);
        ReportedPriceProblems::unlock($other);
    } finally {
        DB::purge('ppre_other');
    }

    $broken = false;
    ppreAt('2026-10-14T00:00:00Z');
    [$code, $output, $again] = ppreRun();
    [, , $third] = ppreRun();

    $text = ($thrown === null ? '' : ppreExceptionText($thrown)).json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($afterFailure)->toBe([1, 0])
        ->and($lockFree)->toBeTrue()
        ->and($text)->not->toContain('SENTINEL')
        ->and($text)->not->toContain('api/webhooks')
        ->and([$code, $output])->toBe([0, '{"posted":1,"cleared":0,"failed":0}'])
        ->and(ppreAttempts())->toBe([
            'PokéPrices price job problem: The newest prices are from 2026-10-12, which is past the limit of 26 hours. Seen since 2026-10-13T23:00:00Z.',
            'PokéPrices price job problem: The newest prices are from 2026-10-12, which is past the limit of 26 hours. Seen since 2026-10-14T00:00:00Z.',
        ])
        ->and($again)->toHaveCount(1)
        ->and($third)->toBe([])
        ->and(ppreKeys())->toBe(['late:2026-10-12']);
});

it('keeps the address out of the log, the output and the table when the answer repeats it and when the host does not resolve', function (): void {
    ppreOk('2026-10-11', '2026-10-12');
    ppreAt('2026-10-13T23:00:00Z');

    ppreAnswer(fn () => Http::response('', 302, ['Location' => PPRE_URL.'?wait=true']));
    [$codeRedirect, $outputRedirect] = ppreRun();
    ppreAnswer(fn () => Http::response((string) json_encode(['message' => 'Invalid Webhook Token', 'url' => PPRE_URL]), 401));
    [$codeDenied, $outputDenied] = ppreRun();
    FakeHostResolver::install(['discord.com' => []]);
    [$codeUnresolved, $outputUnresolved] = ppreRun();
    $triedWhileBroken = count(ppreAttempts());
    $recordedWhileBroken = DB::table('reported_price_problems')->count();

    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    ppreAnswer(fn () => Http::response('', 204));
    [$codeMended, $outputMended] = ppreRun();

    $left = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES).$outputRedirect.$outputDenied.$outputUnresolved.$outputMended
        .json_encode(DB::table('reported_price_problems')->get());
    expect([$codeRedirect, $codeDenied, $codeUnresolved])->toBe([1, 1, 1])
        ->and(array_column(AlertRows::logged('price_problem_report_failed'), 'context'))->toBe([
            ['key' => 'late:2026-10-12', 'event' => 'started', 'status' => 302, 'error' => 'redirect'],
            ['key' => 'late:2026-10-12', 'event' => 'started', 'status' => 401, 'error' => 'http_status'],
            ['key' => 'late:2026-10-12', 'event' => 'started', 'status' => null, 'error' => 'unresolvable'],
        ])
        ->and([$triedWhileBroken, $recordedWhileBroken])->toBe([2, 0])
        ->and($left)->not->toContain('SENTINEL')
        ->and($left)->not->toContain('api/webhooks')
        ->and($left)->not->toContain('discord.com')
        ->and($left)->not->toContain('192.0.2.10')
        ->and([$codeMended, $outputMended])->toBe([0, '{"posted":1,"cleared":0,"failed":0}'])
        ->and(ppreKeys())->toBe(['late:2026-10-12']);
});

it('records the problem whose post was accepted and leaves the one that failed for the next run, which posts only that one', function (): void {
    ppreOk('2026-10-10', '2026-10-12');
    ppreAt('2026-10-13T23:00:00Z');
    $requests = 0;
    ppreAnswer(function () use (&$requests) {
        return Http::response('', ++$requests === 1 ? 503 : 204);
    });

    [$code, $output, $first] = ppreRun();
    $openAfterFirst = ppreKeys();
    ppreAt('2026-10-14T00:00:00Z');
    [$codeNext, $outputNext, $second] = ppreRun();
    ppreAt('2026-10-14T01:00:00Z');
    [, $outputThird, $third] = ppreRun();

    expect([$code, $output])->toBe([1, '{"posted":1,"cleared":0,"failed":1}'])
        ->and($first)->toHaveCount(2)
        ->and($openAfterFirst)->toBe(['missing_day:2026-10-11'])
        ->and(array_column(AlertRows::logged('price_problem_report_failed'), 'context'))->toBe([
            ['key' => 'late:2026-10-12', 'event' => 'started', 'status' => 503, 'error' => 'http_status'],
        ])
        ->and([$codeNext, $outputNext])->toBe([0, '{"posted":1,"cleared":0,"failed":0}'])
        ->and($second)->toBe(['PokéPrices price job problem: The newest prices are from 2026-10-12, which is past the limit of 26 hours. Seen since 2026-10-14T00:00:00Z.'])
        ->and([$outputThird, $third])->toBe(['{"posted":0,"cleared":0,"failed":0}', []])
        ->and(ppreKeys())->toBe(['missing_day:2026-10-11', 'late:2026-10-12']);
});

it('posts nothing when a problem whose cleared message failed is back before the next run, and one cleared message with the first instant when it ends', function (): void {
    ppreOk('2026-10-11');
    $run = ppreIngest('running', '2026-10-12', '2026-10-12T18:00:00Z');
    ppreAt('2026-10-12T21:30:00Z');
    [, , $started] = ppreRun();

    // The run fails once (not a hold): the entry is gone, and the cleared message is not accepted.
    DB::table('ingest_runs')->where('id', $run)->update(['status' => 'failed', 'finished_at' => '2026-10-12T21:35:00Z', 'error' => 'boom']);
    ppreAnswer(fn () => Http::response('', 500));
    ppreAt('2026-10-12T21:40:00Z');
    [$codeLost, $outputLost, $lost] = ppreRun();

    // A resume that hangs: the same run is running again before the cleared message was ever delivered.
    DB::table('ingest_runs')->where('id', $run)->update(['status' => 'running', 'finished_at' => null, 'error' => null]);
    ppreAnswer(fn () => Http::response('', 204));
    ppreAt('2026-10-12T21:50:00Z');
    [$codeBack, $outputBack, $back] = ppreRun();

    DB::table('ingest_runs')->where('id', $run)->update(['status' => 'ok', 'finished_at' => '2026-10-12T21:55:00Z']);
    ppreAt('2026-10-12T22:00:00Z');
    [, $outputEnded, $ended] = ppreRun();

    $sentence = "Run {$run} of 2026-10-12 has not finished and blocks its day.";
    expect($started)->toBe(["PokéPrices price job problem: {$sentence} Seen since 2026-10-12T21:30:00Z."])
        ->and([$codeLost, $outputLost])->toBe([1, '{"posted":0,"cleared":0,"failed":1}'])
        ->and($lost)->toHaveCount(1)
        ->and([$codeBack, $outputBack, $back])->toBe([0, '{"posted":0,"cleared":0,"failed":0}', []])
        ->and($outputEnded)->toBe('{"posted":0,"cleared":1,"failed":0}')
        ->and($ended)->toBe(["PokéPrices price job problem cleared: {$sentence} That was seen since 2026-10-12T21:30:00Z and is over as of 2026-10-12T22:00:00Z."])
        ->and(ppreKeys(all: true))->toBe(["stuck:2026-10-12:run:{$run}"])
        ->and(ppreKeys())->toBe([]);
});

it('holds the report lock while a message is posted and not yet recorded, and keeps one open row when a row for the key is written in that gap', function (): void {
    ppreOk('2026-10-11', '2026-10-12');
    ppreAt('2026-10-13T23:00:00Z');
    $other = ppreSession('ppre_other');
    $takenInTheGap = null;
    $rivalWrote = null;
    ppreAnswer(function () use ($other, &$takenInTheGap, &$rivalWrote) {
        $takenInTheGap = ReportedPriceProblems::tryLock($other);
        if ($takenInTheGap) {
            ReportedPriceProblems::unlock($other);
        }
        $rivalWrote = ReportedPriceProblems::report(PriceJobProblems::all()[0], CarbonImmutable::now());

        return Http::response('', 204);
    });

    try {
        [$code, $output, $posted] = ppreRun();
        $takenAfter = ReportedPriceProblems::tryLock($other);
        ReportedPriceProblems::unlock($other);
    } finally {
        DB::purge('ppre_other');
    }
    [$codeNext, $outputNext, $postedNext] = ppreRun();

    expect($takenInTheGap)->toBeFalse()
        ->and($rivalWrote)->toBeTrue()
        ->and($takenAfter)->toBeTrue()
        ->and([$code, $output])->toBe([1, '{"posted":0,"cleared":0,"failed":1}'])
        ->and($posted)->toHaveCount(1)
        ->and(ppreKeys(all: true))->toBe(['late:2026-10-12'])
        ->and([$codeNext, $outputNext, $postedNext])->toBe([0, '{"posted":0,"cleared":0,"failed":0}', []]);
});

it('is locked out by a live session that holds the report lock, and no longer once that session has died without giving it back', function (): void {
    ppreOk('2026-10-11', '2026-10-12');
    ppreAt('2026-10-13T23:00:00Z');
    $dying = ppreSession('ppre_dying');
    $took = ReportedPriceProblems::tryLock($dying);
    [$codeHeld, $outputHeld, $postedHeld] = ppreRun();

    // The process is killed: its session ends with no unlock.
    DB::purge('ppre_dying');
    unset($dying);
    $held = 1;
    for ($i = 0; $i < 100 && $held > 0; $i++) {
        usleep(20_000);
        $held = (int) DB::selectOne(
            "SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND classid = 0 AND objid = ?::bigint::oid
             AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
            [ReportedPriceProblems::LOCK_KEY],
        )->n;
    }
    [$code, $output, $posted] = ppreRun();

    expect($took)->toBeTrue()
        ->and([$codeHeld, $outputHeld, $postedHeld])->toBe([0, 'Another price-job report is running; nothing was sent.', []])
        ->and($held)->toBe(0)
        ->and([$code, $output])->toBe([0, '{"posted":1,"cleared":0,"failed":0}'])
        ->and($posted)->toHaveCount(1);
});

it('lets the next hour run after a run that was killed: the schedule entry\'s overlap mutex is held within the hour and gone at the next one', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === 'pokeprices-price-problems');

    // What the scheduler does just before it starts the run; a killed run never removes it.
    ppreAt('2026-10-13T10:00:07Z');
    $created = $event->mutex->create($event);
    ppreAt('2026-10-13T10:59:00Z');
    $skippedWithinTheHour = ! $event->filtersPass(app());
    ppreAt('2026-10-13T11:00:00Z');
    $dueAtTheNextHour = $event->isDue(app());
    $passesAtTheNextHour = $event->filtersPass(app());

    expect($created)->toBeTrue()
        ->and($skippedWithinTheHour)->toBeTrue()
        ->and([$dueAtTheNextHour, $passesAtTheNextHour])->toBe([true, true]);
});

it('posts a refusal the ingest itself wrote with the file name only, never the folder it was read from', function (): void {
    ppreOk('2026-10-11');
    $root = sys_get_temp_dir().'/ppre-SENTINELROOT-'.bin2hex(random_bytes(4));
    mkdir("{$root}/2026-10-12", 0700, true);
    ppreAt('2026-10-12T21:40:00Z');
    try {
        $ingest = Artisan::call('pokeprices:prices:ingest', ['--day' => '2026-10-12', '--sync' => true, '--snapshots' => $root]);
    } finally {
        rmdir("{$root}/2026-10-12");
        rmdir($root);
    }
    $recorded = DB::table('ingest_runs')->where('day', '2026-10-12')->get(['status', 'error'])->map(fn ($r): array => (array) $r)->all();

    ppreAt('2026-10-12T22:00:00Z');
    [$code, , $posted] = ppreRun();

    $left = implode("\n", $posted).json_encode(DB::table('reported_price_problems')->get(), JSON_UNESCAPED_SLASHES);
    expect($ingest)->toBe(1)
        ->and($recorded)->toBe([['status' => 'refused', 'error' => 'missing_file:SUCCESS']])
        ->and($code)->toBe(0)
        ->and($posted)->toBe(['PokéPrices price job problem: The folder of 2026-10-12 was refused: missing_file:SUCCESS. Seen since 2026-10-12T22:00:00Z.'])
        ->and($left)->not->toContain('SENTINELROOT')
        ->and($left)->not->toContain(sys_get_temp_dir());
});
