<?php

declare(strict_types=1);

use App\Domain\Alerts\DiscordMessage;
use App\Domain\Alerts\NotificationText;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\ForbiddenWords;

// Plan P5-i1 T06 criterion 4 (D4, D11; review A06, A10.5): the alert and digest texts, pinned as whole strings, as
// the dispatcher stores them; money grouped the same way in both; the card URL with its encoded id; the movement
// rule's figures rendered from config; a degraded catalog gives "Card <card_id>"; every rendered subject, body and
// Discord content passes all eight human-text forbidden-word patterns (dates only, never a time of day).

const T6T_APP = 'http://pokeprices.localhost:8095';
const T6T_EX = 'ex10-?:holo:1st:-';
const T6T_BASE = 'base1-4:holo:-:-';
const T6T_DOWN = 'base1-2:reverse:-:-';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    config(['app.url' => T6T_APP]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** The up move of the pinned alert: $1,234.50 → $1,389.95 on 2026-10-12. */
function t6tUp(): int
{
    return AlertRows::event(T6T_EX, '2026-10-12', [
        'direction' => 'up', 'close' => '1389.95', 'previous_close' => '1234.50', 'previous_day' => '2026-10-11',
        'change' => '155.45', 'change_pct' => '12.59',
    ]);
}

/** The down move: $5.50 → $4.00 on 2026-10-12. */
function t6tDown(): int
{
    return AlertRows::event(T6T_DOWN, '2026-10-12', [
        'direction' => 'down', 'close' => '4.00', 'previous_close' => '5.50', 'previous_day' => '2026-10-11',
        'change' => '-1.50', 'change_pct' => '-27.27',
    ]);
}

/** An eligible user on the email channel watching $keys at $pct, with the digest as given. */
function t6tUser(string $email, array $keys, ?int $pct, bool $digest): User
{
    $user = AlertRows::user($email);
    foreach ($keys as $key) {
        AccountRows::watch($user, $key);
    }
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => $pct]);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => 'email', 'digest' => $digest]);

    return $user;
}

/**
 * The stored email message of the user's delivery of $kind (event or day), decoded.
 *
 * @return array{subject: string, text: string, raw: string}
 */
function t6tMessage(User $user, string $kind, ?int $eventId = null): array
{
    $query = DB::table('deliveries')->where('user_id', $user->id)->where('kind', $kind);
    if ($eventId !== null) {
        $query->where('event_id', $eventId);
    }
    $raw = (string) $query->value('body');
    $decoded = json_decode($raw, true);

    return ['subject' => (string) $decoded['subject'], 'text' => (string) $decoded['text'], 'raw' => $raw];
}

/** A price_events-shaped row for direct rendering (no database row; any variant key). */
function t6tRow(string $key, array $overrides = []): stdClass
{
    return (object) ($overrides + [
        'id' => 1, 'variant_key' => $key, 'card_id' => explode(':', $key)[0], 'direction' => 'up', 'close' => '12.30',
        'previous_close' => '10.00', 'change' => '2.30', 'change_pct' => '23.00', 'day' => '2026-10-12',
        'as_of' => '2026-10-12T20:05:45Z', 'previous_day' => '2026-10-11', 'previous_as_of' => '2026-10-11T20:05:45Z',
        'series_epoch' => 1, 'rule' => 'draft-v1', 'source' => 'tcgplayer',
    ]);
}

it('pins the alert of an up move: subject and body as whole strings, grouped money, the encoded card URL', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $event = t6tUp();
    $user = t6tUser('alert@example.com', [T6T_EX], 10, false);

    AlertRows::dispatch();
    $message = t6tMessage($user, 'alert.triggered', $event);

    expect($message['subject'])->toBe('PokéPrices alert: Name of ex10-? moved +12.59% (2026-10-12)')
        ->and($message['text'])->toBe(implode("\n", [
            'Name of ex10-? · holo, 1st',
            'Market price $1,389.95',
            '▲ +$155.45 (+12.59%) vs previous close (2026-10-11)',
            'as of 2026-10-12 from TCGCSV (daily close)',
            'Your alert threshold: 10% (and at least $1.00).',
            'http://pokeprices.localhost:8095/card/ex10-%3F',
        ]))
        ->and($message['raw'])->toStartWith('{"subject":"PokéPrices alert: ')
        ->and(ForbiddenWords::textMatches($message['subject']."\n".$message['text']))->toBe([]);
});

