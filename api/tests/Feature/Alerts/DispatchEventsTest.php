<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DispatchEvents;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\ForbiddenWords;

// Plan P5-i1 T01 criteria 6, 7, 8 and 10 (D3, D4, D8, D11): who receives a price.moved delivery, the catalog read
// only when something is missing, the byte-exact payload, the volume line and the schedule entry.

const P5_VARIANT = 'base1-4:holo:-:-';
const P5_OTHER = 'base1-2:holo:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** The recorded PokéSearch requests (any path). */
function p5CatalogRequests(): int
{
    return Http::recorded(fn (ClientRequest $r): bool => true)->count();
}

/** The one price.moved event of the payload tests: fixed values, as_of stamped as AccountRows stamps it. */
function p5PayloadEvent(): int
{
    return AlertRows::event(P5_VARIANT, '2026-10-12', [
        'direction' => 'up', 'close' => '968.91', 'previous_close' => '850.00', 'previous_day' => '2026-10-11',
        'change' => '118.91', 'change_pct' => '13.99', 'as_of' => '2026-10-12 20:05:45+00',
        'previous_as_of' => '2026-10-11 20:05:45+00', 'series_epoch' => 1,
    ]);
}

it('delivers an event once to each active standard endpoint of each eligible watcher or holder, and to nobody else', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $event = AlertRows::event(P5_VARIANT, '2026-10-12');

    $watcher = AlertRows::user('watcher@example.com');
    AccountRows::watch($watcher, P5_VARIANT);
    $w1 = AlertRows::endpoint($watcher);
    $w2 = AlertRows::endpoint($watcher);

    $holder = AlertRows::user('holder@example.com');
    AccountRows::hold($holder, P5_VARIANT, 2);
    $h1 = AlertRows::endpoint($holder);
    $h2 = AlertRows::endpoint($holder);

    $stranger = AlertRows::user('stranger@example.com');
    AccountRows::watch($stranger, P5_OTHER);
    AlertRows::endpoint($stranger);

    $unverified = AlertRows::user('unverified@example.com', ['email_verified_at' => null]);
    AccountRows::watch($unverified, P5_VARIANT);
    AlertRows::endpoint($unverified);

    $demo = AlertRows::user('demo@example.com', ['is_demo' => true]);
    AccountRows::watch($demo, P5_VARIANT);
    AccountRows::hold($demo, P5_VARIANT, 1);
    DB::table('webhook_endpoints')->insert(['user_id' => $demo->id, 'kind' => 'standard', 'url' => 'x', 'url_hint' => 'x', 'secret' => 'x']);

    $offAndDiscord = AlertRows::user('discord@example.com');
    AccountRows::watch($offAndDiscord, P5_VARIANT);
    AlertRows::endpoint($offAndDiscord, 'standard', disabled: true);
    AlertRows::endpoint($offAndDiscord, 'discord');

    [$code, $line] = AlertRows::dispatch();
    $rows = DB::select('SELECT user_id, kind, channel, endpoint_id, event_id, day::text AS day, status, attempts, claims, next_attempt_at, last_status, last_error, delivered_at FROM deliveries ORDER BY endpoint_id');

    expect($code)->toBe(0)
        ->and($line['created'])->toBe(['price.moved' => 4, 'alert.triggered' => 0, 'digest' => 0])
        ->and(AlertRows::moved())->toBe([[$event, $w1], [$event, $w2], [$event, $h1], [$event, $h2]])
        ->and(array_map(fn (object $r): array => [(int) $r->user_id, $r->kind, $r->channel, (int) $r->event_id, $r->day, $r->status, (int) $r->attempts, (int) $r->claims, $r->last_status, $r->last_error, $r->delivered_at], $rows))
        ->toBe([
            [$watcher->id, 'price.moved', 'webhook', $event, '2026-10-12', 'pending', 0, 0, null, null, null],
            [$watcher->id, 'price.moved', 'webhook', $event, '2026-10-12', 'pending', 0, 0, null, null, null],
            [$holder->id, 'price.moved', 'webhook', $event, '2026-10-12', 'pending', 0, 0, null, null, null],
            [$holder->id, 'price.moved', 'webhook', $event, '2026-10-12', 'pending', 0, 0, null, null, null],
        ])
        ->and(CarbonImmutable::parse($rows[0]->next_attempt_at)->equalTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC')))->toBeTrue();
});

it('delivers once to a user who both watches and holds the variant', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(P5_VARIANT, '2026-10-12');
    $user = AlertRows::user('both@example.com');
    AccountRows::watch($user, P5_VARIANT);
    AccountRows::hold($user, P5_VARIANT, 1);
    $endpoint = AlertRows::endpoint($user);

    AlertRows::dispatch();

    expect(AlertRows::moved())->toBe([[$event, $endpoint]]);
});

