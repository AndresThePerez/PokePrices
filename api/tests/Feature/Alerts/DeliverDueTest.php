<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\Models\Delivery;
use App\Domain\Alerts\Models\WebhookEndpoint;
use App\Domain\Alerts\TestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;
use Tests\Support\ForbiddenWords;

// Plan P5-i1 T02 criteria 5, 10, 11 and 12 (D7, D8, review A04, A05, A10.4): the sweep claims due rows within its
// caps, sends in-line within its budget, releases what it did not send, survives a crashed sweep, retires poison
// rows, keeps 30 days, and the test message; the job is unique and scheduled every minute.

beforeEach(function (): void {
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->start = CarbonImmutable::now();
    $this->user = AlertRows::user('sweep@example.com');
});

/**
 * $count endpoints at https://h<n>.example.com/hook (each resolving to a public test address) with $perEndpoint
 * due rows each, inserted round-robin so the oldest rows alternate endpoints; returns [endpoint ids, delivery ids].
 *
 * @return array{0: list<int>, 1: list<int>}
 */
function p5Spread(object $test, int $count, int $perEndpoint): array
{
    $answers = [];
    $endpoints = [];
    for ($e = 1; $e <= $count; $e++) {
        $answers["h{$e}.example.com"] = ['203.0.113.'.(10 + $e)];
        $endpoints[] = DeliveryRows::endpoint($test->user, "https://h{$e}.example.com/hook");
    }
    FakeHostResolver::install($answers);
    $rows = [];
    for ($i = 0; $i < $perEndpoint; $i++) {
        foreach ($endpoints as $endpoint) {
            $rows[] = DeliveryRows::delivery($endpoint);
        }
    }

    return [$endpoints, $rows];
}

it('claims only due pending rows, oldest first, at most 10 and at most 3 per endpoint', function (): void {
    [$endpoints] = p5Spread($this, 2, 0);
    $a = $endpoints[0];
    $b = $endpoints[1];
    $ids = [];
    for ($i = 0; $i < 5; $i++) {
        $ids['a'][] = DeliveryRows::delivery($a, ['next_attempt_at' => $this->start->subMinutes(10 - $i)->format('Y-m-d H:i:sP')]);
    }
    $ids['b'][] = DeliveryRows::delivery($b, ['next_attempt_at' => $this->start->subMinutes(20)->format('Y-m-d H:i:sP')]);
    $ids['future'] = DeliveryRows::delivery($a, ['next_attempt_at' => $this->start->addSecond()->format('Y-m-d H:i:sP')]);
    $ids['done'] = DeliveryRows::delivery($a, ['status' => 'delivered', 'next_attempt_at' => $this->start->subHour()->format('Y-m-d H:i:sP')]);

    $claimed = DB::transaction(fn (): array => DeliverDue::claim(DB::connection(), $this->start, 10));

    expect($claimed)->toBe([$ids['b'][0], $ids['a'][0], $ids['a'][1], $ids['a'][2]]);
    foreach ($claimed as $id) {
        $row = DeliveryRows::row($id);
        expect($row->claims)->toBe(1)
            ->and($row->attempts)->toBe(0)
            ->and($row->next_at)->toBe($this->start->addSeconds(300)->format('Y-m-d\TH:i:s.u'));
    }
    expect(DeliveryRows::row($ids['a'][3])->claims)->toBe(0)
        ->and(DeliveryRows::row($ids['future'])->claims)->toBe(0)
        ->and(DeliveryRows::row($ids['done'])->claims)->toBe(0);
});

it('stops at the batch of 10 across many endpoints', function (): void {
    [, $rows] = p5Spread($this, 12, 1);

    $claimed = DeliverDue::claim(DB::connection(), $this->start, 10);

    expect($claimed)->toBe(array_slice($rows, 0, 10));
});

