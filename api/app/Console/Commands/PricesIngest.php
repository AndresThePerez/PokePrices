<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\DayIngest;
use App\Domain\Pricing\PriceDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Description('Ingest one collected TCGCSV day into the PokéPrices price tables; the snapshot is only read.')]
#[Signature('pokeprices:prices:ingest
    {--day= : The collected day, YYYY-MM-DD}
    {--sync : Run start, every chunk and finalize in this process, in one transaction}
    {--snapshots= : The snapshots root holding <day>/ (default: services.tcgcsv.snapshots)}
    {--connection= : The database connection to write to (default: the default connection)}
    {--force : Re-ingest a marker that already has an ok run (idempotent)}
    {--digest : Add the determinism digest of the price tables to the JSON line}')]
final class PricesIngest extends Command
{
    public function handle(): int
    {
        $day = $this->stringOption('day');
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) !== 1 || CarbonImmutable::createFromFormat('!Y-m-d', $day)?->format('Y-m-d') !== $day) {
            $this->error('--day must be a date in the form YYYY-MM-DD');

            return self::FAILURE;
        }
        if ($this->option('sync') !== true) {
            $this->error('choose the mode: --sync');

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

        $result = (new DayIngest($db, $snapshots))->sync($day, $this->option('force') === true);
        $marker = $result['marker']?->utc()->format('Y-m-d\TH:i:s\Z');

        if ($result['status'] === 'locked') {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }
        if ($result['status'] === 'refused' || $result['status'] === 'failed') {
            $this->error("ingest {$result['status']} (run {$result['run_id']}): {$result['error']}");

            return self::FAILURE;
        }

        $line = ['run_id' => $result['run_id'], 'day' => $day, 'marker' => $marker];
        if ($result['status'] === 'skipped_unchanged') {
            $line['status'] = 'skipped_unchanged';
        } else {
            foreach (['rows', 'mapped', 'unmapped', 'null_market', 'upserted', 'unchanged', 'daily_rows'] as $key) {
                $line[$key] = $result['counts'][$key] ?? 0;
            }
        }
        if ($this->option('digest') === true) {
            $line['digest'] = PriceDigest::compute($db);
        }
        $this->line(json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