it('creates each delivery once over three runs, and a watcher added between runs gets exactly its own', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $a = AlertRows::event(P5_VARIANT, '2026-10-12');
    $b = AlertRows::event(P5_OTHER, '2026-10-12');
    $first = AlertRows::user('first@example.com');
    AccountRows::watch($first, P5_VARIANT);
    AccountRows::watch($first, P5_OTHER);
    $e1 = AlertRows::endpoint($first);

    $created = [];
    [, $line] = AlertRows::dispatch();
    $created[] = $line['created']['price.moved'];
    [, $line] = AlertRows::dispatch();
    $created[] = $line['created']['price.moved'];
    $afterTwo = DB::table('deliveries')->count();

    $late = AlertRows::user('late@example.com');
    AccountRows::hold($late, P5_OTHER, 1);
    $e2 = AlertRows::endpoint($late);
    [, $line] = AlertRows::dispatch();
    $created[] = $line['created']['price.moved'];
    [, $line] = AlertRows::dispatch();
    $created[] = $line['created']['price.moved'];

    expect($created)->toBe([2, 0, 1, 0])
        ->and($afterTwo)->toBe(2)
        ->and(AlertRows::moved())->toBe([[$a, $e1], [$b, $e1], [$b, $e2]]);
});

it('reads card names only when a delivery is missing', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(P5_VARIANT, '2026-10-12');
    $user = AlertRows::user('watcher@example.com');
    AccountRows::watch($user, P5_VARIANT);
    AlertRows::endpoint($user);
    AlertRows::dispatch();
    $first = AccountRows::batches();

    // A cold catalog cache and a fresh request log: any cardNames() call would now have to ask PokéSearch.
    CatalogCache::flush($this->catalogPrefix);
    Http::swap(new Factory);
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    [, $line] = AlertRows::dispatch();

    expect($first)->toBe([['base1-4']])
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(p5CatalogRequests())->toBe(0);
});

it('creates nothing while the catalog is degraded within 30 minutes of the day\'s run, and creates with a null card after', function (): void {
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', 'ok', 'sync', '2026-10-12 20:30:00+00');
    p5PayloadEvent();
    $user = AlertRows::user('watcher@example.com');
    AccountRows::watch($user, P5_VARIANT);
    AlertRows::endpoint($user);
    AccountRows::$down = true;

    $this->travelTo(CarbonImmutable::parse('2026-10-12 20:59:00', 'UTC'));
    [$within, $withinLine] = AlertRows::dispatch();
    $withinRows = DB::table('deliveries')->count();
    $warnings = array_values(array_filter(AlertRows::logged('alerts_dispatch_skipped'), fn (array $r): bool => $r['context']['reason'] === 'catalog degraded'));

    $this->travelTo(CarbonImmutable::parse('2026-10-12 21:00:01', 'UTC'));
    [$after, $afterLine] = AlertRows::dispatch();
    $body = json_decode((string) DB::table('deliveries')->value('body'), true);

    expect([$within, $after])->toBe([0, 0])
        ->and($withinLine)->toMatchArray(['deliverable_day' => '2026-10-12', 'skipped' => 'catalog degraded', 'created' => ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0]])
        ->and($withinRows)->toBe(0)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['level'])->toBe('warning')
        ->and($afterLine)->toMatchArray(['skipped' => null, 'created' => ['price.moved' => 1, 'alert.triggered' => 0, 'digest' => 0]])
        ->and($body['data']['card'])->toBeNull()
        ->and(array_keys($body['data']))->toContain('card');
});

it('builds the price.moved body byte for byte from the event row and the catalog name', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $id = p5PayloadEvent();
    $user = AlertRows::user('watcher@example.com');
    AccountRows::watch($user, P5_VARIANT);
    AlertRows::endpoint($user);
    AlertRows::endpoint($user);

    AlertRows::dispatch();
    $rows = DB::table('deliveries')->orderBy('id')->get(['message_id', 'body']);

    $expected = '{"type":"price.moved","timestamp":"2026-10-12T20:05:45Z","data":{"event_id":"evt_'.$id.'",'
        .'"variant_key":"base1-4:holo:-:-","card_id":"base1-4","card":{"name":"Name of base1-4","set_name":"Set of base1-4","number":"4"},'
        .'"direction":"up","close":"968.91","previous_close":"850.00","change":"118.91","change_pct":"13.99",'
        .'"day":"2026-10-12","as_of":"2026-10-12T20:05:45Z","previous_day":"2026-10-11","previous_as_of":"2026-10-11T20:05:45Z",'
        .'"series_epoch":1,"rule":"draft-v1","source":"tcgplayer","feed":"tcgcsv","basis":"market","currency":"USD","cadence":"daily"}}';

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->body)->toBe($expected)
        ->and($rows[1]->body)->toBe($expected)
        ->and($rows[0]->message_id)->toMatch('/^msg_[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($rows[1]->message_id)->toMatch('/^msg_[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($rows[0]->message_id)->not->toBe($rows[1]->message_id)
        ->and(ForbiddenWords::payloadMatches($rows[0]->body))->toBe([]);
});

