<?php

declare(strict_types=1);

use App\Domain\Pricing\Models\IngestRunChunk;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;

// The chunk rows of a superseded run are kept 30 days (720 h) after the run finished, then pruned once a day. Chunks
// of every other run, and every run row, price row, snapshot file and oracle file, stay.

/** Inserts one tcgplayer ingest run and $chunks chunk rows for it; returns the run id. */
function chunkRetentionRun(string $status, string $mode, ?CarbonImmutable $finishedAt, int $chunks): int
{
    $id = (int) DB::table('ingest_runs')->insertGetId([
        'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-09-01', 'marker' => '2026-09-01 20:05:44+00', 'mode' => $mode,
        'status' => $status, 'started_at' => '2026-09-01 20:10:00+00', 'finished_at' => $finishedAt?->format('Y-m-d H:i:sP'),
        'counts' => '{}',
    ]);
    for ($i = 0; $i < $chunks; $i++) {
        DB::table('ingest_run_chunks')->insert([
            'ingest_run_id' => $id, 'chunk_key' => "1:{$i}", 'group_id' => 1, 'offset' => $i, 'rows' => 1, 'status' => 'done',
        ]);
    }

    return $id;
}

/** @return array<string, int> the row count of every price_* table */
function chunkRetentionPriceCounts(): array
{
    $counts = [];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'price\\_%' ORDER BY tablename") as $row) {
        $counts[(string) $row->tablename] = DB::table((string) $row->tablename)->count();
    }

    return $counts;
}

/** The one scheduled event named pokeprices-prune-chunks. */
function chunkRetentionEvent(): ScheduledEvent
{
    $events = collect(app(Schedule::class)->events())->filter(fn (ScheduledEvent $e): bool => $e->description === 'pokeprices-prune-chunks')->values();
    expect($events)->toHaveCount(1);

    return $events->first();
}

it('prunes once a day only the chunks of superseded runs finished more than 720 hours ago, and keeps every run, price row and file', function (): void {
    $now = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
    $this->travelTo($now);
    $ancient = $now->subYears(3);

    $old = chunkRetentionRun('superseded', 'queued', $now->subHours(721), 3);
    $recent = chunkRetentionRun('superseded', 'queued', $now->subHours(719), 2);
    $ok = chunkRetentionRun('ok', 'queued', $ancient, 1);
    $failed = chunkRetentionRun('failed', 'queued', $ancient, 1);
    $refused = chunkRetentionRun('refused', 'queued', $ancient, 1);
    $skipped = chunkRetentionRun('skipped_unchanged', 'queued', $ancient, 1);
    $running = chunkRetentionRun('running', 'queued', null, 1);
    $runningSuperseded = chunkRetentionRun('superseded', 'queued', null, 1);

    AccountRows::price('base1-4:holo:unlimited:-', '12.34', '2026-09-01');
    DB::table('price_points')->insert([
        'variant_key' => 'base1-4:holo:unlimited:-', 'source' => 'tcgplayer', 'observed_at' => '2026-10-01 20:05:44+00', 'day' => '2026-10-01',
        'series_epoch' => 1, 'source_product_id' => 1, 'source_subtype' => 'Holofoil', 'market' => '12.34', 'ingest_run_id' => $old,
    ]);

    $root = sys_get_temp_dir().'/chunk-retention-'.bin2hex(random_bytes(6));
    $snapshot = "{$root}/snapshots/2026-09-01/prices.json";
    $oracle = "{$root}/oracle/2026-09-01/cards.json";
    foreach ([$snapshot, $oracle] as $file) {
        mkdir(dirname($file), 0o777, true);
        file_put_contents($file, '{}');
    }
    config(['services.tcgcsv.snapshots' => "{$root}/snapshots", 'services.oracle.root' => "{$root}/oracle"]);

    $runs = DB::table('ingest_runs')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all();
    $prices = chunkRetentionPriceCounts();

    $event = chunkRetentionEvent();
    expect($event->expression)->toBe('0 0 * * *');
    preg_match('/\'artisan\' model:prune --model=\'?([A-Za-z\\\\]+)\'?\s*$/', (string) $event->command, $m);
    expect($m[1] ?? null)->toBe(IngestRunChunk::class);

    $code = Artisan::call('model:prune', ['--model' => [$m[1]]]);

    $left = DB::table('ingest_run_chunks')->orderBy('ingest_run_id')->pluck('ingest_run_id')->map(fn ($id): int => (int) $id)->all();
    expect($code)->toBe(0)
        ->and($left)->toBe([$recent, $recent, $ok, $failed, $refused, $skipped, $running, $runningSuperseded])
        ->and(DB::table('ingest_runs')->orderBy('id')->get()->map(fn (object $r): array => (array) $r)->all())->toBe($runs)
        ->and(chunkRetentionPriceCounts())->toBe($prices)
        ->and($prices['price_points'] ?? 0)->toBe(1)
        ->and(is_file($snapshot))->toBeTrue()
        ->and(is_file($oracle))->toBeTrue();

    foreach ([$snapshot, $oracle] as $file) {
        unlink($file);
        rmdir(dirname($file));
        rmdir(dirname($file, 2));
    }
    rmdir($root);
});
