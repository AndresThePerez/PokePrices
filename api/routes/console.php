<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\LogFreshness;
use App\Domain\Pricing\Models\IngestRunChunk;
use App\Domain\Pricing\PricingConfig;
use App\Jobs\ProbeJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ProbeJob('scheduler'), PricingConfig::queue(), 'redis')->everyMinute()->name('pokeprices-probe');

// The pending price scan: hourly at :40, a no-op unless a collected day needs work.
Schedule::job(new IngestPending, PricingConfig::queue(), 'redis')->hourlyAt(40)->withoutOverlapping()->name('pokeprices-ingest-pending');

// The freshness log line: hourly, info within the 26 h SLO, warning when breached or while a day is missing.
Schedule::job(new LogFreshness, PricingConfig::queue(), 'redis')->hourly()->name('pokeprices-freshness');

// The alerts dispatch: every five minutes, price.moved deliveries of the deliverable day.
Schedule::job(new DispatchEvents, PricingConfig::queue(), 'redis')->everyFiveMinutes()->withoutOverlapping()->name('pokeprices-alerts-dispatch');

// The delivery sweep: every minute, due deliveries sent in-line within the sweep caps.
Schedule::job(new DeliverDue, PricingConfig::queue(), 'redis')->everyMinute()->withoutOverlapping()->name('pokeprices-deliveries');

// Queue bookkeeping: failed jobs are kept 30 days (720 h), then pruned once a day.
Schedule::command('queue:prune-failed --hours=720')->daily()->name('pokeprices-prune-failed');

// Queue bookkeeping: job batches are kept 30 days (720 h), then pruned once a day.
Schedule::command('queue:prune-batches --hours=720')->daily()->name('pokeprices-prune-batches');

// Ingest bookkeeping: a superseded run's chunks are kept 30 days (720 h) after the run finished, then pruned once a day.
Schedule::command('model:prune', ['--model' => [IngestRunChunk::class]])->daily()->name('pokeprices-prune-chunks');

// Account bookkeeping: a password-reset token is good for 60 minutes; the expired rows are cleared once a day at 03:17
// UTC, off the minutes the price jobs run at (:00 the freshness line, :40 the pending scan, every fifth the dispatch).
Schedule::command('auth:clear-resets')->dailyAt('03:17')->timezone('UTC')->name('pokeprices-prune-resets');

// The price-job report: hourly, a command the scheduler runs itself and not a queued job, so a stopped worker is still
// reported. One Discord message when a problem starts and one when it clears. The overlap lock lasts 59 minutes at
// most, so a run that was killed never holds back the next hour's.
Schedule::command('pokeprices:prices:report-problems')->hourly()->withoutOverlapping(59)->name('pokeprices-price-problems');
