<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\RetryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Plan Q1-i1 T01, adversarial review round 1: the containment holds in the corners the author's tests leave open
// (the disable branch of the outcome transaction, leaks into the warning, the slow-endpoint rule, a write that never
// succeeds, the named connection on the warning, disable and email paths, the timing ordering).

beforeEach(function (): void {
    Http::preventStrayRequests();
    DeliveryRows::resolver();
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->start = CarbonImmutable::now();
    $this->user = AlertRows::user('containment-edge@example.com');
});

/**
 * A connection named $name that rides the test's own PDO (so it sees the test transaction's rows) and throws a
 * QueryException for every update whose SQL $refuse accepts. Its transaction counter starts at 1, so its
 * transactions are savepoints inside the test transaction.
 */
function q1EdgeConnection(string $name, ?Closure $refuse = null): PostgresConnection
{
    $default = DB::connection();
    $connection = new class($default->getPdo(), $default->getDatabaseName(), '', ['name' => $name, 'driver' => 'pgsql'], $refuse) extends PostgresConnection
    {
        /** @param array<string, mixed> $config */
        public function __construct(PDO $pdo, string $database, string $prefix, array $config, private readonly ?Closure $refuse)
        {
            parent::__construct($pdo, $database, $prefix, $config);
            $this->transactions = 1;
        }

        public function update($query, $bindings = []): int
        {
            if ($this->refuse !== null && ($this->refuse)((string) $query)) {
                throw new QueryException((string) $this->getName(), (string) $query, $bindings, new RuntimeException('refused by the edge test'));
            }

            return parent::update($query, $bindings);
        }
    };
    $connection->setEventDispatcher(app('events'));

    return $connection;
}

/** Records the connection name of every statement run until the returned closure is called; it returns the names. */
function q1EdgeNames(): Closure
{
    $names = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$names): void {
        $names[] = $query->connectionName;
    });

    return function () use (&$names): array {
        Event::forget(QueryExecuted::class);

        return array_values(array_unique($names));
    };
}

it('rolls the whole outcome back when the endpoint-disable statement of the same transaction fails (disable branch)', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user);
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(fn () => Http::response('', 410));
    DB::unprepared("CREATE FUNCTION q1_edge_refuse_disable() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN RAISE EXCEPTION 'disable refused by the edge test'; END \$\$");
    DB::unprepared('CREATE TRIGGER q1_edge_refuse_disable BEFORE UPDATE OF disabled_at ON webhook_endpoints FOR EACH ROW EXECUTE FUNCTION q1_edge_refuse_disable()');

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    $row = DeliveryRows::row($id);
    $lease = $this->start->addSeconds(300)->format('Y-m-d\TH:i:s.u');
    // The 410 outcome (failed, last_status 410) must not be half-written next to a disable that did not happen.
    expect($summary['claimed'])->toBe(1)
        ->and($summary['failed'])->toBe(0)
        ->and([$row->status, $row->attempts, $row->last_status, $row->last_error, $row->next_at])->toBe(['pending', 0, null, null, $lease])
        ->and(DB::table('webhook_endpoints')->where('id', $endpoint)->value('disabled_at'))->toBeNull()
        ->and(AlertRows::logged('delivery_outcome_unrecorded'))->toHaveCount(1)
        ->and(AlertRows::logged('delivery_attempt'))->toBe([]);

    // Once the fault is gone the same 410 is recorded and the endpoint is turned off, together.
    DB::unprepared('DROP TRIGGER q1_edge_refuse_disable ON webhook_endpoints');
    $this->travel(300)->seconds();
    DeliveryRows::sweep();
    expect(DeliveryRows::row($id)->status)->toBe('failed')
        ->and(DB::table('webhook_endpoints')->where('id', $endpoint)->value('disabled_at'))->not->toBeNull();
});