it('pins the alert of a down move with the web\'s change line', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    $event = t6tDown();
    $user = t6tUser('alert@example.com', [T6T_DOWN], 20, false);

    AlertRows::dispatch();
    $message = t6tMessage($user, 'alert.triggered', $event);

    expect($message['subject'])->toBe('PokéPrices alert: Name of base1-2 moved -27.27% (2026-10-12)')
        ->and($message['text'])->toBe(implode("\n", [
            'Name of base1-2 · reverse',
            'Market price $4.00',
            '▼ -$1.50 (-27.27%) vs previous close (2026-10-11)',
            'as of 2026-10-12 from TCGCSV (daily close)',
            'Your alert threshold: 20% (and at least $1.00).',
            'http://pokeprices.localhost:8095/card/base1-2',
        ]))
        ->and(ForbiddenWords::textMatches($message['subject']."\n".$message['text']))->toBe([]);
});

it('pins a three-line digest: ordered by the size of the move, money grouped exactly like the alert', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    t6tUp();
    t6tDown();
    AlertRows::event(T6T_BASE, '2026-10-12');
    $user = t6tUser('digest@example.com', [T6T_EX, T6T_BASE], null, true);
    AccountRows::hold($user, T6T_DOWN, 1);
    AccountRows::hold($user, T6T_BASE, 3);

    AlertRows::dispatch();
    $message = t6tMessage($user, 'digest');

    expect($message['subject'])->toBe('PokéPrices daily digest: closes of 2026-10-12')
        ->and($message['text'])->toBe(implode("\n", [
            'Cards on your watchlist and portfolio that moved by at least 5% and $1.00 between two daily closes, as of 2026-10-12 from TCGCSV (daily close):',
            'Name of base1-2 · reverse: $5.50 → $4.00, ▼ -$1.50 (-27.27%) vs previous close (2026-10-11)',
            'Name of base1-4 · holo: $10.00 → $12.30, ▲ +$2.30 (+23.00%) vs previous close (2026-10-11)',
            'Name of ex10-? · holo, 1st: $1,234.50 → $1,389.95, ▲ +$155.45 (+12.59%) vs previous close (2026-10-11)',
            'TCGplayer market prices via TCGCSV, collected once a day.',
        ]))
        ->and(ForbiddenWords::textMatches($message['subject']."\n".$message['text']))->toBe([]);
});

it('labels a card the catalog could not name as Card <card_id> in the alert and the digest', function (): void {
    AlertRows::run('2026-10-11');
    AlertRows::run('2026-10-12', 'ok', 'sync', '2026-10-12 20:30:00+00');
    $event = t6tUp();
    $user = t6tUser('degraded@example.com', [T6T_EX], 10, true);
    AccountRows::$down = true;
    // Past the catalog grace, so the deliveries are created with whatever the read gave.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 21:00:01', 'UTC'));

    AlertRows::dispatch();
    $alert = t6tMessage($user, 'alert.triggered', $event);
    $digest = t6tMessage($user, 'digest');

    expect($alert['subject'])->toBe('PokéPrices alert: Card ex10-? moved +12.59% (2026-10-12)')
        ->and(explode("\n", $alert['text'])[0])->toBe('Card ex10-? · holo, 1st')
        ->and(explode("\n", $digest['text'])[1])->toBe('Card ex10-? · holo, 1st: $1,234.50 → $1,389.95, ▲ +$155.45 (+12.59%) vs previous close (2026-10-11)');
});

it('renders the movement rule\'s figures from config, never hard-coded', function (): void {
    config(['pricing.movement.pct' => '7.50', 'pricing.movement.abs' => '2.00']);
    $row = t6tRow(T6T_BASE);

    $alert = NotificationText::alert($row, null, 10);
    $digest = NotificationText::digest('2026-10-12', [['event' => $row, 'card' => null]], 25);

    expect(explode("\n", $alert['text'])[4])->toBe('Your alert threshold: 10% (and at least $2.00).')
        ->and(explode("\n", $digest['text'])[0])->toBe('Cards on your watchlist and portfolio that moved by at least 7.5% and $2.00 between two daily closes, as of 2026-10-12 from TCGCSV (daily close):');
});

