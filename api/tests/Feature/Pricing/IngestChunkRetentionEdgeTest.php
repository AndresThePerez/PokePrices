<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\IngestRunChunk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;

// Review tests for the superseded-run chunk prune: the 720 h cutoff is strict, zone-independent and exact; only
// ingest_run_chunks rows are ever deleted; --pretend deletes nothing; a prune bigger than one chunkById page finishes.

/** Inserts an ingest run and $chunks chunk rows for it; returns the run id. */
function chunkEdgeRun(string $status, ?CarbonImmutable $finishedAt, int $chunks, string $mode = 'queued'): int
{
    $id = (int) DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-09-01', 'marker' => '2026-09-01 20:05:44+00', 'mode' => $mode,
        'status' => $status, 'started_at' => '2026-09-01 20:10:00+00', 'finished_at' => $finishedAt?->format('Y-m-d H:i:sP'), 'counts' => '{}',
    ]);
    if ($chunks > 0) {
        DB::table('ingest_run_chunks')->insert(array_map(fn (int $i): array => [
            'ingest_run_id' => $id, 'chunk_key' => "1:{$i}", 'group_id' => 1, 'offset' => $i, 'rows' => 1, 'status' => 'done',
        ], range(0, $chunks - 1)));
    }

    return $id;
}

/** @return list<int> the run ids that still own at least one chunk */
function chunkEdgeOwners(): array
{
    return DB::table('ingest_run_chunks')->distinct()->orderBy('ingest_run_id')->pluck('ingest_run_id')->map(fn ($id): int => (int) $id)->all();
}

function chunkEdgePrune(string ...$options): int
{
    return Artisan::call('model:prune', ['--model' => [IngestRunChunk::class], ...array_fill_keys($options, true)]);
}

it('keeps a superseded run finished exactly 720 hours ago and prunes one finished a second earlier', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    $atLimit = chunkEdgeRun('superseded', $now->subHours(720), 1);
    $justOver = chunkEdgeRun('superseded', $now->subHours(720)->subSecond(), 1);
    $justUnder = chunkEdgeRun('superseded', $now->subHours(720)->addSecond(), 1);

    expect(chunkEdgePrune())->toBe(0)
        ->and(chunkEdgeOwners())->toBe([$atLimit, $justUnder]);

    // The same instant, pruned again, is stable: the boundary run is still kept until the clock moves past it.
    expect(chunkEdgePrune())->toBe(0)
        ->and(chunkEdgeOwners())->toBe([$atLimit, $justUnder])
        ->and($justOver)->not->toBeIn(chunkEdgeOwners());

    $this->travelTo($now->addSecond());
    chunkEdgePrune();
    expect(chunkEdgeOwners())->toBe([$justUnder]);
});

it('decides the cutoff on the instant, whatever the database session zone and the application zone are', function (): void {
    $before = (string) DB::selectOne('SHOW TIME ZONE')->TimeZone;
    config(['app.timezone' => 'Pacific/Auckland']);
    try {
        DB::statement("SET TIME ZONE 'Pacific/Auckland'");
        $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'Pacific/Auckland');
        $this->travelTo($now);
        $atLimit = chunkEdgeRun('superseded', $now->subHours(720), 1);
        $over = chunkEdgeRun('superseded', $now->subHours(720)->subSeconds(2), 1);
        $under = chunkEdgeRun('superseded', $now->subHours(719)->subMinutes(59), 1);

        expect(chunkEdgePrune())->toBe(0)
            ->and(chunkEdgeOwners())->toBe([$atLimit, $under])
            ->and($over)->not->toBeIn(chunkEdgeOwners());
    } finally {
        DB::statement("SET TIME ZONE '{$before}'");
    }
});

it('keeps the chunks of a running or otherwise unfinished-status run even when its finished_at is old', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    $old = $now->subYears(2);
    $keep = [];
    foreach (['running', 'ok', 'failed', 'refused', 'skipped_unchanged'] as $status) {
        foreach (['sync', 'queued', 'replay'] as $mode) {
            $keep[] = chunkEdgeRun($status, $old, 1, $mode);
        }
    }
    $gone = chunkEdgeRun('superseded', $old, 2, 'replay');

    chunkEdgePrune();

    expect(chunkEdgeOwners())->toBe($keep)
        ->and($gone)->not->toBeIn(chunkEdgeOwners())
        ->and(DB::table('ingest_runs')->count())->toBe(count($keep) + 1);
});

it('reports what it would prune under --pretend and deletes nothing', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    $old = chunkEdgeRun('superseded', $now->subDays(40), 3);
    $recent = chunkEdgeRun('superseded', $now->subDays(2), 1);

    $code = chunkEdgePrune('--pretend');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('3 ['.IngestRunChunk::class.'] records will be pruned.')
        ->and(DB::table('ingest_run_chunks')->count())->toBe(4)
        ->and(chunkEdgeOwners())->toBe([$old, $recent]);
});

it('deletes only ingest_run_chunks rows, one DELETE target, and touches no run or price row', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    $old = chunkEdgeRun('superseded', $now->subDays(31), 3);
    AccountRows::price('base1-4:holo:unlimited:-', '12.34', '2026-09-01');
    DB::table('price_points')->insert([
        'variant_key' => 'base1-4:holo:unlimited:-', 'source' => 'tcgplayer', 'observed_at' => '2026-10-01 20:05:44+00', 'day' => '2026-10-01',
        'series_epoch' => 1, 'source_product_id' => 1, 'source_subtype' => 'Holofoil', 'market' => '12.34', 'ingest_run_id' => $old,
    ]);
    $runs = DB::table('ingest_runs')->count();
    $points = DB::table('price_points')->count();

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(delete|update|insert|truncate)\b/i', $query->sql) === 1) {
            $writes[] = strtolower(preg_replace('/\s+/', ' ', $query->sql) ?? '');
        }
    });

    expect(chunkEdgePrune())->toBe(0);

    expect($writes)->not->toBe([])
        ->and(array_values(array_filter($writes, fn (string $sql): bool => ! str_starts_with($sql, 'delete from "ingest_run_chunks"'))))->toBe([])
        ->and(DB::table('ingest_runs')->count())->toBe($runs)
        ->and(DB::table('price_points')->count())->toBe($points)
        ->and(DB::table('ingest_run_chunks')->count())->toBe(0);
});

it('prunes a backlog larger than one page of the chunked delete and still spares the recent run', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    $old = chunkEdgeRun('superseded', $now->subDays(90), 0);
    foreach (array_chunk(range(0, 2499), 500) as $page) {
        DB::table('ingest_run_chunks')->insert(array_map(fn (int $i): array => [
            'ingest_run_id' => $old, 'chunk_key' => "1:{$i}", 'group_id' => 1, 'offset' => $i, 'rows' => 1, 'status' => 'done',
        ], $page));
    }
    $recent = chunkEdgeRun('superseded', $now->subDays(1), 2);

    expect(DB::table('ingest_run_chunks')->where('ingest_run_id', $old)->count())->toBe(2500)
        ->and(chunkEdgePrune())->toBe(0)
        ->and(chunkEdgeOwners())->toBe([$recent])
        ->and(DB::table('ingest_run_chunks')->count())->toBe(2)
        ->and(chunkEdgePrune())->toBe(0)
        ->and(DB::table('ingest_run_chunks')->count())->toBe(2);
});
