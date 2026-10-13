<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Mapping\GoldenSet;
use App\Domain\Mapping\MapAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

#[Description('Judge the PokéPrices product map against the golden set Andres signs at HG-18 (DC4 artefact); reads only.')]
#[Signature('pokeprices:map:golden
    {--file= : The golden file, relative to the working directory (default: database/data/golden-set.json)}
    {--pushed-ref= : The ref recording the last push (default: refs/remotes/origin/main)}
    {--build= : The map build id named in inputs (default: the newest build)}
    {--connection= : The database connection holding the map (default: the default connection)}
    {--json : Print the artefact as JSON}
    {--out= : Also write the artefact to this file}')]
final class MapGolden extends Command
{
    public function handle(): int
    {
        $buildOption = $this->stringOption('build');
        if ($buildOption !== '' && preg_match('/\A[1-9]\d*\z/', $buildOption) !== 1) {
            $this->error('--build must be a positive integer');

            return self::FAILURE;
        }
        $name = $this->stringOption('connection');
        $file = $this->stringOption('file');
        $pushedRef = $this->stringOption('pushed-ref');

        try {
            $db = DB::connection($name === '' ? null : $name);
            $build = (new MapAudit($db))->build($buildOption === '' ? null : (int) $buildOption);
            $artefact = (new GoldenSet($db))->evaluate(
                $build,
                $file === '' ? database_path('data/golden-set.json') : $file,
                $pushedRef === '' ? GoldenSet::PUSHED_REF : $pushedRef,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error('golden set refused: '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($artefact, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $out = $this->stringOption('out');
        if ($out !== '' && file_put_contents($out, $json."\n") === false) {
            $this->error("could not write {$out}");

            return self::FAILURE;
        }
        $this->line($this->option('json') === true ? $json : sprintf(
            'DC4: value %d over %d golden rows; approval_ratified %d; classes_missing %d; mapped_cards_regression %d',
            $artefact['value'], $artefact['denominator'], $artefact['approval_ratified'], $artefact['classes_missing'], $artefact['mapped_cards_regression'],
        ));

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