it('labels a variant key with no finish, edition or treatment by the key itself', function (): void {
    $row = t6tRow('base1-3:-:-:-');

    $alert = NotificationText::alert($row, ['name' => 'Charizard', 'set_name' => 'Base', 'number' => '4'], 5);

    expect(explode("\n", $alert['text'])[0])->toBe('Charizard · base1-3:-:-:-');
});

it('cuts a digest at the configured number of lines and points to the watchlist for the rest', function (): void {
    config(['alerts.digest.max_lines' => 2]);
    $moves = [];
    foreach (['10.00', '30.00', '20.00', '40.00'] as $i => $pct) {
        $moves[] = ['event' => t6tRow("base1-{$i}:holo:-:-", ['change_pct' => $pct]), 'card' => null];
    }

    $digest = NotificationText::digest('2026-10-12', $moves, (int) config('alerts.digest.max_lines'));

    expect(explode("\n", $digest['text']))->toBe([
        'Cards on your watchlist and portfolio that moved by at least 5% and $1.00 between two daily closes, as of 2026-10-12 from TCGCSV (daily close):',
        'Card base1-3 · holo: $10.00 → $12.30, ▲ +$2.30 (+40.00%) vs previous close (2026-10-11)',
        'Card base1-1 · holo: $10.00 → $12.30, ▲ +$2.30 (+30.00%) vs previous close (2026-10-11)',
        '…and 2 more: http://pokeprices.localhost:8095/watchlist',
        'TCGplayer market prices via TCGCSV, collected once a day.',
    ]);
});

it('orders equal moves by variant key and compares sizes as decimals, not as text', function (): void {
    $moves = [
        ['event' => t6tRow('base1-9:holo:-:-', ['change_pct' => '9.00']), 'card' => null],
        ['event' => t6tRow('base1-2:holo:-:-', ['change_pct' => '-100.00', 'change' => '-10.00']), 'card' => null],
        ['event' => t6tRow('base1-5:holo:-:-', ['change_pct' => '10.00']), 'card' => null],
        ['event' => t6tRow('base1-1:holo:-:-', ['change_pct' => '10.00']), 'card' => null],
    ];

    $lines = explode("\n", NotificationText::digest('2026-10-12', $moves, 25)['text']);

    expect(array_map(fn (string $l): string => explode(' · ', $l)[0], array_slice($lines, 1, 4)))
        ->toBe(['Card base1-2', 'Card base1-1', 'Card base1-5', 'Card base1-9']);
});

it('passes all eight human-text patterns in every rendered subject, body and Discord content', function (): void {
    $up = t6tRow(T6T_EX, ['close' => '1389.95', 'previous_close' => '1234.50', 'change' => '155.45', 'change_pct' => '12.59']);
    $down = t6tRow(T6T_DOWN, ['direction' => 'down', 'close' => '4.00', 'previous_close' => '5.50', 'change' => '-1.50', 'change_pct' => '-27.27']);
    $flat = t6tRow(T6T_BASE, ['change' => '0.00', 'change_pct' => '0.00']);
    $card = ['name' => 'Charizard', 'set_name' => 'Base', 'number' => '4'];
    $many = [];
    for ($i = 1; $i <= 30; $i++) {
        $many[] = ['event' => t6tRow("base1-{$i}:holo:-:-"), 'card' => $i % 2 === 0 ? $card : null];
    }

    $texts = [];
    foreach ([[$up, $card], [$up, null], [$down, $card], [$flat, null]] as [$row, $name]) {
        $alert = NotificationText::alert($row, $name, 5);
        array_push($texts, $alert['subject'], $alert['text'], DiscordMessage::envelope($alert['text']));
    }
    foreach ([[['event' => $up, 'card' => $card], ['event' => $down, 'card' => null]], $many] as $moves) {
        $digest = NotificationText::digest('2026-10-12', $moves, 25);
        array_push($texts, $digest['subject'], $digest['text'], NotificationText::discordDigest('2026-10-12', $moves));
    }

    $hits = [];
    foreach ($texts as $i => $text) {
        if (ForbiddenWords::textMatches($text) !== []) {
            $hits[$i] = ForbiddenWords::textMatches($text);
        }
    }
    expect($texts)->toHaveCount(18)
        ->and($hits)->toBe([]);
});
