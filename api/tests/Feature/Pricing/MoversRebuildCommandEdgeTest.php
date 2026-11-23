<?php

declare(strict_types=1);

use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\Movers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// The documented rebuild of stored movers days (docs/OPERATIONS.md, "Rebuild the movers of stored days"), run as the
// document writes it, against the test database only: the tinker --execute code is read from the document itself and
// handed to artisan tinker. It must rewrite the named day and leave every other day's price_movers rows byte-identical,
// even when the price tables would now give those days other rows, and change nothing while the ingest lock is held.

/** The PHP code of the documented one-day rebuild, exactly as docs/OPERATIONS.md gives it. */
function t04zDocumentedCode(): string
{
    $doc = (string) file_get_contents(base_path('../docs/OPERATIONS.md'));
    $section = substr($doc, (int) strpos($doc, '### Rebuild the movers of stored days'));
    expect(preg_match("/php artisan tinker --execute='([^']+)'\n/", $section, $m))->toBe(1);

    return $m[1];
}

/** Runs $code through artisan tinker and returns what it printed. */
function t04zTinker(string $code): string
{
    ob_start();
    try {
        $exit = Artisan::call('tinker', ['--execute' => $code]);
    } finally {
        $printed = (string) ob_get_clean();
    }
    expect($exit)->toBe(0);

    return $printed.Artisan::output();
}

/** A variant "<card>:normal:-:-" with one real close and its in-line observation (low 90 %, mid 105 %) per day. */
function t04zSeries(string $card, array $closes): string
{
    $key = "{$card}:normal:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'normal', 'edition' => '-',
        'treatment' => '-', 'catalog_status' => 'unverified']);
    $run = DB::table('ingest_runs')->where('day', '2026-09-01')->value('id')
        ?? DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-09-01', 'marker' => '2026-09-01 20:05:00+00',
            'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-09-01 20:10:00+00', 'finished_at' => '2026-09-01 20:11:00+00']);
    foreach ($closes as $day => $close) {
        DB::statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            VALUES (?, 'tcgplayer', ?::date, 1, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
            SQL, [$key, $day, $close, $close, $close, $close, $day]);
        DB::statement(<<<'SQL'
            INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                      market, low, mid, high, direct_low, ingest_run_id)
            VALUES (?, 'tcgplayer', ?::date + interval '20 hours 5 minutes', ?::date, 1, 1, 'Normal', ?::numeric,
                    trunc(?::numeric * 0.90, 2), trunc(?::numeric * 1.05, 2), NULL, NULL, ?)
            SQL, [$key, $day, $day, $close, $close, $close, $run]);
    }

    return $key;
}

/** @return list<string> the price_movers rows with every column as text, of the days $where selects, in key order */
function t04zDump(string $where): array
{
    return array_map(
        fn (object $r): string => implode('|', (array) $r),
        DB::select(<<<SQL
            SELECT day::text, source, direction, rank::text, variant_key, card_id, series_epoch::text, close::text,
                   previous_close::text, previous_day::text, change::text, change_pct::text,
                   to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"') AS as_of
            FROM price_movers WHERE {$where} ORDER BY day, source, direction, rank
            SQL),
    );
}

test('the documented rebuild rewrites only its named day, leaves every other day byte-identical, repeats to the same rows and changes nothing under a held lock', function (): void {
    $code = t04zDocumentedCode();
    expect($code)->toContain('Movers::rebuild($db, "2026-10-13", "2026-10-13")');

    $days = ['2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13', '2026-10-14'];
    $rise = t04zSeries('zz-rise', array_combine($days, ['20.00', '21.50', '23.00', '24.50', '26.00', '27.50', '29.00']));
    $fall = t04zSeries('zz-fall', array_combine($days, ['40.00', '37.00', '34.50', '32.00', '30.00', '28.00', '26.00']));
    Movers::rebuild(DB::connection(), '2026-10-08', '2026-10-14');
    // An old-rule row on 10-12 that the rule as it is would never give: a rebuild of 10-12 would remove it.
    t04zSeries('zz-old', ['2026-10-11' => '10.00', '2026-10-12' => '15.00']);
    DB::table('price_movers')->insert(['day' => '2026-10-12', 'source' => 'tcgplayer', 'direction' => 'up', 'rank' => 3,
        'variant_key' => 'zz-old:normal:-:-', 'card_id' => 'zz-old', 'series_epoch' => 1, 'close' => '15.00', 'previous_close' => '10.00',
        'previous_day' => '2026-10-11', 'change' => '5.00', 'change_pct' => '50.00', 'as_of' => '2026-10-12 20:05:00+00']);
    // The rise's 10-12 close is now out of line with its observation (it changes 10-12 and 10-13 when rebuilt), and the
    // fall's 10-14 close too (it changes 10-14 when rebuilt).
    DB::table('price_points')->where('variant_key', $rise)->where('day', '2026-10-12')->update(['low' => '60.00']);
    DB::table('price_points')->where('variant_key', $fall)->where('day', '2026-10-14')->update(['mid' => '12.00']);
    $others = t04zDump("day <> '2026-10-13'");
    $thirteenBefore = t04zDump("day = '2026-10-13'");

    $printed = t04zTinker($code);
    $thirteen = t04zDump("day = '2026-10-13'");

    // 10-11, 10-12 and 10-14 hold the rise and the fall each, 10-12 the old-rule row too.
    expect(count($others))->toBe(7)
        ->and($thirteenBefore)->toHaveCount(2)
        ->and(trim($printed))->toBe(DB::connection()->getDatabaseName().' [{"direction":"down","n":1}]')
        ->and(t04zDump("day <> '2026-10-13'"))->toBe($others)
        // 10-13 now holds what the rule gives: the rise is off (its previous close is out of line), the fall stays.
        ->and($thirteen)->toBe(['2026-10-13|tcgplayer|down|1|zz-fall:normal:-:-|zz-fall|1|28.00|30.00|2026-10-12|-2.00|-6.67|2026-10-13T20:05:00.000000Z']);

    // Run twice: the same rows.
    t04zTinker($code);
    expect(t04zDump('true'))->toBe([...array_filter($others, fn (string $r): bool => $r < '2026-10-13'), ...$thirteen,
        ...array_filter($others, fn (string $r): bool => $r > '2026-10-14')]);

    // With the ingest lock held by another session, it says so and changes nothing, though 10-13 would now differ.
    DB::table('price_points')->where('variant_key', $rise)->where('day', '2026-10-12')->update(['low' => '23.40']);
    $all = t04zDump('true');
    config(['database.connections.t04z_lock' => config('database.connections.'.DB::getDefaultConnection())]);
    $holder = DB::connection('t04z_lock');
    try {
        expect($holder->selectOne('SELECT pg_try_advisory_lock(?) AS taken', [IngestLock::KEY])->taken)->toBeTrue();
        $held = t04zTinker($code);
    } finally {
        $holder->select('SELECT pg_advisory_unlock(?)', [IngestLock::KEY]);
        DB::purge('t04z_lock');
    }

    expect(trim($held))->toBe('pricing ingest lock held, nothing changed')
        ->and(t04zDump('true'))->toBe($all);
});
