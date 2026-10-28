<?php

declare(strict_types=1);

use App\Domain\Alerts\NotificationText;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;
use Tests\Support\ForbiddenWords;

// Adversarial review of plan P5-i1 T06 (round 1): threshold and ordering semantics, the unique indexes under a
// simulated concurrent dispatch, the Discord 2000-character cut at its exact boundary, stale-event leakage through
// every delivery kind and channel, and the send-time email edges (malformed address, deleted account).

const T6R_APP = 'http://pokeprices.localhost:8095';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    config(['app.url' => T6R_APP]);
    DeliveryRows::jitter('min');
    FakeHostResolver::install(['discord.com' => ['198.51.100.7']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** A person on the email channel (or the given one) with the digest as given and no threshold yet. */
function t6rPerson(string $email, bool $digest = false, string $channel = 'email'): User
{
    $user = AlertRows::user($email);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => $channel, 'digest' => $digest]);

    return $user;
}

/** Sets $pct on the person's watchlist row of $key (adding the row when missing). */
function t6rWatch(User $user, string $key, ?int $pct): void
{
    AccountRows::watch($user, $key);
    DB::table('watchlist_items')->where('user_id', $user->id)->where('variant_key', $key)->update(['alert_pct' => $pct]);
}

/**
 * All deliveries of $kind as [user_id, event_id], ordered.
 *
 * @return list<array{0: int, 1: int}>
 */
function t6rAlerts(): array
{
    return array_map(
        fn (object $r): array => [(int) $r->user_id, (int) $r->event_id],
        DB::select("SELECT user_id, event_id FROM deliveries WHERE kind = 'alert.triggered' ORDER BY user_id, event_id"),
    );
}

/** @return list<string> the digest's text lines of the person's digest (header, move lines, more line, footnote) */
function t6rDigestLines(User $user): array
{
    $body = json_decode((string) DB::table('deliveries')->where('user_id', $user->id)->where('kind', 'digest')->value('body'), true);

    return explode("\n", (string) ($body['text'] ?? $body['content']));
}

/** A price_events-shaped row for direct rendering. */
function t6rRow(string $key, string $pct, int $id): stdClass
{
    $negative = str_starts_with($pct, '-');

    return (object) [
        'id' => $id, 'variant_key' => $key, 'card_id' => explode(':', $key)[0], 'direction' => $negative ? 'down' : 'up',
        'close' => '1234.50', 'previous_close' => '1000.00', 'change' => $negative ? '-234.50' : '234.50', 'change_pct' => $pct,
        'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z', 'previous_day' => '2026-10-11',
        'previous_as_of' => '2026-10-11T20:05:45Z', 'series_epoch' => 1, 'rule' => 'draft-v1', 'source' => 'tcgplayer',
    ];
}

it('alerts at exactly the threshold on the absolute change, per variant and per person, never across variants or people', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $up = AlertRows::event('base1-4:holo:-:-', '2026-10-12', ['change_pct' => '10.00']);
    $down = AlertRows::event('base1-2:holo:-:-', '2026-10-12', ['direction' => 'down', 'close' => '9.00', 'change' => '-1.00', 'change_pct' => '-10.00']);
    $below = AlertRows::event('base1-7:holo:-:-', '2026-10-12', ['change_pct' => '9.99']);

    $exact = t6rPerson('exact@example.com');
    t6rWatch($exact, 'base1-4:holo:-:-', 10);
    t6rWatch($exact, 'base1-2:holo:-:-', 10);
    t6rWatch($exact, 'base1-7:holo:-:-', 10);
    // T05 only accepts 5 to 100, but the table allows 1 to 100: a 3 set directly must alert on this 10 % move and
    // must not leak onto the person's other watched variants, which carry no threshold.
    $directThree = t6rPerson('three@example.com');
    t6rWatch($directThree, 'base1-4:holo:-:-', 3);
    t6rWatch($directThree, 'base1-2:holo:-:-', null);
    $eleven = t6rPerson('eleven@example.com');
    t6rWatch($eleven, 'base1-4:holo:-:-', 11);

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and(t6rAlerts())->toBe([[$exact->id, $up], [$exact->id, $down], [$directThree->id, $up]])
        ->and(array_column(t6rAlerts(), 1))->not->toContain($below)
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 3, 'digest' => 0]);
});