it('puts nothing but the delivery id and the exception class into the logs when the outcome write fails (leak)', function (): void {
    $secret = 'whsec_'.base64_encode(str_repeat('Z', 32));
    $endpoint = DeliveryRows::endpoint($this->user, 'https://hooks.example.com/SENTINEL_PATH_c41b', $secret);
    $id = DeliveryRows::delivery($endpoint, ['body' => '{"type":"x","data":{"note":"SENTINEL_BODY_c41b"}}']);
    Http::fake(fn () => Http::response('SENTINEL_RESPONSE_c41b', 204));
    DB::unprepared("CREATE FUNCTION q1_edge_leak() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN IF NEW.attempts <> OLD.attempts THEN RAISE EXCEPTION 'SENTINEL_DBMSG_c41b'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER q1_edge_leak BEFORE UPDATE ON deliveries FOR EACH ROW EXECUTE FUNCTION q1_edge_leak()');
    Exceptions::fake();

    DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    $everything = json_encode(AlertRows::$logs, JSON_THROW_ON_ERROR);
    $warning = AlertRows::logged('delivery_outcome_unrecorded');
    expect($warning)->toHaveCount(1)
        ->and(array_keys($warning[0]['context']))->toBe(['delivery_id', 'exception'])
        ->and($warning[0]['context']['delivery_id'])->toBe($id)
        ->and($everything)->not->toContain('SENTINEL')
        ->and($everything)->not->toContain($secret)
        ->and($everything)->not->toContain('hooks.example.com')
        ->and($everything)->not->toContain('UPDATE deliveries');
    Exceptions::assertNothingReported();
});

it('still releases the rest of an endpoint after a timed-out send whose outcome could not be written (slow-endpoint rule)', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user);
    $rows = [DeliveryRows::delivery($endpoint), DeliveryRows::delivery($endpoint), DeliveryRows::delivery($endpoint)];
    $requests = 0;
    Http::fake(function () use (&$requests): never {
        $requests++;

        throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds');
    });
    DB::unprepared("CREATE FUNCTION q1_edge_refuse_first() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN IF OLD.id = {$rows[0]} AND NEW.attempts <> OLD.attempts THEN RAISE EXCEPTION 'refused'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER q1_edge_refuse_first BEFORE UPDATE ON deliveries FOR EACH ROW EXECUTE FUNCTION q1_edge_refuse_first()');

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    // One request only: the endpoint is slow whether or not its outcome was recorded.
    expect($requests)->toBe(1)
        ->and($summary['claimed'])->toBe(3)
        ->and($summary['released'])->toBe(2)
        ->and([DeliveryRows::row($rows[1])->claims, DeliveryRows::row($rows[1])->attempts])->toBe([0, 0])
        ->and([DeliveryRows::row($rows[2])->claims, DeliveryRows::row($rows[2])->attempts])->toBe([0, 0])
        ->and([DeliveryRows::row($rows[0])->claims, DeliveryRows::row($rows[0])->attempts])->toBe([1, 0]);
});

it('ends a row whose outcome write never succeeds once it has been claimed 2 x max_attempts times, and sends nothing more (bounded loop)', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user);
    $id = DeliveryRows::delivery($endpoint, ['claims' => 11]);
    Http::fake(fn () => Http::response('', 204));
    // Only an outcome (an attempts change) is refused; the retire statement of the next sweep is not.
    DB::unprepared("CREATE FUNCTION q1_edge_never() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN IF NEW.attempts <> OLD.attempts THEN RAISE EXCEPTION 'refused'; END IF; RETURN NEW; END \$\$");
    DB::unprepared('CREATE TRIGGER q1_edge_never BEFORE UPDATE ON deliveries FOR EACH ROW EXECUTE FUNCTION q1_edge_never()');

    $first = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $this->start);
    $this->travel(300)->seconds();
    $second = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), CarbonImmutable::now());

    $row = DeliveryRows::row($id);
    expect($first['claimed'])->toBe(1)
        ->and($second['retired'])->toBe(1)
        ->and($second['claimed'])->toBe(0)
        ->and([$row->status, $row->last_error, $row->claims])->toBe(['failed', 'internal', 12])
        ->and(Http::recorded())->toHaveCount(1);
});

