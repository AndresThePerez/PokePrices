<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\LogFreshness;
use App\Domain\Pricing\PricingConfig;
use App\Jobs\ProbeJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ProbeJob('scheduler'), PricingConfig::queue(), 'redis')->everyMinute()->name('pokeprices-probe');

// The pending price scan: hourly at :40, a no-op unless a collected day needs work.
Schedule::job(new IngestPending, PricingConfig::queue(), 'redis')->hourlyAt(40)->withoutOverlapping()->name('pokeprices-ingest-pending');

// The freshness log line: hourly, info within the 26 h SLO, warning when breached.
Schedule::job(new LogFreshness, PricingConfig::queue(), 'redis')->hourly()->name('pokeprices-freshness');

// The alerts dispatch: every five minutes, price.moved deliveries of the deliverable day.
Schedule::job(new DispatchEvents, PricingConfig::queue(), 'redis')->everyFiveMinutes()->withoutOverlapping()->name('pokeprices-alerts-dispatch');

// The delivery sweep: every minute, due deliveries sent in-line within the sweep caps.
Schedule::job(new DeliverDue, PricingConfig::queue(), 'redis')->everyMinute()->withoutOverlapping()->name('pokeprices-deliveries');

// Queue bookkeeping: failed jobs are kept 30 days (720 h), then pruned once a day.
Schedule::command('queue:prune-failed --hours=720')->daily()->name('pokeprices-prune-failed');

// Queue bookkeeping: job batches are kept 30 days (720 h), then pruned once a day.
Schedule::command('queue:prune-batches --hours=720')->daily()->name('pokeprices-prune-batches');