it('orders a digest by the absolute size of the change, compared as decimals, and counts a variant on both lists once', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event('base1-1:holo:-:-', '2026-10-12', ['change_pct' => '20.00']);
    AlertRows::event('base1-2:holo:-:-', '2026-10-12', ['direction' => 'down', 'change' => '-5.00', 'change_pct' => '-30.00']);
    AlertRows::event('base1-3:holo:-:-', '2026-10-12', ['change_pct' => '99.99']);
    AlertRows::event('base1-5:holo:-:-', '2026-10-12', ['change_pct' => '100.00']);
    AlertRows::event('base1-6:holo:-:-', '2026-10-12', ['change_pct' => '9.50']);
    $reader = t6rPerson('reader@example.com', true);
    AccountRows::watch($reader, 'base1-1:holo:-:-');
    AccountRows::watch($reader, 'base1-2:holo:-:-');
    foreach (['base1-2', 'base1-3', 'base1-5', 'base1-6'] as $id) {
        AccountRows::hold($reader, "{$id}:holo:-:-", 1);
    }

    AlertRows::dispatch();
    $lines = t6rDigestLines($reader);
    $labels = array_map(fn (string $l): string => explode(' · ', $l)[0], array_slice($lines, 1, -1));

    expect($labels)->toBe(['Name of base1-5', 'Name of base1-3', 'Name of base1-2', 'Name of base1-1', 'Name of base1-6'])
        ->and(DB::table('deliveries')->where('kind', 'digest')->count())->toBe(1);
});

it('creates each alert and digest once when a concurrent dispatch inserts the same rows between the read and the write', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $event = AlertRows::event('base1-4:holo:-:-', '2026-10-12');
    $user = t6rPerson('racer@example.com', true);
    t6rWatch($user, 'base1-4:holo:-:-', 5);

    $injected = ['alert' => false, 'digest' => false];
    DB::listen(function (QueryExecuted $query) use (&$injected, $user, $event): void {
        $kind = null;
        if (! $injected['alert'] && str_contains($query->sql, 'abs(e.change_pct) >= w.alert_pct')) {
            $injected['alert'] = true;
            $kind = 'alert.triggered';
        } elseif (! $injected['digest'] && str_contains($query->sql, "x.kind = 'digest'")) {
            $injected['digest'] = true;
            $kind = 'digest';
        }
        if ($kind !== null) {
            DB::table('deliveries')->insert([
                'message_id' => 'msg_RACE'.$kind[0].'0000000000000000000', 'user_id' => $user->id, 'kind' => $kind, 'channel' => 'email',
                'endpoint_id' => null, 'event_id' => $kind === 'digest' ? null : $event, 'day' => '2026-10-12',
                'body' => '{"subject":"racer","text":"racer"}', 'next_attempt_at' => '2026-10-15 12:00:00+00',
            ]);
        }
    });

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and($injected)->toBe(['alert' => true, 'digest' => true])
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and(DB::table('deliveries')->where('kind', 'alert.triggered')->count())->toBe(1)
        ->and(DB::table('deliveries')->where('kind', 'digest')->count())->toBe(1);
});

