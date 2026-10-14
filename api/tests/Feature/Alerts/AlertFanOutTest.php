<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;

// Plan P5-i1 T06 criteria 1, 2, 3 and 7 (D3, D8): who gets an alert.triggered (the watchlist row's own threshold,
// absolute change, >=), who gets the one daily digest (watchlist and portfolio, no event no digest), on which
// channel (email, or the active Discord endpoint at fan-out time), once however often the dispatcher runs, and the
// command's created counts.

const T6F_A = 'base1-4:holo:-:-';
const T6F_B = 'base1-2:holo:-:-';
const T6F_C = 'base1-7:holo:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    config(['app.url' => 'http://pokeprices.localhost:8095']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/**
 * A user watching $key at $pct (null: no threshold), with the given settings row (null: none) and attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function t6fWatcher(string $email, string $key, ?int $pct, ?array $settings = null, array $attributes = []): User
{
    $user = AlertRows::user($email, $attributes);
    AccountRows::watch($user, $key);
    DB::table('watchlist_items')->where('user_id', $user->id)->where('variant_key', $key)->update(['alert_pct' => $pct]);
    if ($settings !== null) {
        DB::table('notification_settings')->insert(['user_id' => $user->id] + $settings);
    }

    return $user;
}

/**
 * Deliveries of $kind as [user_id, event_id or day, channel, endpoint_id], ordered.
 *
 * @return list<array{0: int, 1: int|string|null, 2: string, 3: int|null}>
 */
function t6fRows(string $kind): array
{
    $rows = DB::select('SELECT user_id, event_id, day::text AS day, channel, endpoint_id FROM deliveries WHERE kind = ? ORDER BY user_id, event_id, day', [$kind]);

    return array_map(fn (object $r): array => [
        (int) $r->user_id,
        $kind === 'digest' ? $r->day : ($r->event_id === null ? null : (int) $r->event_id),
        (string) $r->channel,
        $r->endpoint_id === null ? null : (int) $r->endpoint_id,
    ], $rows);
}

/** @return list<string> the digest's move lines (between the header and the footnote) */
function t6fDigestLines(User $user): array
{
    $body = json_decode((string) DB::table('deliveries')->where('user_id', $user->id)->where('kind', 'digest')->value('body'), true);
    $lines = explode("\n", (string) $body['text']);

    return array_slice($lines, 1, count($lines) - 2);
}

it('alerts a watcher whose threshold the absolute change reaches, and nobody else', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $up = AlertRows::event(T6F_A, '2026-10-12', ['change_pct' => '12.30']);
    $down = AlertRows::event(T6F_B, '2026-10-12', ['direction' => 'down', 'close' => '7.70', 'change' => '-2.30', 'change_pct' => '-12.30']);

    $ten = t6fWatcher('ten@example.com', T6F_A, 10);
    $fifteen = t6fWatcher('fifteen@example.com', T6F_A, 15);
    $twelve = t6fWatcher('twelve@example.com', T6F_A, 12);
    $off = t6fWatcher('off@example.com', T6F_A, null);
    $downTen = t6fWatcher('down@example.com', T6F_B, 10);
    $holder = AlertRows::user('holder@example.com');
    AccountRows::hold($holder, T6F_A, 1);
    $unverified = t6fWatcher('unverified@example.com', T6F_A, 5, null, ['email_verified_at' => null]);
    $demo = t6fWatcher('demo@example.com', T6F_A, 5, ['channel' => 'email', 'digest' => true], ['is_demo' => true]);

    [$code, $line] = AlertRows::dispatch();

    expect($code)->toBe(0)
        ->and(t6fRows('alert.triggered'))->toBe([
            [$ten->id, $up, 'email', null],
            [$twelve->id, $up, 'email', null],
            [$downTen->id, $down, 'email', null],
        ])
        ->and($line['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 3, 'digest' => 0])
        ->and(DB::table('deliveries')->whereIn('user_id', [$fifteen->id, $off->id, $holder->id, $unverified->id, $demo->id])->count())->toBe(0);
});

it('creates one alert per person and event over three runs, and a threshold set between runs gets its own', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(T6F_A, '2026-10-12');
    $first = t6fWatcher('first@example.com', T6F_A, 5);
    $late = t6fWatcher('late@example.com', T6F_A, null);

    $created = [];
    foreach ([1, 2] as $run) {
        [, $line] = AlertRows::dispatch();
        $created[] = $line['created']['alert.triggered'];
    }
    DB::table('watchlist_items')->where('user_id', $late->id)->update(['alert_pct' => 20]);
    [, $line] = AlertRows::dispatch();
    $created[] = $line['created']['alert.triggered'];

    expect($created)->toBe([1, 0, 1])
        ->and(t6fRows('alert.triggered'))->toBe([[$first->id, $event, 'email', null], [$late->id, $event, 'email', null]]);
});

