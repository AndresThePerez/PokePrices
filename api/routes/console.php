<?php

declare(strict_types=1);

use App\Jobs\ProbeJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ProbeJob('scheduler'), 'default', 'redis')->everyMinute()->name('pokeprices-probe');