it('keeps a Discord digest at exactly 2000 characters whole and cuts at 2001 with an accurate more line, counting characters not bytes', function (): void {
    $moves = [];
    for ($i = 1; $i <= 15; $i++) {
        $moves[] = ['event' => t6rRow("base1-{$i}:holo:-:-", sprintf('%d.00', 10 + $i), $i), 'card' => ['name' => 'Dracaufeu é', 'set_name' => 'S', 'number' => (string) $i]];
    }
    $base = mb_strlen(NotificationText::digest('2026-10-12', $moves, 25)['text']);
    expect($base)->toBeLessThan(2000);

    // Lowest-ranked move gets the padding, so it is the line the cut drops.
    $fit = $moves;
    $fit[0]['card']['name'] .= str_repeat('é', 2000 - $base);
    $over = $moves;
    $over[0]['card']['name'] .= str_repeat('é', 2001 - $base);

    $whole = NotificationText::discordDigest('2026-10-12', $fit);
    $cut = NotificationText::discordDigest('2026-10-12', $over);
    $cutLines = explode("\n", $cut);

    expect(mb_strlen($whole))->toBe(2000)
        ->and($whole)->not->toContain('…and')
        ->and(count(explode("\n", $whole)))->toBe(17)
        ->and(mb_strlen($cut))->toBeLessThanOrEqual(2000)
        ->and($cutLines[count($cutLines) - 2])->toBe('…and 1 more: '.T6R_APP.'/watchlist')
        ->and(end($cutLines))->toBe(NotificationText::FOOTNOTE)
        ->and(count($cutLines))->toBe(17)
        ->and($cutLines[0])->toBe('Cards on your watchlist and portfolio that moved by at least 5% and $1.00 between two daily closes, as of 2026-10-12 from TCGCSV (daily close):');
});

it('keeps the as-of header, the footnote and an exact remainder in a Discord digest of very long names, all eight forbidden patterns clean', function (): void {
    $moves = [];
    for ($i = 1; $i <= 25; $i++) {
        $moves[] = ['event' => t6rRow("base1-{$i}:holo:-:-", sprintf('%d.00', 10 + $i), $i), 'card' => ['name' => str_repeat('Carapuce ', 16).$i, 'set_name' => 'S', 'number' => (string) $i]];
    }

    $text = NotificationText::discordDigest('2026-10-12', $moves);
    $lines = explode("\n", $text);
    $shown = array_slice($lines, 1, -2);
    $more = $lines[count($lines) - 2];

    expect(mb_strlen($text))->toBeLessThanOrEqual(2000)
        ->and($lines[0])->toEndWith('as of 2026-10-12 from TCGCSV (daily close):')
        ->and(end($lines))->toBe(NotificationText::FOOTNOTE)
        ->and(count($shown))->toBeGreaterThan(5)->toBeLessThan(25)
        ->and($more)->toBe('…and '.(25 - count($shown)).' more: '.T6R_APP.'/watchlist')
        ->and($shown[0])->toStartWith(str_repeat('Carapuce ', 16).'25 · holo: ')
        ->and(array_filter($shown, fn (string $l): bool => ! str_ends_with($l, 'vs previous close (2026-10-11)')))->toBe([])
        ->and(ForbiddenWords::textMatches($text))->toBe([]);
});

