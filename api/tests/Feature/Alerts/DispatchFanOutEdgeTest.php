<?php

declare(strict_types=1);

use App\Domain\Alerts\Jobs\DispatchEvents;
use App\Domain\Alerts\PriceMovedPayload;
use App\Domain\Catalog\CatalogRepository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan Q1-i5 T06 review: the INSERT ... SELECT FROM (VALUES ...) shape must write exactly what the plain INSERT wrote
// (types, bytes, zones, chunks), skip only vanished rows (never a disabled endpoint), and leave a skipped delivery
// to the next run.

const T06E_VARIANT = 'base1-4:holo:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    AlertRows::runs('2026-10-11', '2026-10-12');
    $this->event = AlertRows::event(T06E_VARIANT, '2026-10-12');
});

afterEach(function (): void {
    Event::forget(RequestSending::class);
    CatalogCache::flush($this->catalogPrefix);
});

/** @return array<string, mixed> one dispatch run at the frozen now, with $gap run during the catalog read when given */
function t06eRun(?Closure $gap = null): array
{
    if ($gap !== null) {
        Event::listen(RequestSending::class, $gap);
    }
    $summary = DispatchEvents::run(DB::connection(), app(CatalogRepository::class), CarbonImmutable::now());
    Event::forget(RequestSending::class);

    return $summary;
}

/** An eligible watcher of the variant with a 5 % alert and the digest on the given channel. */
function t06eWatcher(string $email, string $channel = 'email'): User
{
    $user = AlertRows::user($email);
    AccountRows::watch($user, T06E_VARIANT);
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 5]);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => $channel, 'digest' => true]);

    return $user;
}

/**
 * One user watching the variant with $count standard endpoints, inserted in bulk; returns the endpoint ids in order.
 *
 * @return list<int>
 */
function t06eEndpoints(int $count): array
{
    $user = AlertRows::user('many@example.com');
    AccountRows::watch($user, T06E_VARIANT);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'user_id' => $user->id, 'kind' => 'standard', 'url' => AlertRows::URL, 'url_hint' => 'https://hooks.example.com/…',
            'secret' => AlertRows::secret(),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('webhook_endpoints')->insert($chunk);
    }

    return array_map('intval', DB::table('webhook_endpoints')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all());
}

it('writes every column of a created delivery the way the plain insert did, under a Chicago session zone and a hostile non-ASCII card name', function (): void {
    $name = 'Pikachu é 日本語 😀 ? :: $1 \' " \\ '.str_repeat('ü', 5000);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use ($name) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => AccountRows::SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }

        return Http::response(['data' => [['name' => $name] + AccountRows::card('base1-4')], 'missing' => [], 'seed' => $seed]);
    });
    DB::statement("SET TIME ZONE 'America/Chicago'");

    $user = t06eWatcher('a@example.com');
    $endpoint = AlertRows::endpoint($user);
    t06eRun();

    $rows = DB::select(
        'SELECT message_id, user_id, kind, channel, endpoint_id, event_id, day::text AS day, body, status, attempts, claims,
                extract(epoch FROM next_attempt_at)::bigint AS due, last_status, last_error, delivered_at, created_at IS NOT NULL AS stamped
         FROM deliveries ORDER BY kind',
    );
    $due = CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC')->getTimestamp();

    expect(count($rows))->toBe(3)
        ->and(array_map(fn (object $r): string => $r->kind.'/'.$r->channel, $rows))->toBe(['alert.triggered/email', 'digest/email', 'price.moved/webhook']);
    foreach ($rows as $row) {
        expect($row->message_id)->toMatch('/^msg_[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and((int) $row->user_id)->toBe((int) $user->id)
            ->and($row->day)->toBe('2026-10-12')
            ->and($row->status)->toBe('pending')
            ->and([(int) $row->attempts, (int) $row->claims])->toBe([0, 0])
            ->and((int) $row->due)->toBe($due)
            ->and([$row->last_status, $row->last_error, $row->delivered_at])->toBe([null, null, null])
            ->and($row->stamped)->toBeTrue();
    }
    $moved = $rows[2];
    $card = app(CatalogRepository::class)->cardNames(['base1-4'])['cards']['base1-4'];
    $event = DB::selectOne('SELECT '.PriceMovedPayload::COLUMNS.' FROM price_events e WHERE e.id = ?', [$this->event]);
    expect((int) $moved->endpoint_id)->toBe($endpoint)
        ->and((int) $moved->event_id)->toBe($this->event)
        ->and($rows[0]->endpoint_id)->toBeNull()
        ->and((int) $rows[0]->event_id)->toBe($this->event)
        ->and($rows[1]->endpoint_id)->toBeNull()
        ->and($rows[1]->event_id)->toBeNull()
        ->and($moved->body)->toContain('Pikachu é 日本語 😀')
        ->and($moved->body)->toContain("'")
        ->and($moved->body)->toBe(PriceMovedPayload::encode($event, $card))
        ->and(mb_check_encoding($moved->body, 'UTF-8'))->toBeTrue();
});

