<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\IngestLock;
use App\Domain\Pricing\MovementRule;
use App\Domain\Pricing\MovementVolume;
use App\Domain\Pricing\PricingConfig;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Measures the PokéPrices movement volume (plan P2-i1 T13 items 5 and 6): rewrites movement_volume for every day
 * price_daily holds, from price_daily only (never from price_events), under the `pricing:ingest` lock (D12), and
 * prints a JSON summary. --markdown writes the measurement document: the days, the map build and its epochs, one
 * table per rule, the per-rule mean crossings per day and the comparison with the planner's raw pre-measure.
 *
 * A relative --markdown path is taken from the working directory, as any shell path is: run from the repository
 * root (`exec -w /workspace api php api/artisan ...`), `--markdown docs/measurements/movement-volume.md` writes the
 * committed file. Nothing here reaches above base_path() on its own (R160).
 */
#[Description('Measure how many PokéPrices daily closes cross each movement rule per collected day, from price_daily, into movement_volume.')]
#[Signature('pokeprices:prices:movement-volume
    {--markdown= : Also write the measurement as Markdown to this path (relative to the working directory)}')]
final class PricesMovementVolume extends Command
{
    /** The planner's raw pre-measure (all price rows, draft rule, day over day), quoted in the document. */
    private const string PRE_MEASURE = '260, 225 and 216';

    public function handle(): int
    {
        $db = DB::connection();
        if (! IngestLock::tryAcquire($db)) {
            $this->error(IngestLock::HELD_MESSAGE);

            return self::FAILURE;
        }
        try {
            $range = $db->transaction(fn (): ?array => MovementVolume::rebuildAll($db));
        } finally {
            IngestLock::release($db);
        }
        if ($range === null) {
            $this->error('price_daily holds no real close to measure');

            return self::FAILURE;
        }

        $rows = MovementVolume::rows($db);
        $summary = $this->summary($rows);
        $this->line((string) json_encode([
            'days' => array_column(reset($rows) ?: [], 'day'),
            'rules' => $summary,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $path = $this->option('markdown');
        if (is_string($path) && $path !== '') {
            $target = str_starts_with($path, '/') ? $path : getcwd().'/'.$path;
            if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0775, true)) {
                $this->error("cannot create the directory of {$target}");

                return self::FAILURE;
            }
            if (file_put_contents($target, $this->markdown($db, $rows, $summary)) === false) {
                $this->error("cannot write {$target}");

                return self::FAILURE;
            }
            $this->line("wrote {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * Per rule: thresholds, and the mean, min and max of up + down over the days that have at least one comparison.
     *
     * @param  array<string, list<array{day: string, compared: int, up: int, down: int, epoch_breaks: int, filled_skipped: int, extreme: int, sub_2_previous: int}>>  $rows
     * @return array<string, array{pct: string, abs: string, days: int, total: int, mean: string, min: int, max: int}>
     */
    private function summary(array $rows): array
    {
        $out = [];
        foreach (MovementVolume::rules() as $rule => [$pct, $abs]) {
            $moves = array_map(fn (array $r): int => $r['up'] + $r['down'], array_values(array_filter($rows[$rule], fn (array $r): bool => $r['compared'] > 0)));
            $n = count($moves);
            $total = array_sum($moves);
            // Two decimals, half up, in integers: no float on the way.
            $hundredths = $n === 0 ? 0 : intdiv($total * 200 + $n, 2 * $n);
            $out[$rule] = [
                'pct' => $pct,
                'abs' => $abs,
                'days' => $n,
                'total' => $total,
                'mean' => intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT),
                'min' => $n === 0 ? 0 : min($moves),
                'max' => $n === 0 ? 0 : max($moves),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, list<array{day: string, compared: int, up: int, down: int, epoch_breaks: int, filled_skipped: int, extreme: int, sub_2_previous: int}>>  $rows
     * @param  array<string, array{pct: string, abs: string, days: int, total: int, mean: string, min: int, max: int}>  $summary
     */
    private function markdown(ConnectionInterface $db, array $rows, array $summary): string
    {
        $source = PricingConfig::source();
        $draft = MovementRule::rule();
        $days = array_column($rows[$draft], 'day');
        $build = $db->table('map_builds')->max('id');
        $epochs = [];
        foreach ($db->select('SELECT series_epoch, count(*) AS n FROM source_product_map WHERE source = ? GROUP BY series_epoch ORDER BY series_epoch', [$source], false) as $r) {
            if ($r instanceof stdClass) {
                $epochs[] = 'series_epoch '.(int) $r->series_epoch.': '.number_format((int) $r->n).' rows';
            }
        }
        $transitions = array_values(array_filter($rows[$draft], fn (array $r): bool => $r['compared'] > 0));

        $lines = [
            '# Measured movement volume',
            '',
            'Generated by `pokeprices:prices:movement-volume` from `price_daily` (never from `price_events`), so the figures',
            'do not depend on the mode or order in which the days were ingested. Each count is a number of variants per',
            'daily close; a variant is compared when it has a previous close (the most recent real close in the same',
            '`series_epoch`, at most '.config('pricing.fill_cap_days').' days earlier). Filled rows never count. `extreme` is a ratio above 5 or below 0.2 with a change of at least $5.00;',
            '`sub_2_previous` counts the rule\'s crossings whose previous close is under $2.00.',
            '',
            '- Days measured: '.implode(', ', $days).' ('.count($days).' days, '.count($transitions).' with a previous close)',
            '- Map build: '.(is_numeric($build) ? (int) $build : 'none').'; '.($epochs === [] ? 'no map rows' : implode(', ', $epochs)),
            "- Source: {$source}, feed ".PricingConfig::feed().', daily closes',
            '',
            '## Summary',
            '',
            'Mean crossings per day over the days with a previous close, with the smallest and largest day.',
            '',
            '| rule | pct | abs | mean per day | min | max |',
            '|---|---|---|---|---|---|',
        ];
        foreach ($summary as $rule => $s) {
            $lines[] = "| {$rule} | {$s['pct']} | {$s['abs']} | {$s['mean']} | {$s['min']} | {$s['max']} |";
        }
        $lines[] = '';
        $lines[] = 'The planner\'s raw pre-measure counted '.self::PRE_MEASURE.' draft-rule crossings for the three transitions'
            .' of 2026-10-10, 2026-10-11 and 2026-10-12 over all price rows, mapped or not; measured on mapped variants'
            .' with the D4 previous close, '.$draft.' gives '
            .implode(', ', array_map(fn (array $r): string => $r['day'].': '.($r['up'] + $r['down']), $transitions)).'.';

        foreach ($summary as $rule => $s) {
            $lines[] = '';
            $lines[] = "## {$rule} ({$s['pct']} %, \${$s['abs']})";
            $lines[] = '';
            $lines[] = '| day | compared | up | down | epoch_breaks | extreme |';
            $lines[] = '|---|---|---|---|---|---|';
            foreach ($rows[$rule] as $r) {
                $lines[] = "| {$r['day']} | {$r['compared']} | {$r['up']} | {$r['down']} | {$r['epoch_breaks']} | {$r['extreme']} |";
            }
            $lines[] = '';
            $filled = array_sum(array_column($rows[$rule], 'filled_skipped'));
            $sub = array_sum(array_column($rows[$rule], 'sub_2_previous'));
            $lines[] = "Mean {$s['mean']} crossings per day over {$s['days']} days (range {$s['min']} to {$s['max']}); filled rows skipped: {$filled}; crossings from a previous close under \$2.00: {$sub}.";
        }

        return implode("\n", $lines)."\n";
    }
}