it('writes nothing on the default connection when the outcome write fails on the sweep\'s own connection (b7, warning path)', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user);
    $id = DeliveryRows::delivery($endpoint);
    Http::fake(fn () => Http::response('', 204));
    $db = q1EdgeConnection('pgsql_q1_edge', fn (string $sql): bool => str_contains($sql, 'SET attempts = attempts'));
    $names = q1EdgeNames();

    $summary = DeliverDue::sweep($db, app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    $seen = $names();
    $row = DeliveryRows::row($id);
    expect($seen)->toBe(['pgsql_q1_edge'])
        ->and($summary['claimed'])->toBe(1)
        ->and($summary['delivered'])->toBe(0)
        ->and([$row->status, $row->attempts, $row->claims])->toBe(['pending', 0, 1])
        ->and(AlertRows::logged('delivery_outcome_unrecorded'))->toHaveCount(1);
});

it('runs a Discord 404 disable, the endpoint read and the outcome all on the sweep\'s connection (b7, disable path)', function (): void {
    FakeHostResolver::install(['discord.com' => ['198.51.100.7'], 'hooks.example.com' => ['203.0.113.10']]);
    $endpoint = AlertRows::endpoint($this->user, 'discord');
    $id = DeliveryRows::delivery($endpoint, ['channel' => 'discord', 'body' => '{"content":"x"}']);
    Http::fake(fn () => Http::response('', 404));
    $db = q1EdgeConnection('pgsql_q1_edge');
    $names = q1EdgeNames();

    $summary = DeliverDue::sweep($db, app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    $seen = $names();
    expect($seen)->toBe(['pgsql_q1_edge'])
        ->and($summary['failed'])->toBe(1)
        ->and(DeliveryRows::row($id)->status)->toBe('failed')
        ->and(DB::table('webhook_endpoints')->where('id', $endpoint)->value('disabled_at'))->not->toBeNull();
});

it('reads the address of an email row and records its outcome on the sweep\'s connection (b7, email path)', function (): void {
    Mail::fake();
    $id = (int) DB::table('deliveries')->insertGetId([
        'message_id' => 'msg_edge_email', 'user_id' => $this->user->id, 'kind' => 'price.moved', 'channel' => 'email',
        'endpoint_id' => null, 'body' => '{"subject":"Subject","text":"Text"}',
        'next_attempt_at' => $this->start->utc()->format('Y-m-d H:i:s.uP'),
    ]);
    $db = q1EdgeConnection('pgsql_q1_edge');
    $names = q1EdgeNames();

    $summary = DeliverDue::sweep($db, app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    $seen = $names();
    expect($seen)->toBe(['pgsql_q1_edge'])
        ->and($summary['delivered'])->toBe(1)
        ->and(DeliveryRows::row($id)->status)->toBe('delivered');
});

it('keeps the job timeout below retry_after, and the lease and the unique lock above it, so a stopped sweep cannot be re-run while its rows are leased', function (): void {
    $payload = null;
    BaseQueue::createPayloadUsing(function (?string $connection, ?string $queue, array $built) use (&$payload): array {
        $payload = $built;

        return [];
    });
    try {
        DeliverDue::dispatch();
    } finally {
        BaseQueue::createPayloadUsing(null);
    }
    $timeout = $payload['timeout'] ?? null;

    expect($timeout)->toBeInt()
        ->and($timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        ->and((new DeliverDue)->uniqueFor)->toBeGreaterThan($timeout)
        ->and((int) config('alerts.delivery.lease_seconds'))->toBeGreaterThan((int) config('queue.connections.redis.retry_after'))
        ->and((int) config('alerts.delivery.lease_seconds'))->toBe(300)
        ->and((int) config('alerts.delivery.batch'))->toBe(10)
        ->and((int) config('alerts.delivery.per_endpoint'))->toBe(3)
        ->and((int) config('alerts.delivery.max_attempts'))->toBe(6)
        ->and((int) config('alerts.delivery.sweep_budget_seconds'))->toBe(60);
});
