<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountLimits;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Alerts\PriceMovedPayload;
use App\Domain\Pricing\Jobs\FinalizeDay;
use App\Domain\Pricing\Jobs\IngestPending;
use App\Domain\Pricing\Jobs\LogFreshness;
use App\Domain\Pricing\Jobs\StartDayIngest;
use App\Domain\Pricing\Models\PriceLatest;
use App\Domain\Pricing\PricingConfig;
use App\Jobs\ProbeJob;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Q1-i4 T03 review tests: the quantity cap at its edges (a knob of 1, a knob the database cannot hold, a knob that is
// not a usable number), the movers cap against a missing request limit, the queue name on every job and schedule line,
// the configured source followed by readers that bind it into SQL, and the default responses byte for byte.

beforeEach(function (): void {
    Accounts::setUp();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('never lets a quantity_max the database cannot hold reach Postgres: an unusable or too-large knob keeps 9999 and 10000 answers 422', function (mixed $knob): void {
    config(['accounts.limits.quantity_max' => $knob]);
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');
    $put = fn (int $quantity) => AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => $quantity]);

    $over = $put(10000);
    $top = $put(9999);

    expect(AccountLimits::quantityMax())->toBe(9999)
        ->and($over->getStatusCode())->toBe(422)
        ->and(array_keys($over->json('errors')))->toBe(['quantity'])
        ->and($top->getStatusCode())->toBe(200)
        ->and(AccountRows::held($user))->toBe(['ex5-17:reverse:-:-' => 9999]);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'numeric string' => ['12'],
    'non-numeric string' => ['abc'],
    'float' => [12.5],
    'null' => [null],
    'just above the CHECK' => [10000],
    'int max' => [PHP_INT_MAX],
]);

it('lets a quantity_max of 1 hold exactly one and refuses 2 and 0 with the field-level messages', function (): void {
    config(['accounts.limits.quantity_max' => 1]);
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');
    $put = fn (int $quantity) => AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => $quantity]);

    $one = $put(1);
    $two = $put(2);
    $zero = $put(0);

    expect($one->getStatusCode())->toBe(200)
        ->and($two->getStatusCode())->toBe(422)
        ->and($two->json('errors.quantity'))->toBe(['The quantity field must not be greater than 1.'])
        ->and($zero->getStatusCode())->toBe(422)
        ->and($zero->json('errors.quantity'))->toBe(['The quantity field must be at least 1.'])
        ->and(AccountRows::held($user))->toBe(['ex5-17:reverse:-:-' => 1]);
});

it('keeps the default quantity refusal text and the strict-integer rule when the knob is the default', function (): void {
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');
    $put = fn (mixed $quantity) => AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)
        ->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => $quantity]);

    $over = $put(10000);
    $string = $put('5');
    $float = $put(5.5);

    expect($over->json('errors.quantity'))->toBe(['The quantity field must not be greater than 9999.'])
        ->and($string->getStatusCode())->toBe(422)
        ->and($float->getStatusCode())->toBe(422)
        ->and(AccountRows::held($user))->toBe([]);
});

it('never reports a movers limit above the movers the rebuild keeps: with a knob of 10 and no limit asked, meta.limit is at most 10', function (): void {
    config(['pricing.movers.limit' => 10]);

    $none = $this->getJson('/v1/movers');
    $one = $this->getJson('/v1/movers?limit=1');
    $zero = $this->getJson('/v1/movers?limit=0');

    expect($none->getStatusCode())->toBe(200)
        ->and($none->json('meta.limit'))->toBeLessThanOrEqual(10)
        ->and($one->json('meta.limit'))->toBe(1)
        ->and($zero->getStatusCode())->toBe(422);
});

