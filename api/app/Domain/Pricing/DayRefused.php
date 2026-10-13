<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A collected day the ingest will not read (plan P2-i1 T04 item 1). $error is the machine-readable reason recorded
 * on the refused ingest_runs row, `<reason>:<file>[:…]` with the file relative to the day folder, for example
 * `missing_file:SUCCESS`, `marker_day_mismatch:marker.txt`, `manifest_mismatch:manifest.json:groups` or
 * `bad_value:prices/604.json.zst:42382:marketPrice`. $marker is set once marker.txt was read.
 */
final class DayRefused extends RuntimeException
{
    public function __construct(public readonly string $error, public readonly ?CarbonImmutable $marker = null)
    {
        parent::__construct($error);
    }
}
