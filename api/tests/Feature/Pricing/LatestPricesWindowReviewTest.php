<?php

declare(strict_types=1);

use App\Domain\Pricing\LatestPrices;
use Illuminate\Support\Facades\DB;

// Reviewer edge cases for the windowed price_latest rebuild (plan Q1-i3 T04, finding B10). They work on price_daily
// directly, so every boundary of the set S is placed by hand, and they prove the windowed rebuild against the
// whole-table SELECT LatestPrices ran before T04 (copied verbatim as the oracle). The rollup only ever writes
// price_daily inside its window [from, to], so each mutation below stays inside the window it then rebuilds.

const Q1T04R_ORACLE = <<<'SQL'
    SELECT l.variant_key, l.source, l.series_epoch, l.close, l.day, l.as_of,
           p.close, p.day, l.close - p.close,
           CASE WHEN p.close IS NULL OR p.close = 0 THEN NULL ELSE round((l.close - p.close) / p.close * 100, 2) END
    FROM (
        SELECT DISTINCT ON (d.variant_key, d.source) d.variant_key, d.source, d.series_epoch, d.close, d.day, d.as_of
        FROM price_daily d
        WHERE d.source = ? AND NOT d.is_filled
        ORDER BY d.variant_key, d.source, d.day DESC
    ) l
    LEFT JOIN LATERAL (
        SELECT q.close, q.day
        FROM price_daily q
        WHERE q.variant_key = l.variant_key AND q.source = l.source AND NOT q.is_filled
          AND q.series_epoch = l.series_epoch AND q.day < l.day AND q.day >= l.day - ?::integer
        ORDER BY q.day DESC
        LIMIT 1
    ) p ON true
    SQL;

function q1t04rVariant(int $n): string
{
    $key = "rev-{$n}:holo:unlimited:-";
    if (! DB::table('card_variants')->where('variant_key', $key)->exists()) {
        DB::table('card_variants')->insert(['variant_key' => $key, 'card_id' => "rev-{$n}", 'finish' => 'holo', 'edition' => 'unlimited']);
    }

    return $key;
}

/** Writes (or replaces) the price_daily row of $key on $day: real with $close and $epoch, or filled from the day before. */
function q1t04rPut(string $key, string $day, ?string $close, int $epoch = 1, string $hour = '20:05:45'): void
{
    DB::table('price_daily')->where('variant_key', $key)->where('source', 'tcgplayer')->where('day', $day)->delete();
    $filled = $close === null;
    $c = $close ?? '1.00';
    DB::table('price_daily')->insert([
        'variant_key' => $key, 'source' => 'tcgplayer', 'day' => $day, 'series_epoch' => $epoch,
        'open' => $c, 'high' => $c, 'low' => $c, 'close' => $c, 'n_obs' => $filled ? 0 : 1, 'is_filled' => $filled,
        'filled_from' => $filled ? date('Y-m-d', strtotime($day.' -1 day')) : null, 'as_of' => "{$day} {$hour}+00",
    ]);
}

function q1t04rDrop(string $key, string $day): void
{
    DB::table('price_daily')->where('variant_key', $key)->where('source', 'tcgplayer')->where('day', $day)->delete();
}

/** price_latest as variant_key => row as text. */
function q1t04rLatest(): array
{
    $out = [];
    foreach (DB::select(<<<'SQL'
        SELECT variant_key,
               ROW(variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct)::text AS r
        FROM price_latest ORDER BY variant_key COLLATE "C"
        SQL) as $row) {
        $out[$row->variant_key] = $row->r;
    }

    return $out;
}

function q1t04rOracle(): array
{
    $out = [];
    foreach (DB::select(
        'SELECT o.variant_key, ROW(o.variant_key, o.source, o.series_epoch, o.close, o.day, o.as_of, o.previous_close, o.previous_day, o.change, o.change_pct)::text AS r
         FROM ('.Q1T04R_ORACLE.') AS o(variant_key, source, series_epoch, close, day, as_of, previous_close, previous_day, change, change_pct)
         ORDER BY o.variant_key COLLATE "C"',
        ['tcgplayer', (int) config('pricing.fill_cap_days')],
    ) as $row) {
        $out[$row->variant_key] = $row->r;
    }

    return $out;
}

