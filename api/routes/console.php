<?php

declare(strict_types=1);

use App\Domain\Pricing\Jobs\IngestPending;
use App\Jobs\ProbeJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ProbeJob('scheduler'), 'default', 'redis')->everyMinute()->name('pokeprices-probe');

// The pending price scan (plan P2-i1 T08, R177(5)): hourly at :40, a no-op unless a collected day needs work.
Schedule::job(new IngestPending, 'default', 'redis')->hourlyAt(40)->withoutOverlapping()->name('pokeprices-ingest-pending');