it('stops sending to an endpoint after its first timeout in a sweep and stays inside the budget (tarpit)', function (): void {
    [$endpoints, $rows] = p5Spread($this, 5, 6);
    $hosts = [];
    Http::fake(function (ClientRequest $request) use (&$hosts) {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);
        $hosts[$host] = ($hosts[$host] ?? 0) + 1;
        $this->travel(5)->seconds();

        throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds');
    });

    [$code, $summary] = DeliveryRows::sweep();

    $elapsed = $this->start->diffInSeconds(CarbonImmutable::now());
    $attempts = (int) DB::table('deliveries')->sum('attempts');
    expect($code)->toBe(0)
        ->and($summary)->toBe(['retired' => 0, 'claimed' => 10, 'delivered' => 0, 'retry' => 5, 'failed' => 0, 'released' => 5])
        ->and($hosts)->toBe(['h1.example.com' => 1, 'h2.example.com' => 1, 'h3.example.com' => 1, 'h4.example.com' => 1, 'h5.example.com' => 1])
        ->and($attempts)->toBe(5)
        ->and($elapsed)->toBeLessThanOrEqual(65.0);

    // Rows 6..10 were claimed behind a slow endpoint and released: no attempt, no claim spent, due after delay(1).
    foreach (array_slice($rows, 5, 5) as $id) {
        $row = DeliveryRows::row($id);
        $due = CarbonImmutable::parse($row->next_at.'Z');
        expect([$row->status, $row->attempts, $row->claims])->toBe(['pending', 0, 0])
            ->and($this->start->diffInSeconds($due, false))->toBeGreaterThanOrEqual(15.0)->toBeLessThanOrEqual(30.0);
    }
    foreach (array_slice($rows, 10) as $id) {
        expect([DeliveryRows::row($id)->claims, DeliveryRows::row($id)->attempts])->toBe([0, 0]);
    }
});

it('stops sending when the 60 s budget is spent and releases the rest as due', function (): void {
    [, $rows] = p5Spread($this, 10, 3);
    Http::fake(function (ClientRequest $request) {
        $this->travel(25)->seconds();

        return Http::response('', 204);
    });

    [, $summary] = DeliveryRows::sweep();

    $elapsed = $this->start->diffInSeconds(CarbonImmutable::now());
    expect($summary)->toBe(['retired' => 0, 'claimed' => 10, 'delivered' => 3, 'retry' => 0, 'failed' => 0, 'released' => 7])
        ->and(Http::recorded())->toHaveCount(3)
        ->and((int) DB::table('deliveries')->sum('attempts'))->toBe(3)
        ->and($elapsed)->toBeLessThanOrEqual(85.0)
        ->and($elapsed)->toBeGreaterThanOrEqual(60.0);
    foreach (array_slice($rows, 3, 7) as $id) {
        $row = DeliveryRows::row($id);
        expect([$row->status, $row->attempts, $row->claims, $row->next_at])->toBe(['pending', 0, 0, $this->start->format('Y-m-d\TH:i:s.u')]);
    }

    // The released rows are due again: the next sweep claims and sends them.
    Http::fake(['*' => Http::response('', 204)]);
    [, $next] = DeliveryRows::sweep();
    expect($next['claimed'])->toBe(10);
});

it('implements ShouldBeUnique, so a second dispatch while one is queued is dropped', function (): void {
    Queue::fake();

    DeliverDue::dispatch();
    DeliverDue::dispatch();

    $job = new DeliverDue;
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->uniqueFor)->toBe(120);
    Queue::assertPushed(DeliverDue::class, 1);
});

it('claims a row again 300 s after a sweep died holding it, and sends it with the same webhook-id', function (): void {
    DeliveryRows::resolver();
    $endpoint = DeliveryRows::endpoint($this->user);
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    // A sweep claimed it, then died before sending.
    DeliverDue::claim(DB::connection(), $this->start, 10);

    $this->travel(299)->seconds();
    [, $early] = DeliveryRows::sweep();
    $this->travel(1)->seconds();
    [, $late] = DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect($early['claimed'])->toBe(0)
        ->and($late['claimed'])->toBe(1)
        ->and(Http::recorded())->toHaveCount(1)
        ->and(Http::recorded()[0][0]->header('webhook-id'))->toBe([$row->message_id])
        ->and([$row->status, $row->claims, $row->attempts])->toBe(['delivered', 2, 1]);
});

