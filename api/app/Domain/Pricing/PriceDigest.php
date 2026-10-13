<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The determinism digest (plan P2-i1 D11, R180b/c). For each covered table it gives {rows, sha256}: the sha256 is
 * taken over the rows serialized in primary-key order (byte order, COLLATE "C") with an explicit column list, one
 * row per line, columns joined by `|`, NULL written as `\N`, instants in UTC with microseconds, dates as YYYY-MM-DD
 * and numerics in their own text form. Volatile bookkeeping (ingest_run_id) is left out; price_daily has no volatile
 * column (D3) and price_latest none (R177(6)). Covered tables: price_points, price_daily and price_latest (T05);
 * never price_events.
 *
 * The digest also records map_build_id (the newest map_builds.id) and the map's row count per series_epoch, so a map
 * change between two digests is visible: "identical" holds at a fixed (day set, map build, code sha).
 */
final class PriceDigest
{
    /** Table => [the serialized columns, the primary key]. */
    private const array TABLES = [
        'price_points' => [
            "variant_key, source, to_char(observed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),"
            ." to_char(day, 'YYYY-MM-DD'), series_epoch::text, source_product_id::text, source_subtype,"
            ." coalesce(market::text, '\\N'), coalesce(low::text, '\\N'), coalesce(mid::text, '\\N'),"
            ." coalesce(high::text, '\\N'), coalesce(direct_low::text, '\\N')",
            'variant_key COLLATE "C", source COLLATE "C", observed_at',
        ],
        'price_daily' => [
            "variant_key, source, to_char(day, 'YYYY-MM-DD'), series_epoch::text, open::text, high::text, low::text,"
            ." close::text, n_obs::text, CASE WHEN is_filled THEN 't' ELSE 'f' END,"
            ." coalesce(to_char(filled_from, 'YYYY-MM-DD'), '\\N'),"
            ." to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')",
            'variant_key COLLATE "C", source COLLATE "C", day',
        ],
        'price_latest' => [
            "variant_key, source, series_epoch::text, close::text, to_char(day, 'YYYY-MM-DD'),"
            ." to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"'),"
            ." coalesce(previous_close::text, '\\N'), coalesce(to_char(previous_day, 'YYYY-MM-DD'), '\\N'),"
            ." coalesce(change::text, '\\N'), coalesce(change_pct::text, '\\N')",
            'variant_key COLLATE "C", source COLLATE "C"',
        ],
    ];

    /**
     * @return array{map_build_id: int|null, map_epochs: array<int, int>, tables: array<string, array{rows: int, sha256: string}>}
     */
    public static function compute(ConnectionInterface $db): array
    {
        $tables = [];
        foreach (self::TABLES as $table => [$columns, $key]) {
            $row = $db->selectOne(
                "SELECT count(*) AS n, encode(sha256(convert_to(coalesce(string_agg(line, E'\\n' ORDER BY {$key}), ''), 'UTF8')), 'hex') AS sha256"
                ." FROM (SELECT {$key}, concat_ws('|', {$columns}) AS line FROM {$table}) serialized",
                [],
                false,
            );
            $tables[$table] = [
                'rows' => $row instanceof stdClass ? (int) $row->n : 0,
                'sha256' => $row instanceof stdClass ? (string) $row->sha256 : '',
            ];
        }

        $build = $db->selectOne('SELECT max(id) AS id FROM map_builds', [], false);
        $epochs = [];
        foreach ($db->select('SELECT series_epoch, count(*) AS n FROM source_product_map WHERE source = ? GROUP BY series_epoch ORDER BY series_epoch', [(string) config('pricing.source')], false) as $epoch) {
            if ($epoch instanceof stdClass) {
                $epochs[(int) $epoch->series_epoch] = (int) $epoch->n;
            }
        }

        return [
            'map_build_id' => $build instanceof stdClass && $build->id !== null ? (int) $build->id : null,
            'map_epochs' => $epochs,
            'tables' => $tables,
        ];
    }
}