it('writes a down move with a negative change as the stored text', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(P5_VARIANT, '2026-10-12', [
        'direction' => 'down', 'close' => '4.00', 'previous_close' => '5.50', 'change' => '-1.50', 'change_pct' => '-27.27',
    ]);
    $user = AlertRows::user('watcher@example.com');
    AccountRows::watch($user, P5_VARIANT);
    AlertRows::endpoint($user);

    AlertRows::dispatch();
    $body = (string) DB::table('deliveries')->value('body');

    expect($body)->toContain('"direction":"down","close":"4.00","previous_close":"5.50","change":"-1.50","change_pct":"-27.27"')
        ->and(ForbiddenWords::payloadMatches($body))->toBe([]);
});

it('reports events, measured and scoped_events, and logs the same object as alerts_dispatch', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(P5_VARIANT, '2026-10-12');
    AlertRows::event(P5_OTHER, '2026-10-12');
    AlertRows::event('base1-7:holo:-:-', '2026-10-12');
    DB::table('movement_volume')->insert([
        ['day' => '2026-10-12', 'source' => 'tcgplayer', 'rule' => 'draft-v1', 'pct' => '5.00', 'abs' => '1.00', 'compared' => 40, 'up' => 2, 'down' => 1, 'epoch_breaks' => 0, 'filled_skipped' => 0, 'extreme' => 0, 'sub_2_previous' => 0],
        ['day' => '2026-10-12', 'source' => 'tcgplayer', 'rule' => 'strict', 'pct' => '10.00', 'abs' => '2.00', 'compared' => 40, 'up' => 1, 'down' => 0, 'epoch_breaks' => 0, 'filled_skipped' => 0, 'extreme' => 0, 'sub_2_previous' => 0],
    ]);
    $watcher = AlertRows::user('watcher@example.com');
    AccountRows::watch($watcher, P5_VARIANT);
    AlertRows::endpoint($watcher);
    $noEndpoint = AlertRows::user('noendpoint@example.com');
    AccountRows::hold($noEndpoint, P5_OTHER, 1);
    $unverified = AlertRows::user('unverified@example.com', ['email_verified_at' => null]);
    AccountRows::watch($unverified, 'base1-7:holo:-:-');

    [$code, $line, $output] = AlertRows::dispatch();
    $logged = AlertRows::logged('alerts_dispatch');

    expect($code)->toBe(0)
        ->and(array_keys($line))->toBe(['source', 'deliverable_day', 'newest_day', 'floor', 'watermark', 'ceiling', 'events', 'measured', 'scoped_events', 'created', 'skipped', 'passed_over'])
        ->and($line)->toMatchArray(['source' => 'tcgplayer', 'deliverable_day' => '2026-10-12', 'events' => 3, 'measured' => 3, 'scoped_events' => 2, 'created' => ['price.moved' => 1, 'alert.triggered' => 0, 'digest' => 0]])
        ->and(substr_count(trim($output), "\n"))->toBe(0)
        ->and($logged)->toHaveCount(1)
        ->and($logged[0]['level'])->toBe('info')
        ->and($logged[0]['context'])->toBe($line);
});

it('reports no finalized day with exit 0 and creates nothing', function (): void {
    AlertRows::run('2026-10-12', 'failed');

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and($line)->toBe([
            'source' => 'tcgplayer', 'deliverable_day' => null, 'newest_day' => null, 'floor' => null, 'watermark' => null,
            'ceiling' => null, 'events' => 0, 'measured' => null, 'scoped_events' => 0, 'created' => ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0],
            'skipped' => 'no finalized day', 'passed_over' => [],
        ])
        ->and(AlertRows::days())->toBe([])
        ->and(AlertRows::logged('alerts_dispatch')[0]['context'])->toBe($line);
});

it('runs the same dispatch from the queued job as from the command', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(P5_VARIANT, '2026-10-12');
    $user = AlertRows::user('watcher@example.com');
    AccountRows::watch($user, P5_VARIANT);
    $endpoint = AlertRows::endpoint($user);

    dispatch(new DispatchEvents);

    expect(AlertRows::moved())->toBe([[$event, $endpoint]])
        ->and(AlertRows::logged('alerts_dispatch')[0]['context']['created'])->toBe(['price.moved' => 1, 'alert.triggered' => 0, 'digest' => 0]);
});

it('schedules DispatchEvents every five minutes without overlapping as pokeprices-alerts-dispatch on redis/default', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e): bool => $e->description === 'pokeprices-alerts-dispatch')->values();
    Bus::fake();
    $event = $events->first();
    expect($event)->toBeInstanceOf(CallbackEvent::class);
    $callback = (fn () => $this->callback)->call($event);
    app()->call($callback);

    expect($events)->toHaveCount(1)
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
    Bus::assertDispatched(DispatchEvents::class, fn (DispatchEvents $job): bool => $job->connection === 'redis' && $job->queue === 'default');
});