function q1t04rXmins(): array
{
    return array_column(DB::select('SELECT variant_key, xmin::text AS x FROM price_latest ORDER BY variant_key COLLATE "C"'), 'x', 'variant_key');
}

function q1t04rDay(int $offset): string
{
    return date('Y-m-d', strtotime('2026-09-01 +'.$offset.' days'));
}

test('random window-bounded edits to price_daily: the windowed rebuild always equals the whole-table oracle', function (int $seed): void {
    mt_srand($seed);
    $cap = (int) config('pricing.fill_cap_days');
    $keys = array_map('q1t04rVariant', range(1, 14));
    $closes = ['0.00', '1.00', '1.00', '2.50', '2.50', '10.00'];

    // The starting state: sparse real closes (some epoch 2) and a few filled rows over 50 days, then the whole rebuild.
    foreach ($keys as $key) {
        foreach (range(0, 49) as $i) {
            $roll = mt_rand(0, 99);
            if ($roll < 30) {
                q1t04rPut($key, q1t04rDay($i), $closes[mt_rand(0, 5)], mt_rand(0, 4) === 0 ? 2 : 1);
            } elseif ($roll < 40) {
                q1t04rPut($key, q1t04rDay($i), null);
            }
        }
    }
    LatestPrices::rebuild(DB::connection());
    expect(q1t04rLatest())->toBe(q1t04rOracle());

    $steps = 0;
    foreach (range(1, 30) as $step) {
        $from = mt_rand(0, 40);
        $to = min(49, $from + mt_rand(0, $cap));
        foreach ($keys as $key) {
            foreach (range($from, $to) as $i) {
                $roll = mt_rand(0, 99);
                if ($roll < 20) {
                    q1t04rPut($key, q1t04rDay($i), $closes[mt_rand(0, 5)], mt_rand(0, 4) === 0 ? 2 : 1, sprintf('%02d:00:00', mt_rand(0, 23)));
                } elseif ($roll < 30) {
                    q1t04rDrop($key, q1t04rDay($i));
                } elseif ($roll < 35) {
                    q1t04rPut($key, q1t04rDay($i), null);
                }
            }
        }
        LatestPrices::rebuild(DB::connection(), q1t04rDay($from), q1t04rDay($to));
        expect(q1t04rLatest())->toBe(q1t04rOracle(), "seed {$seed}, step {$step}, window ".q1t04rDay($from).' .. '.q1t04rDay($to));
        $steps++;
    }

    expect($steps)->toBe(30)->and(count(q1t04rLatest()))->toBeGreaterThan(0);
})->with(range(1, 12));

test('a newest close exactly at to + cap follows an edit of its previous close in the window, one day later is left alone', function (): void {
    $cap = (int) config('pricing.fill_cap_days');
    [$edge, $beyond, $quiet] = [q1t04rVariant(1), q1t04rVariant(2), q1t04rVariant(3)];
    $from = 40;
    $to = 42;
    // edge: newest close at to + cap, its previous close in the window exactly cap days before it.
    q1t04rPut($edge, q1t04rDay($to), '10.00');
    q1t04rPut($edge, q1t04rDay($to + $cap), '12.00');
    // beyond: newest close at to + cap + 1 and an older one in the window, which is out of the 14-day reach.
    q1t04rPut($beyond, q1t04rDay($to), '10.00');
    q1t04rPut($beyond, q1t04rDay($to + $cap + 1), '12.00');
    // quiet: a real close on from - 1 and only filled rows in the window.
    q1t04rPut($quiet, q1t04rDay($from - 1), '5.00');
    foreach (range($from, $to) as $i) {
        q1t04rPut($quiet, q1t04rDay($i), null);
    }
    LatestPrices::rebuild(DB::connection());
    expect(q1t04rLatest())->toBe(q1t04rOracle())
        ->and(DB::table('price_latest')->where('variant_key', $edge)->value('previous_day'))->toBe(q1t04rDay($to))
        ->and(DB::table('price_latest')->where('variant_key', $beyond)->value('previous_day'))->toBeNull();
    $xmin = q1t04rXmins();

    // The ingest re-collects the window: edge's close at 42 changes, beyond's candle vanishes, quiet gets other fills.
    q1t04rPut($edge, q1t04rDay($to), '8.00');
    q1t04rDrop($beyond, q1t04rDay($to));
    q1t04rPut($quiet, q1t04rDay($from), null);
    LatestPrices::rebuild(DB::connection(), q1t04rDay($from), q1t04rDay($to));

    $after = q1t04rXmins();
    expect(q1t04rLatest())->toBe(q1t04rOracle())
        ->and(DB::table('price_latest')->where('variant_key', $edge)->value('previous_close'))->toBe('8.00')
        ->and($after[$beyond])->toBe($xmin[$beyond])
        ->and($after[$quiet])->toBe($xmin[$quiet]);
});

