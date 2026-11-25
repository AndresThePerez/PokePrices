<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverySender;
use App\Domain\Pricing\PriceJobProblems;
use App\Domain\Pricing\ReportedPriceProblems;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// The hourly price-job report (pokeprices:prices:report-problems): one Discord message when a problem of
// PriceJobProblems::all() starts and one when it is gone, remembered in reported_price_problems. HTTP is faked and
// discord.com resolves to 192.0.2.10; the address is set with config([...]) and never reaches a log line or a table.

const PPR_URL = 'https://discord.com/api/webhooks/123456789012345678/SENTINELTOKEN-0002';

/** The answer the fake gives from now on (set), or the current one. */
function pprAnswer(?Closure $set = null): Closure
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
function pprAttempts(?string $add = null, bool $reset = false): array
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

/** Sets the address, the DNS answer and the HTTP fake of this application; call once per application. */
function pprInstall(): void
{
    config(['alerts.discord.price_job_webhook_url' => PPR_URL]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    AlertRows::captureLogs();
    Http::fake(function (ClientRequest $request) {
        $body = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
        pprAttempts($request->url() === PPR_URL && array_keys($body) === ['content', 'username', 'allowed_mentions'] && $body['username'] === 'PokéPrices'
            ? (string) $body['content']
            : 'UNEXPECTED REQUEST');

        return pprAnswer()($request);
    });
}

/**
 * One run of the command.
 *
 * @return array{0: int, 1: string, 2: list<string>} the exit code, the output, and the contents it tried to post
 */
function pprRun(): array
{
    $before = count(pprAttempts());
    $code = Artisan::call('pokeprices:prices:report-problems');

    return [$code, trim(Artisan::output()), array_slice(pprAttempts(), $before)];
}

/** Inserts one ingest run and returns its id; the marker defaults to 20:05:44 UTC on the run's day. */
function pprIngest(string $status, string $day, ?string $startedAt = null, ?string $finishedAt = null, ?string $error = null): int
{
    return DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => "{$day}T20:05:44Z", 'mode' => 'queued',
        'status' => $status, 'started_at' => $startedAt ?? "{$day}T21:40:00Z",
        'finished_at' => $finishedAt, 'error' => $error, 'counts' => '{}',
    ]);
}

function pprOk(string ...$days): void
{
    foreach ($days as $day) {
        pprIngest('ok', $day, null, "{$day}T21:41:00Z");
    }
}

function pprAt(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant));
}

/** @return list<string> the keys of the reports, oldest first; only the open ones unless $all */
function pprKeys(bool $all = false): array
{
    return DB::table('reported_price_problems')->when(! $all, fn ($q) => $q->whereNull('cleared_at'))->orderBy('id')->pluck('key')->all();
}

/** @return list<string> the tables with a row whose text holds $needle */
function pprTablesHolding(string $needle): array
{
    $found = [];
    foreach (DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY 1') as $table) {
        $name = (string) $table->tablename;
        if ((int) DB::selectOne("SELECT count(*) AS n FROM \"{$name}\" t WHERE t::text LIKE ?", ["%{$needle}%"])->n > 0) {
            $found[] = $name;
        }
    }

    return $found;
}

beforeEach(function (): void {
    pprAnswer(fn () => Http::response('', 204));
    pprAttempts(reset: true);
    pprInstall();
});

// a. No address.