it('creates every row of a run that straddles the 500-row chunk, once, and a re-run creates none', function (int $count): void {
    $endpoints = t06eEndpoints($count);

    $first = t06eRun();
    $second = t06eRun();

    $ids = DB::table('deliveries')->where('kind', 'price.moved')->pluck('message_id')->all();
    expect($first['created']['price.moved'])->toBe($count)
        ->and(DB::table('deliveries')->where('kind', 'price.moved')->count())->toBe($count)
        ->and(count(array_unique($ids)))->toBe($count)
        ->and(array_map('intval', DB::table('deliveries')->orderBy('endpoint_id')->pluck('endpoint_id')->all()))->toBe($endpoints)
        ->and($second['created']['price.moved'])->toBe(0)
        ->and(DB::table('deliveries')->count())->toBe($count);
})->with([500, 501, 1000, 1001]);

it('skips an endpoint deleted in either chunk and counts only the rows it inserted', function (int $count): void {
    $endpoints = t06eEndpoints($count);
    $first = $endpoints[0];
    $last = $endpoints[$count - 1];

    $summary = t06eRun(function () use ($first, $last): void {
        DB::table('webhook_endpoints')->whereIn('id', [$first, $last])->delete();
    });

    expect($summary['created']['price.moved'])->toBe($count - 2)
        ->and(DB::table('deliveries')->count())->toBe($count - 2)
        ->and(DB::table('deliveries')->whereIn('endpoint_id', [$first, $last])->count())->toBe(0);
})->with([501, 1000]);

it('still creates the delivery of an endpoint disabled during the catalog read, as the plain insert did', function (): void {
    $user = AlertRows::user('a@example.com');
    AccountRows::watch($user, T06E_VARIANT);
    $disabled = AlertRows::endpoint($user);
    $kept = AlertRows::endpoint($user);

    $summary = t06eRun(function () use ($disabled): void {
        DB::table('webhook_endpoints')->where('id', $disabled)->update(['disabled_at' => '2026-10-15 12:00:00+00']);
    });

    expect(AlertRows::moved())->toBe([[$this->event, $disabled], [$this->event, $kept]])
        ->and($summary['created']['price.moved'])->toBe(2);
});

it('keeps a person\'s email alert and digest when only their signed endpoint vanished', function (): void {
    $user = t06eWatcher('a@example.com');
    $gone = AlertRows::endpoint($user);

    $summary = t06eRun(function () use ($gone): void {
        DB::table('webhook_endpoints')->where('id', $gone)->delete();
    });

    $rows = DB::select('SELECT kind, channel, endpoint_id FROM deliveries ORDER BY kind');
    expect(array_map(fn (object $r): string => $r->kind.'/'.$r->channel.'/'.($r->endpoint_id ?? 'null'), $rows))
        ->toBe(['alert.triggered/email/null', 'digest/email/null'])
        ->and($summary['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 1, 'digest' => 1]);
});

it('hands a skipped Discord alert and digest to the next run as email, losing nothing', function (): void {
    $user = t06eWatcher('a@example.com', 'discord');
    $gone = AlertRows::endpoint($user, 'discord');

    $first = t06eRun(function () use ($gone): void {
        DB::table('webhook_endpoints')->where('id', $gone)->delete();
    });
    $afterFirst = DB::table('deliveries')->count();
    $second = t06eRun();

    $rows = DB::select('SELECT kind, channel, endpoint_id FROM deliveries ORDER BY kind');
    expect($first['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and($afterFirst)->toBe(0)
        ->and($second['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 1, 'digest' => 1])
        ->and(array_map(fn (object $r): string => $r->kind.'/'.$r->channel, $rows))->toBe(['alert.triggered/email', 'digest/email']);
});

it('survives every endpoint and every person vanishing at once without an exception', function (): void {
    $a = t06eWatcher('a@example.com');
    AlertRows::endpoint($a);
    $b = t06eWatcher('b@example.com', 'discord');
    AlertRows::endpoint($b, 'discord');

    $summary = t06eRun(function () use ($a, $b): void {
        DB::table('users')->whereIn('id', [$a->id, $b->id])->delete();
    });

    expect($summary['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(DB::table('deliveries')->count())->toBe(0)
        ->and($summary['skipped'])->toBeNull();
});
