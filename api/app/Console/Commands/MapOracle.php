<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Mapping\MapAudit;
use App\Domain\Mapping\OracleComparison;
use App\Domain\Mapping\SnapshotReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

#[Description('Judge the PokéPrices product map per set against the recorded price oracle (DC2 artefact); reads only.')]
#[Signature('pokeprices:map:oracle
    {--day= : The collected day; must be the map build\'s snapshot day (default: that day)}
    {--oracle-dir= : The recorded oracle (default: services.oracle.root/<day>)}
    {--snapshots= : The snapshots root holding <day>/ (default: services.tcgcsv.snapshots)}
    {--golden= : The golden file (default: database/data/golden-set.json; absent covers no blind class)}
    {--build= : The map build id (default: the newest build)}
    {--connection= : The database connection holding the map (default: the default connection)}
    {--json : Print the artefact as JSON}
    {--out= : Also write the artefact to this file}')]
final class MapOracle extends Command
{
    public function handle(): int
    {
        $buildOption = $this->stringOption('build');
        if ($buildOption !== '' && preg_match('/\A[1-9]\d*\z/', $buildOption) !== 1) {
            $this->error('--build must be a positive integer');

            return self::FAILURE;
        }
        $name = $this->stringOption('connection');

        try {
            $db = DB::connection($name === '' ? null : $name);
            $build = (new MapAudit($db))->build($buildOption === '' ? null : (int) $buildOption);
            $day = $this->stringOption('day');
            if ($day !== '' && $day !== $build['snapshot_day']) {
                throw new RuntimeException("map build {$build['id']} was built from {$build['snapshot_day']}, not {$day}; compare the day the map was built from");
            }
            $day = $build['snapshot_day'];
            $snapshots = $this->stringOption('snapshots');
            if ($snapshots === '') {
                $configured = config('services.tcgcsv.snapshots');
                $snapshots = is_string($configured) ? $configured : '';
            }
            $oracleDir = $this->stringOption('oracle-dir');
            if ($oracleDir === '') {
                $root = config('services.oracle.root');
                $oracleDir = (is_string($root) ? rtrim($root, '/') : '').'/'.$day;
            }
            $golden = $this->stringOption('golden');
            $artefact = (new OracleComparison($db))->compare(
                $build,
                SnapshotReader::open($snapshots, $day),
                $oracleDir,
                $golden === '' ? database_path('data/golden-set.json') : $golden,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error('oracle comparison refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($artefact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $out = $this->stringOption('out');
        if ($out !== '' && file_put_contents($out, $json."\n") === false) {
            $this->error("could not write {$out}");

            return self::FAILURE;
        }
        $this->line($this->option('json') === true ? $json : sprintf(
            'DC2: %d sets disagree; %d of %d compared variants disagree (%s%%); %d excluded stale; %d blind classes uncovered',
            $artefact['value'], (int) array_sum(array_column($artefact['per_set'], 'disagree')), $artefact['denominator'],
            $artefact['global_disagreement_pct'], $artefact['excluded_stale'], $artefact['blind_classes_uncovered'],
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
