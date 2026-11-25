<?php

declare(strict_types=1);

use App\Console\Commands\PricesReportProblems;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// A failed Discord post of the price-job report (pokeprices:prices:report-problems) leaves one line in the command's
// output: price_problem_report_failed, the problem's key, started or cleared, and the HTTP status or a short word. The
// scheduler runs the command with its output captured in a file and shows the failure lines in its own output when the
// run fails. HTTP is faked and discord.com resolves to 192.0.2.10; the address below is made up and never appears.

const PPF_URL = 'https://discord.com/api/webhooks/200000000000000000/SENTINELTOKEN-LINE-0000';

/** The answer the fake gives from now on (set), or the current one. */
function ppfAnswer(?Closure $set = null): Closure
{
    static $answer = null;
    if ($set !== null) {
        $answer = $set;
    }

    return $answer ?? fn () => Http::response('', 204);
}

/** Sets the address, the DNS answer and the HTTP fake of this application, and the answer to 204. */
function ppfInstall(): void
{
    config(['alerts.discord.price_job_webhook_url' => PPF_URL]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    AlertRows::captureLogs();
    ppfAnswer(fn () => Http::response('', 204));
    Http::fake(fn (ClientRequest $request) => ppfAnswer()($request));
}

/** One run of the command: the exit code and the output. */
function ppfRun(): array
{
    $code = Artisan::call('pokeprices:prices:report-problems');

    return [$code, trim(Artisan::output())];
}

function ppfAt(string $instant): void
{
    Carbon::setTestNow(CarbonImmutable::parse($instant));
}

function ppfOk(string ...$days): void
{
    foreach ($days as $day) {
        DB::table('ingest_runs')->insert([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => $day, 'marker' => "{$day}T20:05:44Z", 'mode' => 'queued',
            'status' => 'ok', 'started_at' => "{$day}T21:40:00Z", 'finished_at' => "{$day}T21:41:00Z", 'counts' => '{}',
        ]);
    }
}

/** Two problems at once: 12 October is late and 11 October is missing (keys late:2026-10-12, missing_day:2026-10-11). */
function ppfTwoProblems(): void
{
    ppfOk('2026-10-10', '2026-10-12');
    ppfAt('2026-10-13T23:00:00Z');
}

/** @return list<string> the lines of an output that are failure lines */
function ppfLines(string $output): array
{
    return array_values(array_filter(explode("\n", $output), fn (string $line): bool => str_starts_with($line, 'price_problem_report_failed')));
}

beforeEach(function (): void {
    ppfInstall();
});

afterEach(function (): void {
    Carbon::setTestNow();
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === 'pokeprices-price-problems');
    if ($event !== null && is_string($event->output) && is_file($event->output)) {
        unlink($event->output);
    }
});

// a, b. One line for a failed post, with its status or its word.

it('writes one line with the key, the event and the status for a failed post', function (Closure $answer, string $event, string $status): void {
    if ($event === 'cleared') {
        ppfOk('2026-10-10', '2026-10-12');
        ppfAt('2026-10-12T22:00:00Z');
        ppfRun();
        ppfOk('2026-10-11');
        ppfAt('2026-10-12T23:00:00Z');
        $key = 'missing_day:2026-10-11';
    } else {
        ppfOk('2026-10-10');
        ppfAt('2026-10-11T23:00:00Z');
        $key = 'late:2026-10-10';
    }
    ppfAnswer($answer);

    [$code, $output] = ppfRun();

    expect($code)->toBe(1)
        ->and(ppfLines($output))->toBe(["price_problem_report_failed key={$key} event={$event} status={$status}"])
        ->and(explode("\n", $output)[1] ?? null)->toBe('{"posted":0,"cleared":0,"failed":1}');
})->with([
    'a 500 on a start' => [fn () => Http::response('', 500), 'started', '500'],
    'a 500 on a clear' => [fn () => Http::response('', 500), 'cleared', '500'],
    'a 429 on a start' => [fn () => Http::response('{"message": "You are being rate limited."}', 429, ['Retry-After' => '5']), 'started', '429'],
    'a 429 on a clear' => [fn () => Http::response('', 429), 'cleared', '429'],
    'a timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.PPF_URL), 'started', 'timeout'],
    'a connection failure' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.PPF_URL), 'started', 'connection'],
    'an exception' => [fn () => throw new RuntimeException('boom '.PPF_URL), 'started', 'internal'],
]);

it('writes the word refused when the address guard refuses the address', function (): void {
    ppfOk('2026-10-10');
    ppfAt('2026-10-11T23:00:00Z');
    FakeHostResolver::install(['discord.com' => ['::1']]);

    [$code, $output] = ppfRun();

    expect($code)->toBe(1)
        ->and(ppfLines($output))->toBe(['price_problem_report_failed key=late:2026-10-10 event=started status=refused']);
});