it('fails a row whose endpoint URL cannot be decrypted after six attempts, with no failed job', function (): void {
    DeliveryRows::resolver();
    $endpoint = DeliveryRows::endpoint($this->user);
    DB::update('UPDATE webhook_endpoints SET url = ? WHERE id = ?', ['eyJub3QiOiJhIGNpcGhlcnRleHQifQ==', $endpoint]);
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(['*' => Http::response('', 204)]);

    for ($i = 0; $i < 7; $i++) {
        DeliveryRows::job();
        $this->travel(3601)->seconds();
    }

    $row = DeliveryRows::row($id);
    $lines = AlertRows::logged('delivery_attempt');
    expect([$row->status, $row->last_error, $row->attempts])->toBe(['failed', 'internal', 6])
        ->and($lines)->toHaveCount(6)
        ->and(array_column(array_column($lines, 'context'), 'exception'))->toBe(array_fill(0, 6, 'DecryptException'))
        ->and(array_column(array_column($lines, 'context'), 'error'))->toBe(array_fill(0, 6, 'internal'))
        ->and(Http::recorded())->toHaveCount(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('retires a row claimed 12 times without a request', function (): void {
    DeliveryRows::resolver();
    $endpoint = DeliveryRows::endpoint($this->user);
    $poison = DeliveryRows::delivery($endpoint, ['claims' => 12]);
    $young = DeliveryRows::delivery($endpoint, ['claims' => 11]);
    Http::fake(['*' => Http::response('', 204)]);

    [, $summary] = DeliveryRows::sweep();

    expect($summary['retired'])->toBe(1)
        ->and([DeliveryRows::row($poison)->status, DeliveryRows::row($poison)->last_error, DeliveryRows::row($poison)->attempts])->toBe(['failed', 'internal', 0])
        ->and(DeliveryRows::row($young)->status)->toBe('delivered')
        ->and(Http::recorded())->toHaveCount(1);
});

it('deletes delivered and failed rows created more than 30 days before its clock and keeps the rest', function (): void {
    DeliveryRows::resolver();
    $endpoint = DeliveryRows::endpoint($this->user);
    $old = $this->start->subDays(30)->subSecond()->format('Y-m-d H:i:sP');
    $edge = $this->start->subDays(30)->addSecond()->format('Y-m-d H:i:sP');
    $later = $this->start->addHour()->format('Y-m-d H:i:sP');
    $gone = [
        DeliveryRows::delivery($endpoint, ['status' => 'delivered', 'created_at' => $old]),
        DeliveryRows::delivery($endpoint, ['status' => 'failed', 'created_at' => $old]),
    ];
    $kept = [
        DeliveryRows::delivery($endpoint, ['status' => 'delivered', 'created_at' => $edge]),
        DeliveryRows::delivery($endpoint, ['status' => 'pending', 'created_at' => $old, 'next_attempt_at' => $later]),
    ];
    Http::fake(['*' => Http::response('', 204)]);

    DeliveryRows::sweep();

    expect(DB::table('deliveries')->whereIn('id', $gone)->count())->toBe(0)
        ->and(DB::table('deliveries')->whereIn('id', $kept)->orderBy('id')->pluck('id')->all())->toBe($kept);
});

it('queues a webhook.test message that the deliver command sends', function (): void {
    DeliveryRows::resolver();
    $endpoint = WebhookEndpoint::query()->findOrFail(DeliveryRows::endpoint($this->user));
    Http::fake(['*' => Http::response('', 204)]);

    $delivery = TestMessage::queue($endpoint);

    $row = DB::selectOne("SELECT kind, channel, endpoint_id, user_id, event_id, day, body, status, next_attempt_at <= ?::timestamptz AS due,
        to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS created FROM deliveries WHERE id = ?", [$this->start->format('Y-m-d H:i:sP'), $delivery->id]);
    expect($delivery)->toBeInstanceOf(Delivery::class)
        ->and($delivery->message_id)->toMatch('/^msg_[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and([$row->kind, $row->channel, $row->endpoint_id, $row->user_id, $row->event_id, $row->day, $row->status, $row->due])
        ->toBe(['webhook.test', 'webhook', $endpoint->id, $this->user->id, null, null, 'pending', true])
        ->and($row->created)->toBe('2026-10-15T12:00:00Z')
        ->and($row->body)->toBe('{"type":"webhook.test","timestamp":"2026-10-15T12:00:00Z","data":{"message":"PokéPrices test message. It carries no price."}}')
        ->and(ForbiddenWords::payloadMatches($row->body))->toBe([]);

    [, $summary] = DeliveryRows::sweep();

    expect($summary['delivered'])->toBe(1)
        ->and(Http::recorded()[0][0]->body())->toBe($row->body)
        ->and(Http::recorded()[0][0]->header('webhook-id'))->toBe([$delivery->message_id]);
});

it('prints the sweep line with zeros when nothing is due', function (): void {
    [$code, $summary] = DeliveryRows::sweep();

    expect($code)->toBe(0)
        ->and($summary)->toBe(['retired' => 0, 'claimed' => 0, 'delivered' => 0, 'retry' => 0, 'failed' => 0, 'released' => 0])
        ->and(Http::recorded())->toHaveCount(0);
});

it('schedules the sweep every minute without overlapping, as a DeliverDue job on redis/default', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e): bool => $e->description === 'pokeprices-deliveries')->values();
    Bus::fake();
    $event = $events->first();
    expect($event)->toBeInstanceOf(CallbackEvent::class);
    $callback = (fn () => $this->callback)->call($event);
    app()->call($callback);

    expect($events)->toHaveCount(1)
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
    Bus::assertDispatched(DeliverDue::class, fn (DeliverDue $job): bool => $job->connection === 'redis' && $job->queue === 'default');
});
