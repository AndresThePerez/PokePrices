<?php

declare(strict_types=1);

use App\Domain\Pricing\Movers;
use App\Domain\Pricing\PricingConfig;
use Illuminate\Support\Facades\DB;

// The upper listing bound of the movers: both closes of a move are at most one and a half times the mid listing of their
// own observation. Exactly 1.5 x counts, one cent over does not, and a close between 1.5 x and 2 x (ranked under the
// earlier bound of 2 x) is not ranked, as the close of the day and as the previous close. A day maps to a close as a
// string (listings in line: low 90 %, mid 105 % of it) or to [close, low, mid].

function t04uRun(): int
{
    $id = DB::table('ingest_runs')->where('source', 'tcgplayer')->orderBy('id')->value('id');
    if (is_numeric($id)) {
        return (int) $id;
    }

    return (int) DB::table('ingest_runs')->insertGetId(['source' => 'tcgplayer', 'feed' => 'tcgcsv', 'day' => '2026-10-01',
        'marker' => '2026-10-01 20:05:00+00', 'mode' => 'sync', 'status' => 'ok', 'started_at' => '2026-10-01 20:10:00+00',
        'finished_at' => '2026-10-01 20:11:00+00']);
}

/** @param array<string, string|array{0: string, 1: string, 2: string}> $days */
function t04uSeries(string $card, array $days): void
{
    $key = "{$card}:normal:-:-";
    DB::table('card_variants')->insertOrIgnore(['variant_key' => $key, 'card_id' => $card, 'finish' => 'normal', 'edition' => '-',
        'treatment' => '-', 'catalog_status' => 'unverified']);
    foreach ($days as $day => $spec) {
        [$close, $low, $mid] = is_array($spec) ? $spec : [$spec, null, null];
        DB::statement(<<<'SQL'
            INSERT INTO price_daily (variant_key, source, day, series_epoch, open, high, low, close, n_obs, is_filled, filled_from, as_of)
            VALUES (?, 'tcgplayer', ?::date, 1, ?, ?, ?, ?, 1, false, NULL, ?::date + interval '20 hours 5 minutes')
            SQL, [$key, $day, $close, $close, $close, $close, $day]);
        DB::statement(<<<'SQL'
            INSERT INTO price_points (variant_key, source, observed_at, day, series_epoch, source_product_id, source_subtype,
                                      market, low, mid, high, direct_low, ingest_run_id)
            VALUES (?, 'tcgplayer', ?::date + interval '20 hours 5 minutes', ?::date, 1, 1, 'Normal', ?::numeric,
                    coalesce(?::numeric, trunc(?::numeric * 0.90, 2)), coalesce(?::numeric, trunc(?::numeric * 1.05, 2)), NULL, NULL, ?)
            SQL, [$key, $day, $day, $close, $low, $close, $mid, $close, t04uRun()]);
    }
}

/** @return list<string> "card previous_close>close change_pct" of 2026-10-13 rises, by rank */
function t04uRises(): array
{
    return array_map(
        fn (object $r): string => "{$r->card_id} {$r->previous_close}>{$r->close} {$r->change_pct}",
        DB::select("SELECT card_id, previous_close::text AS previous_close, close::text AS close, change_pct::text AS change_pct
                    FROM price_movers WHERE day = '2026-10-13' AND direction = 'up' ORDER BY rank"),
    );
}

/** Five days ending 2026-10-13. */
function t04uWeek(string|array ...$closes): array
{
    return array_combine(['2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12', '2026-10-13'], $closes);
}

test('the upper listing bound is one and a half times the mid listing', function (): void {
    expect(PricingConfig::MOVERS_LISTING_MID_RATIO)->toBe('1.5');
});

test('the close of the day is ranked up to exactly 1.5 x its mid listing, not one cent over, and not between 1.5 x and 2 x', function (): void {
    // Mid 10.00: 15.00 is exactly 1.5 x, 15.01 one cent over, 18.00 between 1.5 x and 2 x.
    t04uSeries('zu1-at', t04uWeek('10.00', '10.10', '10.20', '10.30', ['15.00', '9.00', '10.00']));
    t04uSeries('zu1-over', t04uWeek('10.00', '10.10', '10.20', '10.30', ['15.01', '9.00', '10.00']));
    t04uSeries('zu1-between', t04uWeek('10.00', '10.10', '10.20', '10.30', ['18.00', '9.00', '10.00']));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(t04uRises())->toBe(['zu1-at 10.30>15.00 45.63'])
        ->and(DB::table('price_movers')->count())->toBe(1);
});

test('the previous close is held to 1.5 x its own mid listing too: at it, one cent over, and between 1.5 x and 2 x', function (): void {
    // 10.29 against a mid of 6.86 is exactly 1.5 x; 10.30 against 6.86 is one cent over; 10.30 against 6.00 is 1.72 x.
    t04uSeries('zu2-at', t04uWeek('10.00', '10.10', '10.20', ['10.29', '1.00', '6.86'], '11.40'));
    t04uSeries('zu2-over', t04uWeek('10.00', '10.10', '10.20', ['10.30', '1.00', '6.86'], '11.40'));
    t04uSeries('zu2-between', t04uWeek('10.00', '10.10', '10.20', ['10.30', '1.00', '6.00'], '11.40'));

    Movers::rebuild(DB::connection(), '2026-10-09', '2026-10-13');

    expect(t04uRises())->toBe(['zu2-at 10.29>11.40 10.79'])
        ->and(DB::table('price_movers')->count())->toBe(1);
});