test('a variant that lost every real row in the window loses its price_latest row and the others are untouched', function (): void {
    [$gone, $older, $other] = [q1t04rVariant(1), q1t04rVariant(2), q1t04rVariant(3)];
    // gone: the only real closes sit in the window; filled rows remain afterwards, which are no close.
    q1t04rPut($gone, q1t04rDay(41), '4.00');
    q1t04rPut($gone, q1t04rDay(42), '5.00');
    // older: an older real close before the window and one in the window; losing the window one falls back.
    q1t04rPut($older, q1t04rDay(30), '3.00');
    q1t04rPut($older, q1t04rDay(42), '6.00');
    q1t04rPut($other, q1t04rDay(20), '7.00');
    LatestPrices::rebuild(DB::connection());
    $xmin = q1t04rXmins();

    q1t04rPut($gone, q1t04rDay(41), null);
    q1t04rPut($gone, q1t04rDay(42), null);
    q1t04rDrop($older, q1t04rDay(42));
    LatestPrices::rebuild(DB::connection(), q1t04rDay(40), q1t04rDay(43));

    expect(q1t04rLatest())->toBe(q1t04rOracle())
        ->and(DB::table('price_latest')->where('variant_key', $gone)->exists())->toBeFalse()
        ->and(DB::table('price_latest')->where('variant_key', $older)->value('close'))->toBe('3.00')
        ->and(q1t04rXmins()[$other])->toBe($xmin[$other]);
});

test('an epoch break inside the window never takes a previous close across series_epoch', function (): void {
    $key = q1t04rVariant(1);
    q1t04rPut($key, q1t04rDay(30), '10.00', 1);
    q1t04rPut($key, q1t04rDay(35), '20.00', 1);
    LatestPrices::rebuild(DB::connection());
    expect(DB::table('price_latest')->where('variant_key', $key)->value('previous_close'))->toBe('10.00');

    // The newest row (in the window) moves to epoch 2: the epoch 1 close of day 30 is no previous close any more.
    q1t04rPut($key, q1t04rDay(35), '20.00', 2);
    LatestPrices::rebuild(DB::connection(), q1t04rDay(34), q1t04rDay(36));

    expect(q1t04rLatest())->toBe(q1t04rOracle())
        ->and(DB::table('price_latest')->where('variant_key', $key)->first(['series_epoch', 'previous_close', 'change_pct']))
        ->toEqual((object) ['series_epoch' => 2, 'previous_close' => null, 'change_pct' => null]);
});

test('a window with from and to given but a table that was empty builds every row it can see', function (): void {
    $key = q1t04rVariant(1);
    q1t04rPut($key, q1t04rDay(10), '2.00');
    q1t04rPut($key, q1t04rDay(11), '3.00');
    expect(DB::table('price_latest')->count())->toBe(0);

    LatestPrices::rebuild(DB::connection(), q1t04rDay(11), q1t04rDay(11));

    expect(q1t04rLatest())->toBe(q1t04rOracle())
        ->and(DB::table('price_latest')->where('variant_key', $key)->value('previous_close'))->toBe('2.00');
});