it('sends one digest per person and day over watchlist and portfolio, a variant on both counted once', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(T6F_A, '2026-10-12');
    AlertRows::event(T6F_B, '2026-10-12');
    AlertRows::event(T6F_C, '2026-10-12');
    AlertRows::event(T6F_C, '2026-10-11');

    $reader = t6fWatcher('reader@example.com', T6F_A, null, ['channel' => 'email', 'digest' => true]);
    AccountRows::hold($reader, T6F_A, 2);
    AccountRows::hold($reader, T6F_B, 1);
    AccountRows::watch($reader, T6F_C);
    $noDigest = t6fWatcher('nodigest@example.com', T6F_A, null, ['channel' => 'email', 'digest' => false]);
    $noRow = t6fWatcher('norow@example.com', T6F_A, null);
    $nothingMoved = t6fWatcher('quiet@example.com', 'base1-9:holo:-:-', null, ['channel' => 'email', 'digest' => true]);
    $unverified = t6fWatcher('unverified@example.com', T6F_A, null, ['channel' => 'email', 'digest' => true], ['email_verified_at' => null]);

    $created = [];
    foreach ([1, 2, 3] as $run) {
        [, $line] = AlertRows::dispatch();
        $created[] = $line['created']['digest'];
    }

    expect($created)->toBe([1, 0, 0])
        ->and(t6fRows('digest'))->toBe([[$reader->id, '2026-10-12', 'email', null]])
        ->and(t6fDigestLines($reader))->toHaveCount(3)
        ->and(DB::table('deliveries')->whereIn('user_id', [$noDigest->id, $noRow->id, $nothingMoved->id, $unverified->id])->count())->toBe(0);
});

it('lists at most 25 moves in a digest and points to the watchlist for the rest', function (): void {
    AlertRows::runs('2026-10-12');
    $reader = AlertRows::user('reader@example.com');
    DB::table('notification_settings')->insert(['user_id' => $reader->id, 'channel' => 'email', 'digest' => true]);
    for ($i = 1; $i <= 30; $i++) {
        $key = "base1-{$i}:holo:-:-";
        AlertRows::event($key, '2026-10-12', ['change_pct' => sprintf('%d.00', 10 + $i)]);
        AccountRows::watch($reader, $key);
    }

    AlertRows::dispatch();
    $lines = t6fDigestLines($reader);

    expect($lines)->toHaveCount(26)
        ->and($lines[0])->toStartWith('Name of base1-30 · holo: ')
        ->and($lines[24])->toStartWith('Name of base1-6 · holo: ')
        ->and($lines[25])->toBe('…and 5 more: http://pokeprices.localhost:8095/watchlist');
});

it('chooses the channel at fan-out time: email, the active Discord endpoint, or email when it was turned off', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(T6F_A, '2026-10-12');
    $digest = ['digest' => true];
    $noRow = t6fWatcher('norow@example.com', T6F_A, 5);
    $email = t6fWatcher('email@example.com', T6F_A, 5, ['channel' => 'email'] + $digest);
    $discord = t6fWatcher('discord@example.com', T6F_A, 5, ['channel' => 'discord'] + $digest);
    $discordEndpoint = AlertRows::endpoint($discord, 'discord');
    AlertRows::endpoint($discord, 'standard');
    $turnedOff = t6fWatcher('off@example.com', T6F_A, 5, ['channel' => 'discord'] + $digest);
    $offEndpoint = AlertRows::endpoint($turnedOff, 'discord');
    DB::table('webhook_endpoints')->where('id', $offEndpoint)->update(['disabled_at' => '2026-10-13 00:00:00+00']);
    $emailWithDiscord = t6fWatcher('both@example.com', T6F_A, 5, ['channel' => 'email'] + $digest);
    AlertRows::endpoint($emailWithDiscord, 'discord');

    AlertRows::dispatch();

    expect(t6fRows('alert.triggered'))->toBe([
        [$noRow->id, $event, 'email', null],
        [$email->id, $event, 'email', null],
        [$discord->id, $event, 'discord', $discordEndpoint],
        [$turnedOff->id, $event, 'email', null],
        [$emailWithDiscord->id, $event, 'email', null],
    ])
        ->and(t6fRows('digest'))->toBe([
            [$email->id, '2026-10-12', 'email', null],
            [$discord->id, '2026-10-12', 'discord', $discordEndpoint],
            [$turnedOff->id, '2026-10-12', 'email', null],
            [$emailWithDiscord->id, '2026-10-12', 'email', null],
        ]);
});

