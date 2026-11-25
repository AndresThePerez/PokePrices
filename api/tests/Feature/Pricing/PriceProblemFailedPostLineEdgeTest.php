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

// Edge cases of the failure line of the price-job report: a redirect or a 404 shows its status and never the Location,
// an address the guard refuses shows a word, no address shows no line, and a key with escape characters is one line.
// The address is made up (a fake token and marker); HTTP is faked and discord.com resolves to 192.0.2.10.

const PPFE_URL = 'https://discord.com/api/webhooks/200000000000000000/EDGETOKEN-SENTINEL-0000';

/** The answer the fake gives, set by the test. */
function ppfeAnswer(?Closure $set = null): Closure
{
    static $answer = null;
    if ($set !== null) {
        $answer = $set;
    }

    return $answer ?? fn () => Http::response('', 204);
}

function ppfeInstall(): void
{
    config(['alerts.discord.price_job_webhook_url' => PPFE_URL]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10']]);
    AlertRows::captureLogs();
    ppfeAnswer(fn () => Http::response('', 204));
    Http::fake(fn (ClientRequest $request) => ppfeAnswer()($request));
}

/** @return array{0: int, 1: string} the exit code and the output of one run */
function ppfeRun(): array
{
    $code = Artisan::call('pokeprices:prices:report-problems');

    return [$code, trim(Artisan::output())];
}

/** One late problem (key late:2026-10-10) for the run to post. */
function ppfeOneProblem(): void
{
    DB::table('ingest_runs')->insert([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-10', 'marker' => '2026-10-10T20:05:44Z', 'mode' => 'queued',
        'status' => 'ok', 'started_at' => '2026-10-10T21:40:00Z', 'finished_at' => '2026-10-10T21:41:00Z', 'counts' => '{}',
    ]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-11T23:00:00Z'));
}

/** @return list<string> the lines of an output that are failure lines */
function ppfeLines(string $output): array
{
    return array_values(array_filter(explode("\n", $output), fn (string $line): bool => str_starts_with($line, 'price_problem_report_failed')));
}

beforeEach(function (): void {
    ppfeInstall();
});

afterEach(function (): void {
    Carbon::setTestNow();
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === 'pokeprices-price-problems');
    if ($event !== null && is_string($event->output) && is_file($event->output)) {
        unlink($event->output);
    }
});

it('shows only the status for a redirect or a 404, never the Location the answer carries', function (int $status): void {
    ppfeOneProblem();
    ppfeAnswer(fn () => Http::response('', $status, ['Location' => 'https://192.0.2.10/LOCATIONMARKER/api/webhooks/1/EDGEMOVED']));

    [$code, $output] = ppfeRun();
    $logs = (string) json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);

    expect($code)->toBe(1)
        ->and(ppfeLines($output))->toBe(["price_problem_report_failed key=late:2026-10-10 event=started status={$status}"])
        ->and(explode("\n", $output))->toHaveCount(2)
        ->and($output.$logs)->not->toContain('LOCATIONMARKER')
        ->and($output.$logs)->not->toContain('EDGEMOVED')
        ->and($output.$logs)->not->toContain('EDGETOKEN');
})->with([301, 302, 404]);

it('shows the word refused, on one line and without a request, for an address the guard or the shape check refuses', function (string $url): void {
    ppfeOneProblem();
    config(['alerts.discord.price_job_webhook_url' => $url]);
    FakeHostResolver::install(['discord.com' => ['192.0.2.10'], 'example.com' => ['192.0.2.10']]);

    [$code, $output] = ppfeRun();
    $logs = (string) json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);

    expect($code)->toBe(1)
        ->and(ppfeLines($output))->toBe(['price_problem_report_failed key=late:2026-10-10 event=started status=refused'])
        ->and(explode("\n", $output))->toHaveCount(2)
        ->and($output.$logs)->not->toContain('PRIVATEMARKER')
        ->and($output.$logs)->not->toContain('127.0.0.1')
        ->and($output.$logs)->not->toContain('example.com');
    Http::assertNothingSent();
})->with([
    'a private address literal' => ['https://127.0.0.1/api/webhooks/200000000000000000/PRIVATEMARKER'],
    'a plain http scheme' => ['http://discord.com/api/webhooks/200000000000000000/PRIVATEMARKER'],
    'a host that is not Discord' => ['https://example.com/api/webhooks/200000000000000000/PRIVATEMARKER'],
]);

it('writes the word unresolvable when the Discord host does not resolve', function (): void {
    ppfeOneProblem();
    FakeHostResolver::install([]);

    [$code, $output] = ppfeRun();

    expect($code)->toBe(1)
        ->and(ppfeLines($output))->toBe(['price_problem_report_failed key=late:2026-10-10 event=started status=unresolvable']);
    Http::assertNothingSent();
});

it('prints no failure line, sends nothing and exits 0 when no address is set or it is blank', function (mixed $value): void {
    ppfeOneProblem();
    config(['alerts.discord.price_job_webhook_url' => $value]);

    [$code, $output] = ppfeRun();

    expect($code)->toBe(0)
        ->and($output)->toBe(PricesReportProblems::NOT_SET)
        ->and(ppfeLines($output))->toBe([]);
    Http::assertNothingSent();
})->with([null, '', '   ']);

it('keeps a key holding an escape sequence and non-ASCII letters on one plain line', function (): void {
    ppfeOneProblem();
    DB::table('reported_price_problems')->insert([
        'key' => "gone:\e[31mred\e[0m \u{00E9}\u{0007}\u{007F}", 'kind' => 'gone', 'sentence' => 'A problem that is gone.',
        'reported_at' => '2026-10-11T22:00:00Z', 'cleared_at' => null,
    ]);
    ppfeAnswer(fn () => Http::response('', 500));

    [, $output] = ppfeRun();
    $lines = ppfeLines($output);

    expect($lines)->toHaveCount(2)
        ->and($lines[1])->toMatch('/^price_problem_report_failed key=[A-Za-z0-9:._-]+ event=cleared status=500$/')
        ->and($output)->not->toContain("\e")
        ->and(explode("\n", $output))->toHaveCount(3);
});

it('prints nothing for a 200 or a 204, and the scheduler shows the real failure line of a run and nothing else', function (): void {
    ppfeOneProblem();
    ppfeAnswer(fn () => Http::response('', 200));
    [$codeOk, $outputOk] = ppfeRun();

    ppfeAnswer(fn () => throw new ConnectionException('cURL error 7: refused for '.PPFE_URL));
    DB::table('reported_price_problems')->delete();
    [$codeBad, $outputBad] = ppfeRun();

    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === 'pokeprices-price-problems');
    file_put_contents($event->output, $outputBad."\n");
    ob_start();
    $event->finish(app(), $codeBad);
    $shown = (string) ob_get_clean();

    expect($codeOk)->toBe(0)
        ->and($outputOk)->toBe('{"posted":1,"cleared":0,"failed":0}')
        ->and($codeBad)->toBe(1)
        ->and($shown)->toBe("price_problem_report_failed key=late:2026-10-10 event=started status=connection\n")
        ->and($shown)->not->toContain('EDGETOKEN');
});