it('posts nothing, records nothing and exits 0 while the address is not set', function (?string $value): void {
    config(['alerts.discord.price_job_webhook_url' => $value]);
    pprOk('2026-10-10', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');

    [$code, $output, $posted] = pprRun();

    expect($code)->toBe(0)
        ->and($output)->toBe('PRICE_JOB_DISCORD_WEBHOOK_URL is not set; nothing was sent.')
        ->and($posted)->toBe([])
        ->and(PriceJobProblems::all())->toHaveCount(2)
        ->and(DB::table('reported_price_problems')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['unset' => [null], 'empty' => [''], 'blank' => ['  ']]);

// b. Once when a problem starts.

it('posts a problem once when it starts and records it; an hour later and after a restart it posts nothing', function (): void {
    pprOk('2026-10-11', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');

    [$code, $output, $posted] = pprRun();
    $sentTo = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();

    pprAt('2026-10-14T00:00:00Z');
    [$codeLater, $outputLater, $postedLater] = pprRun();

    // A restart: a new application (nothing in memory, an empty array cache) on the same database session.
    $pdo = DB::connection()->getPdo();
    $this->refreshApplication();
    DB::connection()->setPdo($pdo)->setReadPdo($pdo);
    pprInstall();
    pprAt('2026-10-14T01:00:00Z');
    [$codeRestarted, , $postedRestarted] = pprRun();

    expect($code)->toBe(0)
        ->and($output)->toBe('{"posted":1,"cleared":0,"failed":0}')
        ->and($posted)->toBe(['PokéPrices price job problem: The newest prices are from 2026-10-12, which is past the limit of 26 hours. Seen since 2026-10-13T23:00:00Z.'])
        ->and($sentTo)->toBe([PPR_URL])
        ->and([$codeLater, $outputLater, $postedLater])->toBe([0, '{"posted":0,"cleared":0,"failed":0}', []])
        ->and([$codeRestarted, $postedRestarted])->toBe([0, []])
        ->and(Http::recorded())->toHaveCount(0)
        ->and(pprKeys())->toBe(['late:2026-10-12'])
        ->and(ReportedPriceProblems::open()[0]['reported_at']->format('Y-m-d\TH:i:s\Z'))->toBe('2026-10-13T23:00:00Z');
});

// c. Once when it clears; the same problem later is new.

it('posts one message when a problem clears, none on the next run, and posts the same problem again when it starts later', function (): void {
    pprOk('2026-10-11');
    $run = pprIngest('running', '2026-10-12', '2026-10-12T18:00:00Z');
    pprAt('2026-10-12T21:30:00Z');
    [, , $started] = pprRun();

    // The run fails once: no longer running, and one failure is not a hold.
    DB::table('ingest_runs')->where('id', $run)->update(['status' => 'failed', 'finished_at' => '2026-10-12T21:35:00Z', 'error' => 'boom']);
    pprAt('2026-10-12T21:40:00Z');
    [$code, $output, $cleared] = pprRun();
    pprAt('2026-10-12T21:50:00Z');
    [, , $afterCleared] = pprRun();

    // A resume that hangs: the same run is running again, so the same key starts again.
    DB::table('ingest_runs')->where('id', $run)->update(['status' => 'running', 'finished_at' => null, 'error' => null]);
    pprAt('2026-10-12T22:00:00Z');
    [, , $again] = pprRun();

    $sentence = "Run {$run} of 2026-10-12 has not finished and blocks its day.";
    expect($started)->toBe(["PokéPrices price job problem: {$sentence} Seen since 2026-10-12T21:30:00Z."])
        ->and([$code, $output])->toBe([0, '{"posted":0,"cleared":1,"failed":0}'])
        ->and($cleared)->toBe(["PokéPrices price job problem cleared: {$sentence} That was seen since 2026-10-12T21:30:00Z and is over as of 2026-10-12T21:40:00Z."])
        ->and($afterCleared)->toBe([])
        ->and($again)->toBe(["PokéPrices price job problem: {$sentence} Seen since 2026-10-12T22:00:00Z."])
        ->and(pprKeys(all: true))->toBe(["stuck:2026-10-12:run:{$run}", "stuck:2026-10-12:run:{$run}"])
        ->and(pprKeys())->toHaveCount(1);
});

it('keeps a report open when its cleared message fails, and posts the cleared message on the next run', function (): void {
    pprOk('2026-10-10', '2026-10-12');
    pprAt('2026-10-12T22:00:00Z');
    pprRun();
    pprOk('2026-10-11');

    pprAnswer(fn () => Http::response('', 500));
    pprAt('2026-10-12T23:00:00Z');
    [$code, $output, $tried] = pprRun();
    $openAfterFailure = pprKeys();

    pprAnswer(fn () => Http::response('', 204));
    pprAt('2026-10-13T00:00:00Z');
    [, , $cleared] = pprRun();

    expect([$code, Str::afterLast($output, "\n")])->toBe([1, '{"posted":0,"cleared":0,"failed":1}'])
        ->and($tried)->toHaveCount(1)
        ->and($openAfterFailure)->toBe(['missing_day:2026-10-11'])
        ->and(AlertRows::logged('price_problem_report_failed')[0]['context'])->toBe(['key' => 'missing_day:2026-10-11', 'event' => 'cleared', 'status' => 500, 'error' => 'http_status'])
        ->and($cleared)->toBe(['PokéPrices price job problem cleared: No price run finished for 2026-10-11. That was seen since 2026-10-12T22:00:00Z and is over as of 2026-10-13T00:00:00Z.'])
        ->and(pprKeys())->toBe([]);
});

// d. Each kind, and several at once.

it('posts one message for a problem of each kind, with its day in ISO form', function (Closure $fixture, string $now): void {
    $sentence = $fixture();
    pprAt($now);

    [$code, , $posted] = pprRun();

    $seen = CarbonImmutable::parse($now)->format('Y-m-d\TH:i:s\Z');
    expect($code)->toBe(0)
        ->and($posted)->toBe(["PokéPrices price job problem: {$sentence} Seen since {$seen}."])
        ->and(array_column(ReportedPriceProblems::open(), 'sentence'))->toBe([$sentence])
        ->and(array_column(ReportedPriceProblems::open(), 'key'))->toBe(array_column(PriceJobProblems::all(), 'key'));
})->with([
    'late' => [function (): string {
        pprOk('2026-10-11', '2026-10-12');

        return 'The newest prices are from 2026-10-12, which is past the limit of 26 hours.';
    }, '2026-10-13T23:00:00Z'],
    'late, no day ever ingested' => [fn (): string => 'No price day has been ingested yet.', '2026-10-13T23:00:00Z'],
    'missing_day' => [function (): string {
        pprOk('2026-10-10', '2026-10-12');

        return 'No price run finished for 2026-10-11.';
    }, '2026-10-12T22:00:00Z'],
    'refused' => [function (): string {
        pprOk('2026-10-11');
        pprIngest('refused', '2026-10-12', '2026-10-12T21:40:00Z', '2026-10-12T21:40:01Z', 'bad_marker:marker.txt');

        return 'The folder of 2026-10-12 was refused: bad_marker:marker.txt.';
    }, '2026-10-12T22:00:00Z'],
    'stuck, a run left running' => [function (): string {
        pprOk('2026-10-11');
        $run = pprIngest('running', '2026-10-12', '2026-10-12T18:00:00Z');

        return "Run {$run} of 2026-10-12 has not finished and blocks its day.";
    }, '2026-10-12T21:30:00Z'],
    'stuck, a day on hold' => [function (): string {
        pprOk('2026-10-11');
        pprIngest('failed', '2026-10-12', '2026-10-12T20:50:00Z', '2026-10-12T21:00:00Z', 'boom');
        pprIngest('failed', '2026-10-12', '2026-10-12T21:05:00Z', '2026-10-12T21:10:00Z', 'boom');

        return '2026-10-12 failed twice in a row and is on hold.';
    }, '2026-10-12T21:30:00Z'],
]);

it('posts several problems one message each in the order the problems are listed, a refused day inside the history under both its kinds', function (): void {
    // Inserted out of order on purpose: the order posted is the list's, not the rows'.
    $stuck = pprIngest('running', '2026-10-11', '2026-10-11T21:40:00Z');
    pprOk('2026-10-12', '2026-10-08', '2026-10-09');
    pprIngest('refused', '2026-10-10', '2026-10-10T21:40:00Z', '2026-10-10T21:40:01Z', 'missing_file:SUCCESS');
    pprAt('2026-10-13T23:00:00Z');

    [$code, $output, $posted] = pprRun();
    [, , $second] = pprRun();

    $sentences = [
        'The newest prices are from 2026-10-12, which is past the limit of 26 hours.',
        'No price run finished for 2026-10-10.',
        'The folder of 2026-10-10 was refused: missing_file:SUCCESS.',
        'No price run finished for 2026-10-11.',
        "Run {$stuck} of 2026-10-11 has not finished and blocks its day.",
    ];
    expect([$code, $output])->toBe([0, '{"posted":5,"cleared":0,"failed":0}'])
        ->and($posted)->toBe(array_map(fn (string $s): string => "PokéPrices price job problem: {$s} Seen since 2026-10-13T23:00:00Z.", $sentences))
        ->and(pprKeys())->toBe(array_column(PriceJobProblems::all(), 'key'))
        ->and(pprKeys()[1])->toBe('missing_day:2026-10-10')
        ->and(pprKeys()[2])->toStartWith('refused:2026-10-10:2026-10-10T20:05:44Z:')
        ->and($second)->toBe([]);
});

// e. A failed post.

it('records nothing when a post fails, warns without the address, and posts on the next run', function (Closure $break, ?int $status, string $error, int $requests): void {
    pprOk('2026-10-10', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');
    $break();

    [$code, $output, $tried] = pprRun();
    $recordedAfterFailure = DB::table('reported_price_problems')->count();
    $warnings = AlertRows::logged('price_problem_report_failed');
    $logs = (string) json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);

    // Mended: the next run posts both, one attempt each.
    config(['alerts.discord.price_job_webhook_url' => PPR_URL]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    pprAnswer(fn () => Http::response('', 204));
    pprAt('2026-10-14T00:00:00Z');
    [$codeNext, $outputNext, $postedNext] = pprRun();

    expect([$code, Str::afterLast($output, "\n")])->toBe([1, '{"posted":0,"cleared":0,"failed":2}'])
        ->and($tried)->toHaveCount($requests)
        ->and($recordedAfterFailure)->toBe(0)
        ->and(array_column($warnings, 'level'))->toBe(['warning', 'warning'])
        ->and(array_column($warnings, 'context'))->toBe([
            ['key' => 'late:2026-10-12', 'event' => 'started', 'status' => $status, 'error' => $error],
            ['key' => 'missing_day:2026-10-11', 'event' => 'started', 'status' => $status, 'error' => $error],
        ])
        ->and($logs.$output)->not->toContain('SENTINEL')
        ->and($logs.$output)->not->toContain('api/webhooks')
        ->and($logs.$output)->not->toContain('192.0.2.10')
        ->and([$codeNext, $outputNext])->toBe([0, '{"posted":2,"cleared":0,"failed":0}'])
        ->and($postedNext)->toBe([
            'PokéPrices price job problem: The newest prices are from 2026-10-12, which is past the limit of 26 hours. Seen since 2026-10-14T00:00:00Z.',
            'PokéPrices price job problem: No price run finished for 2026-10-11. Seen since 2026-10-14T00:00:00Z.',
        ])
        ->and(pprKeys())->toBe(['late:2026-10-12', 'missing_day:2026-10-11']);
})->with([
    'a timeout' => [fn () => pprAnswer(fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.PPR_URL)), null, 'timeout', 2],
    'a 429' => [fn () => pprAnswer(fn () => Http::response('{"message": "You are being rate limited."}', 429, ['Retry-After' => '5'])), 429, 'http_status', 2],
    'a 500' => [fn () => pprAnswer(fn () => Http::response('', 500)), 500, 'http_status', 2],
    'an address that resolves to a blocked one' => [fn () => FakeHostResolver::install(['discord.com' => ['::1']]), null, 'blocked_address', 0],
    'an address that is not a Discord webhook' => [fn () => config(['alerts.discord.price_job_webhook_url' => 'https://192.0.2.10/api/webhooks/1/SENTINELTOKEN-0003']), null, 'blocked_address', 0],
]);

// f. The address stays out of logs, output and tables.

it('keeps the address out of every log line, the output and every table when the send throws with it', function (Closure $answer, string $error): void {
    pprOk('2026-10-11', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');
    pprAnswer($answer);

    $thrown = null;
    try {
        [$code, $output] = pprRun();
    } catch (Throwable $e) {
        $thrown = $e->getMessage();
        [$code, $output] = [null, ''];
    }

    $logs = (string) json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);
    $context = AlertRows::logged('price_problem_report_failed')[0]['context'] ?? [];
    expect($thrown)->toBeNull()
        ->and($code)->toBe(1)
        ->and($context['error'] ?? null)->toBe($error)
        ->and($logs.$output)->not->toContain('SENTINEL')
        ->and($logs.$output)->not->toContain('api/webhooks')
        ->and($logs.$output)->not->toContain('discord.com')
        ->and(pprTablesHolding('SENTINEL'))->toBe([])
        ->and(pprTablesHolding('api/webhooks'))->toBe([])
        ->and(DB::table('reported_price_problems')->count())->toBe(0);
})->with([
    'a connection error carrying the address' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.PPR_URL), 'connection'],
    'an exception carrying the address' => [fn () => throw new RuntimeException('boom '.PPR_URL), 'internal'],
]);

it('writes no address into the table after a run that posted', function (): void {
    pprOk('2026-10-10', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');

    pprRun();

    $rows = (string) json_encode(DB::table('reported_price_problems')->get());
    expect(DB::table('reported_price_problems')->count())->toBe(2)
        ->and($rows)->not->toContain('SENTINEL')
        ->and($rows)->not->toContain('webhooks')
        ->and(pprTablesHolding('SENTINEL'))->toBe([]);
});

// g. Between two runs, the end of a hold, and a month boundary.

it('posts and records nothing for a problem that starts and clears between two runs', function (): void {
    pprOk('2026-10-11', '2026-10-12');
    pprAt('2026-10-13T22:00:00Z');
    [, , $before] = pprRun();

    // At 22:05:44 the newest day turns late; at 22:45 the next day's run finishes. No run saw the late day.
    pprAt('2026-10-13T22:30:00Z');
    $lateBetween = array_column(PriceJobProblems::all(), 'key');
    pprOk('2026-10-13');
    pprAt('2026-10-13T23:00:00Z');
    [$code, $output, $after] = pprRun();

    expect($before)->toBe([])
        ->and($lateBetween)->toBe(['late:2026-10-12'])
        ->and([$code, $output, $after])->toBe([0, '{"posted":0,"cleared":0,"failed":0}', []])
        ->and(DB::table('reported_price_problems')->count())->toBe(0);
});

it('reports a day on hold, and reports it cleared when the hold ends although the day still has no run', function (): void {
    pprOk('2026-10-11');
    pprIngest('failed', '2026-10-12', '2026-10-12T20:50:00Z', '2026-10-12T21:00:00Z', 'boom');
    $newest = pprIngest('failed', '2026-10-12', '2026-10-12T21:05:00Z', '2026-10-12T21:10:00Z', 'boom');
    pprAt('2026-10-12T21:30:00Z');
    [, , $held] = pprRun();

    // Six hours after the second failure the hold is over; by then the newest ok day is late.
    pprAt('2026-10-13T04:00:00Z');
    [, $output, $posted] = pprRun();

    expect($held)->toBe(['PokéPrices price job problem: 2026-10-12 failed twice in a row and is on hold. Seen since 2026-10-12T21:30:00Z.'])
        ->and($output)->toBe('{"posted":1,"cleared":1,"failed":0}')
        ->and($posted)->toBe([
            'PokéPrices price job problem: The newest prices are from 2026-10-11, which is past the limit of 26 hours. Seen since 2026-10-13T04:00:00Z.',
            'PokéPrices price job problem cleared: 2026-10-12 failed twice in a row and is on hold. That was seen since 2026-10-12T21:30:00Z and is over as of 2026-10-13T04:00:00Z.',
        ])
        ->and(pprKeys(all: true))->toBe(["stuck:2026-10-12:hold:{$newest}", 'late:2026-10-11'])
        ->and(pprKeys())->toBe(['late:2026-10-11']);
});

it('carries a report across the end of a month: nothing again in the new month, and the cleared message names both instants', function (): void {
    pprOk('2026-10-28', '2026-10-29');
    pprAt('2026-10-31T23:00:00Z');
    [, , $october] = pprRun();
    pprAt('2026-11-01T00:00:00Z');
    [, , $november] = pprRun();

    // The run of 31 October lands; 30 October was never collected.
    pprOk('2026-10-31');
    pprAt('2026-11-01T01:00:00Z');
    [, , $afterRun] = pprRun();

    expect($october)->toBe(['PokéPrices price job problem: The newest prices are from 2026-10-29, which is past the limit of 26 hours. Seen since 2026-10-31T23:00:00Z.'])
        ->and($november)->toBe([])
        ->and($afterRun)->toBe([
            'PokéPrices price job problem: No price run finished for 2026-10-30. Seen since 2026-11-01T01:00:00Z.',
            'PokéPrices price job problem cleared: The newest prices are from 2026-10-29, which is past the limit of 26 hours. That was seen since 2026-10-31T23:00:00Z and is over as of 2026-11-01T01:00:00Z.',
        ])
        ->and(pprKeys())->toBe(['missing_day:2026-10-30']);
});

// h. The schedule.

it('is scheduled hourly as a command the scheduler runs itself, without overlapping, and queues nothing', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e): bool => $e->description === 'pokeprices-price-problems')->values();
    $event = $events->first();
    $due = function (string $at) use ($event): bool {
        Carbon::setTestNow(CarbonImmutable::parse($at));

        return $event !== null && $event->isDue(app());
    };

    expect($events)->toHaveCount(1)
        ->and($event)->not->toBeInstanceOf(CallbackEvent::class)
        ->and((string) $event->command)->toContain('pokeprices:prices:report-problems')
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(59)
        ->and($event->runInBackground)->toBeFalse()
        ->and($due('2026-10-13T23:00:00Z'))->toBeTrue()
        ->and($due('2026-10-13T23:01:00Z'))->toBeFalse()
        ->and($due('2026-11-01T00:00:00Z'))->toBeTrue();

    Queue::fake();
    Bus::fake();
    pprOk('2026-10-11', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');
    [$code, , $posted] = pprRun();

    expect($code)->toBe(0)->and($posted)->toHaveCount(1);
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
});

// i. Two runs at the same moment.

it('does nothing while another run holds the report lock, then posts once', function (): void {
    pprOk('2026-10-11', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');
    config(['database.connections.ppr_holder' => config('database.connections.pgsql')]);
    $holder = DB::connection('ppr_holder');
    $holder->select('SELECT pg_advisory_lock(?)', [ReportedPriceProblems::LOCK_KEY]);
    try {
        [$code, $output, $posted] = pprRun();
        $recorded = DB::table('reported_price_problems')->count();
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [ReportedPriceProblems::LOCK_KEY]);
        DB::purge('ppr_holder');
    }

    [$codeFree, , $postedFree] = pprRun();
    // The run gave its lock back: a third run is not locked out, and has nothing left to post.
    [$codeThird, $outputThird, $postedThird] = pprRun();

    expect(ReportedPriceProblems::LOCK_KEY)->toBe(crc32('pricing:problem-report'))
        ->and([$code, $output, $posted, $recorded])->toBe([0, 'Another price-job report is running; nothing was sent.', [], 0])
        ->and($codeFree)->toBe(0)
        ->and($postedFree)->toHaveCount(1)
        ->and([$codeThird, $outputThird, $postedThird])->toBe([0, '{"posted":0,"cleared":0,"failed":0}', []]);
});

it('sends through the sender of the alert deliveries, so its guard and timeouts apply', function (): void {
    pprOk('2026-10-11', '2026-10-12');
    pprAt('2026-10-13T23:00:00Z');
    $options = [];
    Http::fake(function (ClientRequest $request, array $given) use (&$options) {
        $options = $given;

        return Http::response('', 204);
    });

    pprRun();

    $request = Http::recorded()->first()[0];
    expect($options['connect_timeout'] ?? null)->toBe(2)
        ->and($options['timeout'] ?? null)->toBe(5)
        ->and($options['allow_redirects'] ?? null)->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['discord.com:443:192.0.2.10'])
        ->and($request->header('User-Agent'))->toBe([DeliverySender::USER_AGENT])
        ->and(json_decode($request->body(), true)['allowed_mentions'] ?? null)->toBe(['parse' => []]);
});
