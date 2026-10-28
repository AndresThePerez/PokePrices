<?php

declare(strict_types=1);

use App\Domain\Alerts\DeliverySender;
use App\Domain\Alerts\Jobs\DeliverDue;
use App\Domain\Alerts\RetryPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\Support\AlertRows;
use Tests\Support\DeliveryRows;

// Plan Q1-i1 T01 (review b: B2, b1, B9, b7, gzip): the delivery sweep is bounded and contains every per-row failure.
// The job's own timeout sits below the queue's retry_after, an SMTP peer cannot hold a send for a minute, a failed
// outcome write leaves its row leased and the sweep goes on, every statement runs on the connection the sweep was
// given, and a gzip answer is never decompressed.

beforeEach(function (): void {
    DeliveryRows::resolver();
    DeliveryRows::jitter('max');
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->start = CarbonImmutable::now();
    $this->user = AlertRows::user('containment@example.com');
});

it('records the rest of the sweep when one row\'s outcome cannot be written, and leaves that row leased (B9)', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user);
    $rows = [DeliveryRows::delivery($endpoint), DeliveryRows::delivery($endpoint), DeliveryRows::delivery($endpoint)];
    $broken = $rows[1];
    // The claim UPDATE (claims, next_attempt_at) passes; the outcome UPDATE of the second row (attempts or status) fails.
    DB::unprepared("CREATE FUNCTION q1_t01_refuse_outcome() RETURNS trigger LANGUAGE plpgsql AS \$\$
        BEGIN
            IF OLD.id = {$broken} AND (NEW.attempts <> OLD.attempts OR NEW.status <> OLD.status) THEN
                RAISE EXCEPTION 'outcome write refused by the test';
            END IF;
            RETURN NEW;
        END \$\$");
    DB::unprepared('CREATE TRIGGER q1_t01_refuse_outcome BEFORE UPDATE ON deliveries FOR EACH ROW EXECUTE FUNCTION q1_t01_refuse_outcome()');
    // A fresh response per request: the sender closes each body it drains.
    Http::fake(fn () => Http::response('', 204));

    $summary = DeliverDue::sweep(DB::connection(), app(DeliverySender::class), app(RetryPolicy::class), $this->start);

    $lease = $this->start->addSeconds(300)->format('Y-m-d\TH:i:s.u');
    $leased = DeliveryRows::row($broken);
    $warnings = AlertRows::logged('delivery_outcome_unrecorded');
    expect($summary)->toBe(['retired' => 0, 'claimed' => 3, 'delivered' => 2, 'retry' => 0, 'failed' => 0, 'released' => 0])
        ->and(Http::recorded())->toHaveCount(3)
        ->and([DeliveryRows::row($rows[0])->status, DeliveryRows::row($rows[0])->attempts])->toBe(['delivered', 1])
        ->and([DeliveryRows::row($rows[2])->status, DeliveryRows::row($rows[2])->attempts])->toBe(['delivered', 1])
        ->and([$leased->status, $leased->attempts, $leased->claims, $leased->next_at])->toBe(['pending', 0, 1, $lease])
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['level'])->toBe('warning')
        ->and($warnings[0]['context'])->toBe(['delivery_id' => $broken, 'exception' => 'QueryException'])
        ->and(array_column(array_column(AlertRows::logged('delivery_attempt'), 'context'), 'delivery_id'))->toBe([$rows[0], $rows[2]])
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    // The leased row is claimed again once its lease runs out, and sent with the same webhook-id.
    DB::unprepared('DROP TRIGGER q1_t01_refuse_outcome ON deliveries');
    $this->travel(300)->seconds();
    [, $next] = DeliveryRows::sweep();
    expect($next['delivered'])->toBe(1)
        ->and(Http::recorded()[3][0]->header('webhook-id'))->toBe([$leased->message_id])
        ->and([DeliveryRows::row($broken)->status, DeliveryRows::row($broken)->attempts, DeliveryRows::row($broken)->claims])->toBe(['delivered', 1, 2]);
});

it('gives the sweep job a timeout above its send budget and below the queue retry_after, and fails it on timeout (B2)', function (): void {
    $payload = null;
    BaseQueue::createPayloadUsing(function (?string $connection, ?string $queue, array $built) use (&$payload): array {
        if (($built['displayName'] ?? null) === DeliverDue::class) {
            $payload = $built;
        }

        return [];
    });
    Http::fake(['*' => Http::response('', 204)]);

    try {
        DeliverDue::dispatch();
    } finally {
        BaseQueue::createPayloadUsing(null);
    }

    expect($payload)->toBeArray()
        ->and($payload['timeout'])->toBeInt()
        ->and($payload['timeout'])->toBeGreaterThan((int) config('alerts.delivery.sweep_budget_seconds'))
        ->and($payload['timeout'])->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($payload['failOnTimeout'])->toBeTrue()
        ->and((int) config('alerts.delivery.sweep_budget_seconds'))->toBe(60);
});

