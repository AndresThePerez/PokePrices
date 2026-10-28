<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Mapping\Models\CardVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * The daily candles of a card's variants over a chart range (plan P2-i1 T11, D3, review F9; System Design §4:
 * price_daily serves every chart range). The window is anchored on the data, never the clock: `to` and `as_of` are
 * the day and marker of the newest `ok` ingest run of the source, read once. `from` is `to` minus 6, 29, 89 or 364
 * days for 1W, 1M, 3M and 1Y, and January 1 of `to`'s year for YTD. There is no 1D range.
 *
 * Three queries whatever the number of rows: the newest ok run, the card's variants, and their price_daily rows in
 * [from, to]. With no ok run, from, to and as_of are null and every series is empty (no third query); a card without
 * variants gives no series. Missing days stay gaps; each point carries its own row's as_of (for a filled point, the
 * marker of the real close it repeats), is_filled, filled_from and series_epoch. Money stays a decimal string.
 *
 * Each series also carries history_begins (plan P3-i1 T04, D5): the day of the variant's earliest real close
 * (is_filled false) for the source, in any series_epoch, over all days, so it does not depend on the range. It rides
 * the variants query as a correlated min(day) on the price_daily primary key prefix (no fourth query), and is null
 * without a real close and on every series while there is no ok run, whatever the subquery returned (F-P3-03).
 */
final class PriceHistory
{
    /** range => days before `to` where the window starts; null: January 1 of `to`'s year. */
    public const array RANGES = ['1W' => 6, '1M' => 29, '3M' => 89, 'YTD' => null, '1Y' => 364];

    public const string DEFAULT_RANGE = '1M';

    /**
     * @return array{series: list<array{variant_key: string, finish: string, edition: string, treatment: string, history_begins: string|null, points: list<array{day: string, close: string, is_filled: bool, filled_from: string|null, as_of: string, series_epoch: int}>}>, from: string|null, to: string|null, as_of: string|null}
     */
    public static function forCard(string $cardId, string $range): array
    {
        $source = PricingConfig::source();

        $newest = DB::selectOne(<<<'SQL'
            SELECT to_char(day, 'YYYY-MM-DD') AS day,
                   to_char(marker AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of
            FROM ingest_runs
            WHERE source = ? AND status = 'ok' AND marker IS NOT NULL
            ORDER BY marker DESC, id DESC
            LIMIT 1
            SQL, [$source]);
        $to = $newest instanceof stdClass ? (string) $newest->day : null;
        $asOf = $newest instanceof stdClass ? (string) $newest->as_of : null;
        $from = $to === null ? null : self::from($to, $range);

        $variants = CardVariant::query()->where('card_id', $cardId)->orderBy('variant_key')
            ->select(['variant_key', 'finish', 'edition', 'treatment'])
            ->selectSub(fn (Builder $first): Builder => $first->from('price_daily')
                ->selectRaw("to_char(min(price_daily.day), 'YYYY-MM-DD')")
                ->whereColumn('price_daily.variant_key', 'card_variants.variant_key')
                ->where('price_daily.source', $source)
                ->where('price_daily.is_filled', false), 'history_begins')
            ->get();

        /** @var array<string, list<array{day: string, close: string, is_filled: bool, filled_from: string|null, as_of: string, series_epoch: int}>> $points */
        $points = [];
        if ($from !== null && $to !== null && $variants->isNotEmpty()) {
            $rows = DB::table('price_daily')
                ->where('source', $source)
                ->whereIn('variant_key', $variants->pluck('variant_key')->all())
                ->whereBetween('day', [$from, $to])
                ->orderBy('variant_key')
                ->orderBy('day')
                ->selectRaw(<<<'SQL'
                    variant_key, to_char(day, 'YYYY-MM-DD') AS day, close::text AS close, is_filled,
                    to_char(filled_from, 'YYYY-MM-DD') AS filled_from,
                    to_char(as_of AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS as_of, series_epoch
                    SQL)
                ->get();
            foreach ($rows as $row) {
                /** @var stdClass $row */
                $points[(string) $row->variant_key][] = [
                    'day' => (string) $row->day,
                    'close' => (string) $row->close,
                    'is_filled' => (bool) $row->is_filled,
                    'filled_from' => $row->filled_from === null ? null : (string) $row->filled_from,
                    'as_of' => (string) $row->as_of,
                    'series_epoch' => (int) $row->series_epoch,
                ];
            }
        }

        $series = [];
        foreach ($variants as $variant) {
            $begins = $variant->getAttribute('history_begins');
            $series[] = [
                'variant_key' => $variant->variant_key,
                'finish' => $variant->finish,
                'edition' => $variant->edition,
                'treatment' => $variant->treatment,
                // Nothing ingested yet: null on every series, whatever rows a superseded run left behind.
                'history_begins' => $to !== null && is_string($begins) ? $begins : null,
                'points' => $points[$variant->variant_key] ?? [],
            ];
        }

        return ['series' => $series, 'from' => $from, 'to' => $to, 'as_of' => $asOf];
    }

    /** The first day of the range ending on $to (YYYY-MM-DD). */
    public static function from(string $to, string $range): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');
        if ($day === null || ! array_key_exists($range, self::RANGES)) {
            throw new InvalidArgumentException("history needs a YYYY-MM-DD day and a known range, got {$to} and {$range}");
        }
        $back = self::RANGES[$range];

        return ($back === null ? $day->startOfYear() : $day->subDays($back))->format('Y-m-d');
    }
}
