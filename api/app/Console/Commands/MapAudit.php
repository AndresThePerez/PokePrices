<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Mapping\MapAudit as Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

#[Description('Audit the PokéPrices product map: names, constraints, coverage, ambiguity or the priced-card share.')]
#[Signature('pokeprices:map:audit
    {--check= : names|constraints|coverage|ambiguity|priced}
    {--json : Print the artefact as JSON}
    {--out= : Also write the artefact to this file}
    {--connection= : The database connection holding the map (default: the default connection)}
    {--build= : The map build id (default: the newest build)}
    {--dataset= : The dataset checkout (default: services.dataset.root/<the build\'s dataset_ref>)}
    {--snapshots= : The snapshots root holding the build\'s collected day, read by priced (default: services.tcgcsv.snapshots)}')]
final class MapAudit extends Command
{
    public function handle(): int
    {
        $check = $this->stringOption('check');
        if (! isset(Audit::CHECKS[$check])) {
            $this->error('--check must be one of '.implode(', ', array_keys(Audit::CHECKS)));

            return self::FAILURE;
        }
        $buildOption = $this->stringOption('build');
        if ($buildOption !== '' && preg_match('/\A[1-9]\d*\z/', $buildOption) !== 1) {
            $this->error('--build must be a positive integer');

            return self::FAILURE;
        }
        $name = $this->stringOption('connection');

        try {
            $audit = new Audit(DB::connection($name === '' ? null : $name));
            $build = $audit->build($buildOption === '' ? null : (int) $buildOption);
            $dataset = $this->stringOption('dataset');
            if ($dataset === '') {
                $root = config('services.dataset.root');
                $dataset = (is_string($root) ? rtrim($root, '/') : '').'/'.$build['dataset_ref'];
            }
            $snapshots = $this->stringOption('snapshots');
            if ($snapshots === '') {
                $configured = config('services.tcgcsv.snapshots');
                $snapshots = is_string($configured) ? $configured : '';
            }
            $artefact = $audit->run($check, $build, $dataset, database_path('data/set_aliases.csv'), $snapshots);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error('map audit refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($artefact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $out = $this->stringOption('out');
        if ($out !== '' && file_put_contents($out, $json."\n") === false) {
            $this->error("could not write {$out}");

            return self::FAILURE;
        }
        $this->line($this->option('json') === true ? $json : sprintf('%s %s: value %d / denominator %d', $artefact['id'], $check, $artefact['value'], $artefact['denominator']));

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