it('bounds an SMTP peer to a few seconds, in the config and on the transport the mailer builds (b1)', function (): void {
    $timeout = config('mail.mailers.smtp.timeout');
    $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
    assert($transport instanceof SmtpTransport);
    $stream = $transport->getStream();
    assert($stream instanceof SocketStream);

    expect($timeout)->toBeInt()
        ->and($timeout)->toBeGreaterThan(0)
        ->and($timeout)->toBeLessThanOrEqual(10)
        ->and($stream->getTimeout())->toBe((float) $timeout);
});

it('runs every statement of a sweep, the outcome write included, on the connection it was given (b7)', function (): void {
    config(['database.connections.pgsql_q1_sweep' => config('database.connections.pgsql')]);
    $sweepDb = DB::connection('pgsql_q1_sweep');
    // Committed on the named connection, outside the test's RefreshDatabase transaction, due in 2099 so no other
    // test's sweep can claim them; removed in the finally block.
    $at = CarbonImmutable::parse('2099-06-01 00:01:00', 'UTC');
    $this->travelTo($at);
    $email = 'q1-t01-'.Str::lower(Str::random(12)).'@q1-t01.example.com';
    $userId = null;
    $id = null;
    $names = [];
    $defaultTouched = false;

    try {
        $userId = (int) $sweepDb->table('users')->insertGetId([
            'email' => $email, 'password' => 'not-a-password-hash', 'email_verified_at' => '2026-10-01 00:00:00+00',
        ]);
        $endpointId = (int) $sweepDb->table('webhook_endpoints')->insertGetId([
            'user_id' => $userId, 'kind' => 'standard', 'url' => Crypt::encryptString(AlertRows::URL),
            'url_hint' => 'https://hooks.example.com/…', 'secret' => Crypt::encryptString(AlertRows::secret()),
        ]);
        $id = (int) $sweepDb->table('deliveries')->insertGetId([
            'message_id' => 'msg_'.Str::ulid(), 'user_id' => $userId, 'kind' => 'webhook.test', 'channel' => 'webhook',
            'endpoint_id' => $endpointId, 'body' => '{}', 'next_attempt_at' => '2099-06-01 00:00:00+00',
        ]);
        Http::fake(['*' => Http::response('', 204)]);
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$names, &$defaultTouched): void {
            $names[$query->connectionName] = true;
            if ($query->connectionName !== 'pgsql_q1_sweep' && str_contains($query->sql, 'deliveries')) {
                $defaultTouched = true;
            }
        });

        $summary = DeliverDue::sweep($sweepDb, app(DeliverySender::class), app(RetryPolicy::class), $at);
        Event::forget(QueryExecuted::class);

        $row = $sweepDb->selectOne('SELECT status, attempts, claims FROM deliveries WHERE id = ?', [$id]);
        expect(array_keys($names))->toBe(['pgsql_q1_sweep'])
            ->and($summary['claimed'])->toBe(1)
            ->and($summary['delivered'])->toBe(1)
            ->and(Http::recorded())->toHaveCount(1)
            ->and([$row->status, $row->attempts, $row->claims])->toBe(['delivered', 1, 1]);
    } finally {
        Event::forget(QueryExecuted::class);
        if ($defaultTouched && DB::transactionLevel() > 0) {
            // A write on the default connection holds the row lock inside the test transaction; end it so the
            // cleanup below cannot wait on it (the next test then migrates afresh).
            DB::rollBack(0);
        }
        if ($userId !== null) {
            $sweepDb->table('users')->where('id', $userId)->delete();
        }
        $left = [
            (int) $sweepDb->table('users')->where('email', $email)->count(),
            (int) $sweepDb->table('deliveries')->where('id', $id ?? 0)->count(),
        ];
        DB::purge('pgsql_q1_sweep');
    }

    expect($left)->toBe([0, 0]);
});

it('asks for an undecoded body, so a gzip answer is drained by its wire bytes and never inflated (gzip)', function (): void {
    $endpoint = DeliveryRows::endpoint($this->user);
    $id = DeliveryRows::delivery($endpoint);
    $compressed = (string) gzencode(random_bytes(70_000));
    $seen = [];
    Http::fake(function (ClientRequest $request, array $options) use (&$seen, $compressed) {
        $seen = $options;

        return Http::response($compressed, 200, ['Content-Encoding' => 'gzip', 'Content-Type' => 'application/json']);
    });

    [, $summary] = DeliveryRows::sweep();

    expect(strlen($compressed))->toBeGreaterThan(65_536)
        ->and($seen)->toHaveKey('decode_content')
        ->and($seen['decode_content'])->toBeFalse()
        ->and($summary['delivered'])->toBe(1)
        ->and([DeliveryRows::row($id)->status, DeliveryRows::row($id)->last_status])->toBe(['delivered', 200]);
});
