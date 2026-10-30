<?php

declare(strict_types=1);

/*
| PokéPrices rate tiers. One named limiter per tier, registered by
| App\Domain\Catalog\RateLimits; routes opt in with throttle:<tier>. Each tier is
| ['per_minute' => n >= 1, 'by' => 'ip'], and anything else fails the boot.
*/

return [
    'public-read' => ['per_minute' => 120, 'by' => 'ip'],
    'search' => ['per_minute' => 60, 'by' => 'ip'],
];
