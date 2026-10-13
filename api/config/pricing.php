<?php

declare(strict_types=1);

// Price ingestion settings (plan P2-i1 D9). The thresholds are the day-close ADR's subject, not deploy knobs, so
// none of them reads the environment.
return [
    'source' => 'tcgplayer',
    'feed' => 'tcgcsv',
    'fill_cap_days' => 14,
    'chunk_rows' => 200,
    'movement' => [
        'rule' => 'draft-v1',
        'pct' => '5.00',
        'abs' => '1.00',
    ],
    'movers' => [
        'limit' => 50,
        'min_previous_close' => '2.00',
    ],
    'freshness' => [
        'slo_hours' => 26,
    ],
    'queue' => 'default',
];