it('puts the jobs and every scheduled job line on pricing.queue and the redis connection, and a default config on default', function (): void {
    config(['pricing.queue' => 'example-queue']);
    $start = new StartDayIngest('2026-10-12');
    $finalize = new FinalizeDay(1);
    $probe = new ProbeJob('example');

    // routes/console.php registers its lines once at boot; reading it again under the new config shows each line's queue.
    $schedule = app(Schedule::class);
    $before = count($schedule->events());
    require base_path('routes/console.php');
    $added = array_slice($schedule->events(), $before);
    Queue::fake();
    foreach (array_slice($added, 0, 5) as $event) {
        expect($event)->toBeInstanceOf(CallbackEvent::class);
        $event->run(app());
    }

    // The two queue bookkeeping prunes and the superseded-chunk prune: artisan commands the scheduler runs itself, never queued jobs.
    expect([$start->queue, $start->connection, $finalize->queue, $finalize->connection, $probe->queue, $probe->connection])
        ->toBe(['example-queue', 'redis', 'example-queue', 'redis', 'example-queue', 'redis'])
        ->and(array_map(fn (ScheduledEvent $e): string => (string) $e->description, $added))
        ->toBe(['pokeprices-probe', 'pokeprices-ingest-pending', 'pokeprices-freshness', 'pokeprices-alerts-dispatch', 'pokeprices-deliveries', 'pokeprices-prune-failed', 'pokeprices-prune-batches', 'pokeprices-prune-chunks'])
        ->and(array_map(fn (ScheduledEvent $e): bool => $e instanceof CallbackEvent, array_slice($added, 5)))->toBe([false, false, false]);
    foreach ([ProbeJob::class, IngestPending::class, LogFreshness::class, DispatchEvents::class, DeliverDue::class] as $class) {
        Queue::assertPushedOn('example-queue', $class);
    }
});

it('reads the configured source where a reader binds it into SQL: a holding priced under another source is unpriced, and the metas name the configured one', function (): void {
    config(['pricing.source' => 'example-source']);
    $user = Accounts::user();
    AccountRows::hold($user, 'ex5-17:reverse:-:-', 2);
    AccountRows::price('ex5-17:reverse:-:-', '10.00', '2026-10-12');

    $portfolio = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');
    $movers = $this->getJson('/v1/movers');

    expect($portfolio->getStatusCode())->toBe(200)
        ->and($portfolio->json('meta.source'))->toBe('example-source')
        ->and($portfolio->json('meta.priced'))->toBe(0)
        ->and($portfolio->json('meta.unpriced'))->toBe(1)
        ->and($portfolio->json('data.0.price'))->toBeNull()
        ->and($movers->json('meta.source'))->toBe('example-source');
});