it('yields no alert and no digest, on any channel, for events above the ceiling, a future day, a repaired older day or a regressed watermark', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $known = AlertRows::event('base1-4:holo:-:-', '2026-10-12');
    $first = t6rPerson('first@example.com', true);
    t6rWatch($first, 'base1-4:holo:-:-', 5);
    AlertRows::dispatch();
    $before = [DB::table('deliveries')->orderBy('id')->pluck('id')->all(), t6rAlerts()];

    // An event written to the already dispatched day, held only on a portfolio, only for a digest, on email and Discord.
    $added = AlertRows::event('base1-2:holo:-:-', '2026-10-12');
    $emailHolder = t6rPerson('holder@example.com', true);
    AccountRows::hold($emailHolder, 'base1-2:holo:-:-', 3);
    $discordHolder = t6rPerson('discord@example.com', true, 'discord');
    AlertRows::endpoint($discordHolder, 'discord');
    AccountRows::hold($discordHolder, 'base1-2:holo:-:-', 1);
    $discordWatcher = t6rPerson('discord-watch@example.com', true, 'discord');
    AlertRows::endpoint($discordWatcher, 'discord');
    t6rWatch($discordWatcher, 'base1-2:holo:-:-', 5);

    // A future-dated finalized day, with an event and a holder.
    AlertRows::run('2026-10-20');
    DB::table('dispatch_days')->insert(['source' => 'tcgplayer', 'day' => '2026-10-20', 'event_id_ceiling' => null]);
    $future = AlertRows::event('base1-7:holo:-:-', '2026-10-20');
    AccountRows::hold($emailHolder, 'base1-7:holo:-:-', 1);
    AccountRows::hold($discordHolder, 'base1-7:holo:-:-', 1);

    $created = [];
    foreach ([1, 2] as $run) {
        [, $line] = AlertRows::dispatch();
        $created[] = $line['created'];
    }

    // A repair re-opens the older days, with a new subscriber whose only watched variant moved on an older day.
    AlertRows::supersedeAll();
    AlertRows::run('2026-10-10');
    $older = AlertRows::event('base1-9:holo:-:-', '2026-10-10');
    $late = t6rPerson('late@example.com', true);
    t6rWatch($late, 'base1-9:holo:-:-', 5);
    [, $repairing] = AlertRows::dispatch();
    AlertRows::run('2026-10-11');
    AlertRows::event('base1-9:holo:-:-', '2026-10-11');
    [, $repairingTwo] = AlertRows::dispatch();

    $none = ['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0];
    expect($created)->toBe([$none, $none])
        ->and($repairing['created'])->toBe($none)
        ->and($repairingTwo['created'])->toBe($none)
        ->and(DB::table('deliveries')->orderBy('id')->pluck('id')->all())->toBe($before[0])
        ->and(t6rAlerts())->toBe($before[1])
        ->and(array_column(t6rAlerts(), 1))->toBe([$known])
        ->and(DB::table('deliveries')->whereIn('user_id', [$emailHolder->id, $discordHolder->id, $discordWatcher->id, $late->id])->count())->toBe(0)
        ->and([$added, $future, $older])->each->toBeGreaterThan($known);
});

it('contains a malformed stored address as an internal retry and leaks neither the address nor the exception text', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event('base1-4:holo:-:-', '2026-10-12');
    $user = t6rPerson('sound@example.com');
    t6rWatch($user, 'base1-4:holo:-:-', 5);
    AlertRows::dispatch();
    $id = (int) DB::table('deliveries')->where('kind', 'alert.triggered')->value('id');
    DB::table('users')->where('id', $user->id)->update(['email' => 'SENTINEL-ADDR <broken@@example.com']);

    Exceptions::fake();
    [$code, $summary] = DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    $all = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES).json_encode(DB::table('failed_jobs')->get());
    expect($code)->toBe(0)
        ->and($summary)->toMatchArray(['claimed' => 1, 'retry' => 1])
        ->and([$row->status, $row->attempts, $row->last_error])->toBe(['pending', 1, 'internal'])
        ->and($all)->not->toContain('SENTINEL-ADDR')
        ->and($all)->not->toContain('broken@@')
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertNothingReported();
});

it('drops a deleted person\'s pending messages and sends nothing; an account flipped to demo or unverified after fan-out is still mailed at its current address', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event('base1-4:holo:-:-', '2026-10-12');
    $gone = t6rPerson('gone@example.com', true);
    t6rWatch($gone, 'base1-4:holo:-:-', 5);
    $flipped = t6rPerson('flipped@example.com', true);
    t6rWatch($flipped, 'base1-4:holo:-:-', 5);
    AlertRows::dispatch();
    expect(DB::table('deliveries')->count())->toBe(4);

    DB::table('users')->where('id', $gone->id)->delete();
    DB::table('users')->where('id', $flipped->id)->update(['email_verified_at' => null, 'is_demo' => true]);
    [$code, $summary] = DeliveryRows::sweep();

    $transport = Mail::mailer()->getSymfonyTransport();
    $recipients = $transport->messages()->map(fn ($sent): array => array_map(fn ($a): string => $a->getAddress(), $sent->getOriginalMessage()->getTo()))->flatten()->unique()->values()->all();
    expect($code)->toBe(0)
        ->and(DB::table('deliveries')->where('user_id', $gone->id)->count())->toBe(0)
        ->and($summary)->toMatchArray(['claimed' => 2, 'delivered' => 2])
        ->and($recipients)->toBe(['flipped@example.com']);
});