// c. One line per failed post, none for a post that was accepted.

it('writes one line per failed post, in the order of the posts, and none for an accepted post', function (): void {
    ppfTwoProblems();
    $answers = [Http::response('', 500), Http::response('', 204)];
    ppfAnswer(function () use (&$answers) {
        return array_shift($answers);
    });

    [$code, $output] = ppfRun();

    expect($code)->toBe(1)
        ->and(ppfLines($output))->toBe(['price_problem_report_failed key=late:2026-10-12 event=started status=500'])
        ->and($output)->toEndWith('{"posted":1,"cleared":0,"failed":1}');

    ppfAnswer(fn () => Http::response('', 503));
    ppfAt('2026-10-14T00:00:00Z');
    [, $second] = ppfRun();

    // The post that failed is tried again, alone: the other one was recorded.
    expect(ppfLines($second))->toBe(['price_problem_report_failed key=late:2026-10-12 event=started status=503']);

    ppfAnswer(fn () => Http::response('', 204));
    ppfAt('2026-10-14T01:00:00Z');
    [$codeFine, $fine] = ppfRun();

    expect($codeFine)->toBe(0)
        ->and(ppfLines($fine))->toBe([])
        ->and($fine)->toBe('{"posted":1,"cleared":0,"failed":0}');
});

it('writes two lines when two posts fail in one run', function (): void {
    ppfTwoProblems();
    ppfAnswer(fn () => Http::response('', 500));

    [, $output] = ppfRun();

    expect(ppfLines($output))->toBe([
        'price_problem_report_failed key=late:2026-10-12 event=started status=500',
        'price_problem_report_failed key=missing_day:2026-10-11 event=started status=500',
    ]);
});

// d. The address stays out of the output and the logs.

it('keeps the address and its path out of the output and the log lines, whatever the failure', function (Closure $answer): void {
    ppfTwoProblems();
    ppfAnswer($answer);

    [$code, $output] = ppfRun();
    $logs = (string) json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);

    expect($code)->toBe(1)
        ->and(ppfLines($output))->toHaveCount(2)
        ->and($logs.$output)->not->toContain('SENTINEL')
        ->and($logs.$output)->not->toContain('api/webhooks')
        ->and($logs.$output)->not->toContain('discord.com')
        ->and($logs.$output)->not->toContain('192.0.2.10');
})->with([
    'a 500' => [fn () => Http::response('', 500)],
    'a timeout carrying the address' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.PPF_URL)],
    'an exception carrying the address' => [fn () => throw new RuntimeException('boom '.PPF_URL)],
]);

// A key with unusual characters is still one line.

it('keeps a key with line breaks and spaces on one line', function (): void {
    ppfOk('2026-10-10', '2026-10-11', '2026-10-12');
    DB::table('reported_price_problems')->insert([
        'key' => "gone:2026-10-11\nINJECTED line\r\twith\u{2028}breaks", 'kind' => 'gone', 'sentence' => 'A problem that is gone.',
        'reported_at' => '2026-10-12T22:00:00Z', 'cleared_at' => null,
    ]);
    ppfAt('2026-10-12T23:00:00Z');
    ppfAnswer(fn () => Http::response('', 500));

    [, $output] = ppfRun();

    expect(ppfLines($output))->toBe(['price_problem_report_failed key=gone:2026-10-11_INJECTED_line__with___breaks event=cleared status=500'])
        ->and(explode("\n", $output))->toHaveCount(2);
});

// The scheduler: the lines reach its own output.

it('shows the failure lines, and only them, in the scheduler\'s own output when a run fails', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === 'pokeprices-price-problems');
    $captured = [
        'price_problem_report_failed key=late:2026-10-12 event=started status=500',
        'price_problem_report_failed key=missing_day:2026-10-11 event=cleared status=timeout',
        '{"posted":0,"cleared":0,"failed":2}',
    ];
    file_put_contents($event->output, implode("\n", $captured)."\n");

    ob_start();
    $event->finish(app(), 1);
    $shown = (string) ob_get_clean();

    file_put_contents($event->output, '{"posted":1,"cleared":0,"failed":0}'."\n");
    ob_start();
    $event->finish(app(), 0);
    $quiet = (string) ob_get_clean();

    expect($shown)->toBe($captured[0]."\n".$captured[1]."\n")
        ->and($quiet)->toBe('')
        ->and($event->command)->toContain('pokeprices:prices:report-problems')
        ->and($event->runInBackground)->toBeFalse()
        ->and(PricesReportProblems::failureLines("x\nprice_problem_report_failed key=a event=started status=500\ny"))
        ->toBe('price_problem_report_failed key=a event=started status=500');
});
