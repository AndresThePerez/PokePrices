<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\VerifyCatalog;
use App\Domain\Mapping\MapBuilder;
use App\Domain\Mapping\SnapshotReader;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

#[Description('Build the PokéPrices product map from one collected TCGCSV day; the snapshot is only read.')]
#[Signature('pokeprices:map:build
    {--day= : The collected day, YYYY-MM-DD}
    {--dataset= : The dataset checkout holding cards/en/*.json and sets/en.json}
    {--connection= : The database connection to write to (default: the default connection)}
    {--snapshots= : The snapshots root holding <day>/ (default: services.tcgcsv.snapshots)}')]
final class MapBuild extends Command
{
    public function handle(): int
    {
        $day = $this->stringOption('day');
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day) !== 1 || CarbonImmutable::createFromFormat('!Y-m-d', $day)?->format('Y-m-d') !== $day) {
            $this->error('--day must be a date in the form YYYY-MM-DD');

            return self::FAILURE;
        }
        $dataset = $this->stringOption('dataset');
        if ($dataset === '' || ! is_dir($dataset)) {
            $this->error("--dataset {$dataset} is not a directory");

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

        try {
            $snapshot = SnapshotReader::open($snapshots, $day);
            $result = (new MapBuilder($db))->build($snapshot, $day, $dataset);
        } catch (RuntimeException $e) {
            $this->error('map build refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(['map_build_id' => $result['build_id']] + $result['counts'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // T45: check the built rows against the PokéSearch catalog, on the connection the build wrote to.
        VerifyCatalog::dispatch($name === '' ? null : $name);

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
