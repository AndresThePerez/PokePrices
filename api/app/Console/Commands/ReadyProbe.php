<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Readiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Run every readiness check once, bypassing the 5 s PokéSearch cache, and print one line per check.')]
#[Signature('pokeprices:ready-probe')]
final class ReadyProbe extends Command
{
    public function handle(Readiness $readiness): int
    {
        $failed = false;
        foreach ($readiness->run(false) as $name => $result) {
            $this->line($name.': '.$result);
            $failed = $failed || $result !== 'ok';
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
