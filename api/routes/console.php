<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\LogFreshness;
use App\Jobs\ProbeJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ProbeJob('scheduler'), 'default', 'redis')->everyMinute()->name('pokeprices-probe');

// The pending price scan (plan P2-i1 T08, R177(5)): hourly at :40, a no-op unless a collected day needs work.
Schedule::job(new IngestPending, 'default', 'redis')->hourlyAt(40)->withoutOverlapping()->name('pokeprices-ingest-pending');

// The freshness log line (plan P2-i1 T10, D8): hourly, info within the 26 h SLO, warning when breached.
Schedule::job(new LogFreshness, 'default', 'redis')->hourly()->name('pokeprices-freshness');

// The alerts dispatch (plan P5-i1 T01, D2, D8): every five minutes, price.moved deliveries of the deliverable day.
Schedule::job(new DispatchEvents, 'default', 'redis')->everyFiveMinutes()->withoutOverlapping()->name('pokeprices-alerts-dispatch');

// The delivery sweep (plan P5-i1 T02, D7, D8): every minute, due deliveries sent in-line within the sweep caps.
Schedule::job(new DeliverDue, 'default', 'redis')->everyMinute()->withoutOverlapping()->name('pokeprices-deliveries');