it('stores an email message without the address, and a Discord message as the D4 envelope of the same text', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(T6F_A, '2026-10-12');
    $mail = t6fWatcher('mail-reader@example.com', T6F_A, 5, ['channel' => 'email', 'digest' => true]);
    $discord = t6fWatcher('discord-reader@example.com', T6F_A, 5, ['channel' => 'discord', 'digest' => true]);
    AlertRows::endpoint($discord, 'discord');

    AlertRows::dispatch();
    $bodies = fn (User $u): array => DB::table('deliveries')->where('user_id', $u->id)->orderBy('kind')->pluck('body', 'kind')->all();
    $emailBodies = $bodies($mail);
    $discordBodies = $bodies($discord);

    foreach (['alert.triggered', 'digest'] as $kind) {
        $message = json_decode($emailBodies[$kind], true);
        $envelope = json_decode($discordBodies[$kind], true);
        expect(array_keys($message))->toBe(['subject', 'text'])
            ->and($emailBodies[$kind])->not->toContain('mail-reader')
            ->and(array_keys($envelope))->toBe(['content', 'username', 'allowed_mentions'])
            ->and($envelope['content'])->toBe($message['text'])
            ->and($envelope['username'])->toBe('PokéPrices')
            ->and($envelope['allowed_mentions'])->toBe(['parse' => []]);
    }
});

it('counts price.moved, alert.triggered and digest in the command line and the alerts_dispatch log line', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(T6F_A, '2026-10-12');
    AlertRows::event(T6F_B, '2026-10-12');
    $user = t6fWatcher('all@example.com', T6F_A, 5, ['channel' => 'email', 'digest' => true]);
    AccountRows::watch($user, T6F_B);
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 5]);
    AlertRows::endpoint($user);

    [$code, $line] = AlertRows::dispatch();
    [, $again] = AlertRows::dispatch();
    $logged = AlertRows::logged('alerts_dispatch');

    expect($code)->toBe(0)
        ->and($line['created'])->toBe(['price.moved' => 2, 'alert.triggered' => 2, 'digest' => 1])
        ->and($again['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and($logged[0]['context'])->toBe($line)
        ->and($logged[0]['context']['created'])->toBe(['price.moved' => 2, 'alert.triggered' => 2, 'digest' => 1]);
});

it('reads card names once for everything missing, and nothing when nothing is missing', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(T6F_A, '2026-10-12');
    AlertRows::event(T6F_B, '2026-10-12');
    $alerted = t6fWatcher('alerted@example.com', T6F_A, 5);
    $reader = t6fWatcher('reader@example.com', T6F_B, null, ['channel' => 'email', 'digest' => true]);

    AlertRows::dispatch();
    $first = AccountRows::batches();
    AlertRows::dispatch();

    $ids = $first[0] ?? [];
    sort($ids);
    expect($first)->toHaveCount(1)
        ->and($ids)->toBe(['base1-2', 'base1-4'])
        ->and(AccountRows::batches())->toHaveCount(1)
        ->and(DB::table('deliveries')->whereIn('user_id', [$alerted->id, $reader->id])->pluck('kind')->sort()->values()->all())->toBe(['alert.triggered', 'digest']);
});

it('defers alerts and digests too while the catalog is degraded inside the grace', function (): void {
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', 'ok', 'sync', '2026-10-12 20:30:00+00');
    AlertRows::event(T6F_A, '2026-10-12');
    t6fWatcher('reader@example.com', T6F_A, 5, ['channel' => 'email', 'digest' => true]);
    AccountRows::$down = true;

    $this->travelTo(CarbonImmutable::parse('2026-10-12 20:59:00', 'UTC'));
    [, $within] = AlertRows::dispatch();
    $withinRows = DB::table('deliveries')->count();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 21:00:01', 'UTC'));
    [, $after] = AlertRows::dispatch();

    expect($within)->toMatchArray(['skipped' => 'catalog degraded'])
        ->and($within['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 0, 'digest' => 0])
        ->and($withinRows)->toBe(0)
        ->and($after['created'])->toBe(['price.moved' => 0, 'alert.triggered' => 1, 'digest' => 1]);
});
