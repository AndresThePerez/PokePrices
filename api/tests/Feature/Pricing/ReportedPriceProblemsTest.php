<?php

declare(strict_types=1);

use App\Domain\Pricing\ReportedPriceProblems;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// The price-job problems that were reported, kept in Postgres so a restart repeats nothing: one row per report with
// the problem's key, when it was reported and when it was reported cleared. One open row per key at most.

/** @return array{kind: 'late'|'missing_day'|'refused'|'stuck', day: string|null, key: string, sentence: string} */
function rppProblem(string $day = '2026-10-12'): array
{
    return ['kind' => 'missing_day', 'day' => $day, 'key' => "missing_day:{$day}", 'sentence' => "No price run finished for {$day}."];
}

it('has the table of reports with its columns, and one open row per key by a unique index', function (): void {
    $columns = array_map(
        fn (object $c): array => [(string) $c->column_name, (string) $c->data_type, (string) $c->is_nullable],
        DB::select("SELECT column_name, data_type, is_nullable FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'reported_price_problems' ORDER BY ordinal_position"),
    );
    $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'reported_price_problems_open_key_unique'");

    expect($columns)->toBe([
        ['id', 'bigint', 'NO'],
        ['key', 'text', 'NO'],
        ['kind', 'text', 'NO'],
        ['day', 'date', 'YES'],
        ['sentence', 'text', 'NO'],
        ['reported_at', 'timestamp with time zone', 'NO'],
        ['cleared_at', 'timestamp with time zone', 'YES'],
    ])
        ->and((string) ($index->indexdef ?? ''))->toContain('CREATE UNIQUE INDEX')
        ->and((string) ($index->indexdef ?? ''))->toContain('(key) WHERE (cleared_at IS NULL)');
});

it('drops the table on the way down and builds it again on the way up', function (): void {
    $migration = require database_path('migrations/2026_11_24_000001_create_reported_price_problems_table.php');
    $exists = fn (): bool => DB::selectOne("SELECT to_regclass('reported_price_problems') IS NOT NULL AS there")->there === true;

    $migration->down();
    $afterDown = $exists();
    $migration->up();

    expect($afterDown)->toBeFalse()
        ->and($exists())->toBeTrue()
        ->and(ReportedPriceProblems::open())->toBe([]);
});

it('records a problem once: a second report of the same open key changes nothing', function (): void {
    $first = CarbonImmutable::parse('2026-10-14T03:00:00Z');

    $recorded = ReportedPriceProblems::report(rppProblem(), $first);
    $again = ReportedPriceProblems::report(rppProblem(), $first->addHour());

    $open = ReportedPriceProblems::open();
    expect($recorded)->toBeTrue()
        ->and($again)->toBeFalse()
        ->and($open)->toHaveCount(1)
        ->and(array_keys($open[0]))->toBe(['id', 'key', 'kind', 'day', 'sentence', 'reported_at'])
        ->and($open[0]['key'])->toBe('missing_day:2026-10-12')
        ->and($open[0]['kind'])->toBe('missing_day')
        ->and($open[0]['day'])->toBe('2026-10-12')
        ->and($open[0]['sentence'])->toBe('No price run finished for 2026-10-12.')
        ->and($open[0]['reported_at']->equalTo($first))->toBeTrue()
        ->and(DB::table('reported_price_problems')->count())->toBe(1);
});

it('clears a report once, and the same key reported later is a new open row', function (): void {
    $first = CarbonImmutable::parse('2026-10-14T03:00:00Z');
    ReportedPriceProblems::report(rppProblem(), $first);
    $id = ReportedPriceProblems::open()[0]['id'];

    $cleared = ReportedPriceProblems::clear($id, $first->addHours(2));
    $clearedAgain = ReportedPriceProblems::clear($id, $first->addHours(3));
    $openAfterClear = ReportedPriceProblems::open();
    $reportedAgain = ReportedPriceProblems::report(rppProblem(), $first->addHours(5));

    $rows = DB::table('reported_price_problems')->orderBy('id')->get();
    expect($cleared)->toBeTrue()
        ->and($clearedAgain)->toBeFalse()
        ->and($openAfterClear)->toBe([])
        ->and($reportedAgain)->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and(CarbonImmutable::parse((string) $rows[0]->cleared_at)->equalTo($first->addHours(2)))->toBeTrue()
        ->and($rows[1]->cleared_at)->toBeNull()
        ->and(array_column(ReportedPriceProblems::open(), 'id'))->toBe([(int) $rows[1]->id]);
});

it('lists the open reports oldest first and keeps a problem without a day', function (): void {
    $at = CarbonImmutable::parse('2026-10-31T23:00:00Z');
    ReportedPriceProblems::report(['kind' => 'late', 'day' => null, 'key' => 'late:none', 'sentence' => 'No price day has been ingested yet.'], $at);
    ReportedPriceProblems::report(rppProblem('2026-10-30'), $at->addHour());
    ReportedPriceProblems::report(rppProblem('2026-10-29'), $at->addHours(2));

    $open = ReportedPriceProblems::open();

    expect(array_column($open, 'key'))->toBe(['late:none', 'missing_day:2026-10-30', 'missing_day:2026-10-29'])
        ->and($open[0]['day'])->toBeNull()
        ->and($open[1]['reported_at']->format('Y-m-d\TH:i:s\Z'))->toBe('2026-11-01T00:00:00Z');
});

it('refuses a second open row for one key in the table itself', function (): void {
    $row = ['key' => 'late:2026-10-12', 'kind' => 'late', 'day' => '2026-10-12', 'sentence' => 'x', 'reported_at' => '2026-10-14T03:00:00Z'];
    DB::table('reported_price_problems')->insert($row);

    $state = null;
    try {
        DB::transaction(fn () => DB::table('reported_price_problems')->insert($row));
    } catch (QueryException $e) {
        $state = (string) $e->getCode();
    }

    expect($state)->toBe('23505')
        ->and(DB::table('reported_price_problems')->count())->toBe(1);
});