it('keeps a price block and a price.moved payload on the row\'s own source while feed, basis and currency follow the accessor', function (): void {
    config(['pricing.source' => 'example-source', 'pricing.feed' => 'example-feed']);
    $latest = new PriceLatest;
    $latest->setRawAttributes([
        'variant_key' => 'ex5-17:reverse:-:-', 'source' => 'row-source', 'series_epoch' => 1, 'close' => '26.34',
        'day' => '2026-10-12', 'as_of' => '2026-10-12 20:05:45+00', 'previous_close' => null, 'previous_day' => null,
        'change' => null, 'change_pct' => null,
    ]);
    $event = (object) [
        'id' => 7, 'variant_key' => 'ex5-17:reverse:-:-', 'card_id' => 'ex5-17', 'direction' => 'up', 'close' => '26.34',
        'previous_close' => '25.00', 'change' => '1.34', 'change_pct' => '5.36', 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z',
        'previous_day' => '2026-10-11', 'previous_as_of' => '2026-10-11T20:05:45Z', 'series_epoch' => 1, 'rule' => 'draft-v1',
        'source' => 'row-source',
    ];

    $payload = json_decode(PriceMovedPayload::encode($event, null), true, 512, JSON_THROW_ON_ERROR)['data'];

    expect(PricingConfig::provenance('row-source'))->toBe(['source' => 'row-source', 'feed' => 'example-feed', 'basis' => 'market', 'currency' => 'USD'])
        ->and(array_slice($payload, 15, 5))->toBe(['source' => 'row-source', 'feed' => 'example-feed', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily']);
});

it('answers the empty movers list and the price.moved payload byte for byte as before the accessor', function (): void {
    $movers = $this->get('/v1/movers', ['Accept' => 'application/json']);
    $event = (object) [
        'id' => 7, 'variant_key' => 'ex5-17:reverse:-:-', 'card_id' => 'ex5-17', 'direction' => 'up', 'close' => '26.34',
        'previous_close' => '25.00', 'change' => '1.34', 'change_pct' => '5.36', 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z',
        'previous_day' => '2026-10-11', 'previous_as_of' => '2026-10-11T20:05:45Z', 'series_epoch' => 1, 'rule' => 'draft-v1',
        'source' => 'tcgplayer',
    ];

    expect($movers->getContent())->toBe('{"data":[],"meta":{"day":null,"as_of":null,"source":"tcgplayer","feed":"tcgcsv","basis":"market","currency":"USD","window":"previous_close","min_previous_close":"2.00","direction":"up","limit":20,"degraded":false}}')
        ->and(PriceMovedPayload::encode($event, null))->toContain('"rule":"draft-v1","source":"tcgplayer","feed":"tcgcsv","basis":"market","currency":"USD","cadence":"daily"}');
});

it('keeps pricing.movers.limit between 1 and the 50 movers price_movers keeps: an unusable knob gives 50, a larger one is clamped to 50', function (mixed $knob, int $expected): void {
    config(['pricing.movers.limit' => $knob]);

    expect(PricingConfig::moversLimit())->toBe($expected);
})->with([
    'zero' => [0, 50],
    'negative' => [-3, 50],
    'non-numeric string' => ['abc', 50],
    'numeric string' => ['30', 50],
    'null' => [null, 50],
    'above the keep' => [80, 50],
    'int max' => [PHP_INT_MAX, 50],
    'within the keep' => [30, 30],
    'one' => [1, 1],
    'the keep' => [50, 50],
]);

it('answers GET /v1/movers?limit=50 with 200 under a knob of 0, and refuses 51 under a knob of 80', function (): void {
    config(['pricing.movers.limit' => 0]);
    $fifty = $this->getJson('/v1/movers?limit=50');
    $none = $this->getJson('/v1/movers');
    config(['pricing.movers.limit' => 80]);
    $over = $this->getJson('/v1/movers?limit=51');

    expect($fifty->getStatusCode())->toBe(200)
        ->and($fifty->json('meta.limit'))->toBe(50)
        ->and($none->getStatusCode())->toBe(200)
        ->and($none->json('meta.limit'))->toBe(20)
        ->and($over->getStatusCode())->toBe(422)
        ->and(array_keys($over->json('errors')))->toBe(['limit']);
});

it('keeps pricing.movers.limit in [1, 50] for the knobs nobody tried: a float, a bool, an array, a million, a missing key and the edge of the keep', function (mixed $knob, int $expected): void {
    config(['pricing.movers.limit' => $knob]);

    expect(PricingConfig::moversLimit())->toBe($expected)
        ->and(PricingConfig::defaultMoversLimit())->toBe(min(20, $expected));
})->with([
    'a float with a fraction' => [30.5, 50],
    'a whole float' => [30.0, 50],
    'true' => [true, 50],
    'false' => [false, 50],
    'an empty array' => [[], 50],
    'a million' => [1_000_000_000, 50],
    'int min' => [PHP_INT_MIN, 50],
    'fifty-one' => [51, 50],
    'forty-nine' => [49, 49],
    'twenty' => [20, 20],
    'twenty-one' => [21, 21],
    'nineteen' => [19, 19],
    'five' => [5, 5],
]);

it('lets a request ask for exactly the clamped knob and no more: a knob of 5 answers limit=5 with 200 and limit=6 with 422, and a knob of 80 stops at 50', function (): void {
    config(['pricing.movers.limit' => 5]);
    $five = $this->getJson('/v1/movers?limit=5');
    $six = $this->getJson('/v1/movers?limit=6');
    $none = $this->getJson('/v1/movers');
    config(['pricing.movers.limit' => 80]);
    $fifty = $this->getJson('/v1/movers?limit=50');
    $over = $this->getJson('/v1/movers?limit=51');

    expect($five->getStatusCode())->toBe(200)
        ->and($five->json('meta.limit'))->toBe(5)
        ->and($six->getStatusCode())->toBe(422)
        ->and(array_keys($six->json('errors')))->toBe(['limit'])
        ->and($none->json('meta.limit'))->toBe(5)
        ->and($fifty->getStatusCode())->toBe(200)
        ->and($fifty->json('meta.limit'))->toBe(50)
        ->and($over->getStatusCode())->toBe(422)
        ->and($over->json('errors.limit'))->toBe(['The limit field must not be greater than 50.']);
});
