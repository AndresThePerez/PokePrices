<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Replay;
use App\Support\IsoDay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Description('Wipe the PokéPrices price tables and re-ingest every collected TCGCSV day three times, proving the result identical; the snapshots are only read.')]
#[Signature('pokeprices:prices:replay
    {--days=all : all, or a comma-separated list of collected days YYYY-MM-DD}
    {--snapshots= : The snapshots root holding <day>/ (default: services.tcgcsv.snapshots)}
    {--connection= : The database connection to write to (default: the default connection)}
    {--order=asc : The ingest order of every pass: asc or desc}
    {--code-sha= : The commit the code was checked out at, recorded in the output}
    {--fresh : Required: truncate the derived price tables before passes 1 and 2}')]
final class PricesReplay extends Command
{
    public function handle(): int
    {
        $order = $this->stringOption('order');
        if (! in_array($order, ['asc', 'desc'], true)) {
            $this->error('--order must be asc or desc');

            return self::FAILURE;
        }
        $codeSha = $this->stringOption('code-sha');
        if ($codeSha !== '' && preg_match('/\A[0-9a-f]{7,64}\z/', $codeSha) !== 1) {
            $this->error('--code-sha must be a hexadecimal commit id (7 to 64 lowercase hex digits)');

            return self::FAILURE;
        }
        $days = $this->days();
        if (is_string($days)) {
            $this->error($days);

            return self::FAILURE;
        }
        if ($this->option('fresh') !== true) {
            $this->error('replay needs --fresh: it truncates '.implode(', ', Replay::WIPED_TABLES).' and re-ingests every pinned day');

            return self::FAILURE;
        }

        $snapshots = $this->stringOption('snapshots');
        if ($snapshots === '') {
            $configured = config('services.tcgcsv.snapshots');
            $snapshots = is_string($configured) ? $configured : '';
        }
        $name = $this->stringOption('connection');
        try {
            $db = DB::connection($name === '' ? null : $name);
        } catch (InvalidArgumentException $e) {
            $this->error("--connection {$name} is not a configured connection: {$e->getMessage()}");

            return self::FAILURE;
        }

        $result = (new Replay($db, $snapshots))->run($days, $order);
        if ($result['status'] !== 'ok') {
            $this->error("replay {$result['status']}: {$result['error']}");

            return self::FAILURE;
        }

        $this->line(json_encode([
            'days' => $result['days'],
            'day_count' => count($result['days']),
            'order' => $order,
            'map_build_id' => $result['map_build_id'],
            'code_sha' => $codeSha === '' ? null : $codeSha,
            'passes' => $result['passes'],
            'identical' => $result['identical'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $result['identical'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The --days option: null for all, else the named days; a string says why it is malformed.
     *
     * @return list<string>|string|null
     */
    private function days(): array|string|null
    {
        $raw = $this->option('days');
        $value = is_string($raw) ? trim($raw) : '';
        if ($raw === null || $value === 'all') {
            return null;
        }
        $days = [];
        foreach (array_filter(array_map(trim(...), explode(',', $value)), fn (string $d): bool => $d !== '') as $day) {
            if (! IsoDay::isDay($day)) {
                return "--days: {$day} is not a day in the form YYYY-MM-DD";
            }
            $days[] = $day;
        }

        return $days;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
