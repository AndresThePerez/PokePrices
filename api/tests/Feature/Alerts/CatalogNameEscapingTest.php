<?php

declare(strict_types=1);

use App\Domain\Alerts\NotificationText;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\FakeHostResolver;
use Tests\Support\ForbiddenWords;

// Plan Q1-i3 T01 (sec B1, HG-40 #14): a catalog name is text from PokéSearch, not template. Every notification strips
// its control characters (\p{Cc}); Discord text also backslash-escapes the markdown metacharacters, so a name cannot
// render a masked link, a heading, a list or a mention. Only the label changes: the variant label, money, the change
// line, the as-of line, URLs and the footnote stay byte-identical, and the price.moved JSON payload keeps the name as
// the catalog gave it.

const Q3E_APP = 'http://pokeprices.localhost:8095';
const Q3E_KEY = 'base1-4:holo:1st:-';
const Q3E_EVIL = "[free cards](https://evil.example/x) @everyone\nX\x07";
const Q3E_EMAIL_LABEL = '[free cards](https://evil.example/x) @everyoneX';
const Q3E_DISCORD_LABEL = '\[free cards\]\(https\://evil.example/x\) \@everyoneX';
const Q3E_DISCORD_URL = 'https://discord.com/api/webhooks/123456789012345678/SENTINELTOKEN-0001';
const Q3E_MOVE = ' · holo, 1st: $1,234.50 → $1,389.95, ▲ +$155.45 (+12.59%) vs previous close (2026-10-11)';

beforeEach(function (): void {
    AlertRows::captureLogs();
    FakeHostResolver::install(['discord.com' => ['198.51.100.7']]);
    config(['app.url' => Q3E_APP]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->catalogPrefix = null;
});

afterEach(function (): void {
    if (is_string($this->catalogPrefix)) {
        CatalogCache::flush($this->catalogPrefix);
    }
});

/**
 * Fakes PokéSearch (meta and the card name reads, every card named from $names, else "Name of <id>") and Discord
 * (204) in one callback.
 *
 * @param  array<string, string>  $names
 */
function q3eFake(object $test, array $names): void
{
    config(['services.pokesearch.url' => AccountRows::BASE, 'services.pokesearch.api_key' => null]);
    $test->catalogPrefix = CatalogCache::isolate();
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use ($names) {
        if (str_starts_with($request->url(), 'https://discord.com/')) {
            return Http::response('', 204);
        }
        $card = fn (string $id): array => ['name' => $names[$id] ?? "Name of {$id}"] + AccountRows::card($id);
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => AccountRows::SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }
        if (str_starts_with($path, '/api/v1/cards/')) {
            return Http::response(['data' => $card(rawurldecode(substr($path, strlen('/api/v1/cards/')))), 'seed' => $seed]);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map($card, explode(',', (string) $query['ids'])), 'missing' => [], 'seed' => $seed]);
    });
}

/** The up move of the pinned alert: $1,234.50 → $1,389.95 on 2026-10-12. */
function q3eEvent(): int
{
    return AlertRows::event(Q3E_KEY, '2026-10-12', [
        'direction' => 'up', 'close' => '1389.95', 'previous_close' => '1234.50', 'previous_day' => '2026-10-11',
        'change' => '155.45', 'change_pct' => '12.59',
    ]);
}

/** An eligible person watching Q3E_KEY at 10 % with the digest on, on $channel. */
function q3ePerson(string $email, string $channel): User
{
    $user = AlertRows::user($email);
    AccountRows::watch($user, Q3E_KEY);
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 10]);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => $channel, 'digest' => true]);

    return $user;
}

/** @return array<string, mixed> the decoded stored body of the user's delivery of $kind */
function q3eBody(User $user, string $kind): array
{
    $raw = (string) DB::table('deliveries')->where('user_id', $user->id)->where('kind', $kind)->value('body');

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

/** A price_events-shaped row for direct rendering (no database row). */
function q3eRow(string $key, array $overrides = []): stdClass
{
    return (object) ($overrides + [
        'id' => 1, 'variant_key' => $key, 'card_id' => explode(':', $key)[0], 'direction' => 'up', 'close' => '12.30',
        'previous_close' => '10.00', 'change' => '2.30', 'change_pct' => '23.00', 'day' => '2026-10-12',
        'as_of' => '2026-10-12T20:05:45Z', 'previous_day' => '2026-10-11', 'previous_as_of' => '2026-10-11T20:05:45Z',
        'series_epoch' => 1, 'rule' => 'draft-v1', 'source' => 'tcgplayer',
    ]);
}

/** @return array{name: string, set_name: string, number: string} */
function q3eCard(string $name): array
{
    return ['name' => $name, 'set_name' => 'Set', 'number' => '4'];
}

it('strips control characters from the email alert and digest, and escapes the name in the stored Discord alert and digest', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    q3eFake($this, ['base1-4' => Q3E_EVIL]);
    q3eEvent();
    $mail = q3ePerson('mail-reader@example.com', 'email');
    $discord = q3ePerson('discord-reader@example.com', 'discord');
    AlertRows::endpoint($discord, 'discord');
    $standard = AlertRows::user('hook-owner@example.com');
    AccountRows::watch($standard, Q3E_KEY);
    AlertRows::endpoint($standard);

    AlertRows::dispatch();

    $alert = q3eBody($mail, 'alert.triggered');
    $digest = q3eBody($mail, 'digest');
    $discordAlert = (string) q3eBody($discord, 'alert.triggered')['content'];
    $discordDigest = (string) q3eBody($discord, 'digest')['content'];
    $payload = (string) DB::table('deliveries')->where('kind', 'price.moved')->value('body');
    $rest = [
        'Market price $1,389.95',
        '▲ +$155.45 (+12.59%) vs previous close (2026-10-11)',
        'as of 2026-10-12 from TCGCSV (daily close)',
        'Your alert threshold: 10% (and at least $1.00).',
        Q3E_APP.'/card/base1-4',
    ];

    expect($alert['subject'])->toBe('PokéPrices alert: [free cards](https://evil.example/x) @everyoneX moved +12.59% (2026-10-12)')
        ->and($alert['text'])->toBe(implode("\n", [Q3E_EMAIL_LABEL.' · holo, 1st', ...$rest]))
        ->and($discordAlert)->toBe(implode("\n", [Q3E_DISCORD_LABEL.' · holo, 1st', ...$rest]))
        ->and(explode("\n", $discordAlert)[0])->toBe('\[free cards\]\(https\://evil.example/x\) \@everyoneX · holo, 1st')
        ->and(explode("\n", (string) $digest['text']))->toContain(Q3E_EMAIL_LABEL.Q3E_MOVE)
        ->and(explode("\n", $discordDigest))->toContain(Q3E_DISCORD_LABEL.Q3E_MOVE)
        ->and($discordDigest)->not->toContain(Q3E_EMAIL_LABEL)
        // The standard webhook gets JSON, not Discord markdown: the name stays exactly as the catalog gave it.
        ->and(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['data']['card']['name'])->toBe(Q3E_EVIL)
        ->and(ForbiddenWords::textMatches($alert['subject']."\n".$alert['text']."\n".$digest['text']))->toBe([])
        ->and(ForbiddenWords::textMatches($discordAlert."\n".$discordDigest))->toBe([]);
});

it('escapes the name in the live switch\'s Discord content line', function (): void {
    config(['alerts.discord.test_webhook_url' => Q3E_DISCORD_URL]);
    AlertRows::runs('2026-10-11', '2026-10-12');
    q3eFake($this, ['base1-4' => Q3E_EVIL]);
    q3eEvent();

    $code = Artisan::call('pokeprices:alerts:discord-test');

    $requests = Http::recorded(fn (ClientRequest $r): bool => str_starts_with($r->url(), 'https://discord.com/'))->values()->all();
    $content = (string) json_decode($requests[0][0]->body(), true, 512, JSON_THROW_ON_ERROR)['content'];
    expect($code)->toBe(0)
        ->and($requests)->toHaveCount(1)
        ->and($content)->toBe(implode("\n", [
            'PokéPrices test message.',
            'The largest moves between two daily closes, as of 2026-10-12 from TCGCSV (daily close):',
            '\[free cards\]\(https\://evil.example/x\) \@everyoneX · holo, 1st: $1,234.50 → $1,389.95, ▲ +$155.45 (+12.59%) vs previous close (2026-10-11)',
            'TCGplayer market prices via TCGCSV, collected once a day.',
        ]))
        ->and(ForbiddenWords::textMatches($content))->toBe([]);
});

it('renders a name of only control characters as an empty label, not a crash and not the Card fallback', function (): void {
    $row = q3eRow('base1-4:holo:-:-');
    $card = q3eCard("\x00\r\n\t\x1b\x07\x7f\u{85}\u{9f}");

    $email = NotificationText::alert($row, $card, 5);
    $discord = NotificationText::discordAlert($row, $card, 5);

    // The outcome: the label is empty, so the subject has two spaces and the first line starts with " · ".
    expect($email['subject'])->toBe('PokéPrices alert:  moved +23.00% (2026-10-12)')
        ->and(explode("\n", $email['text'])[0])->toBe(' · holo')
        ->and(explode("\n", $discord)[0])->toBe(' · holo')
        ->and(explode("\n", NotificationText::discordDigest('2026-10-12', [['event' => $row, 'card' => $card]]))[1])
        ->toBe(' · holo: $10.00 → $12.30, ▲ +$2.30 (+23.00%) vs previous close (2026-10-11)');
});

it('escapes a leading list or heading marker in Discord, leaves a dash inside the name alone, and keeps email raw', function (string $name, string $escaped): void {
    $row = q3eRow('base1-4:holo:-:-');

    expect(explode("\n", NotificationText::discordAlert($row, q3eCard($name), 5))[0])->toBe($escaped.' · holo')
        ->and(explode("\n", NotificationText::alert($row, q3eCard($name), 5)['text'])[0])->toBe($name.' · holo');
})->with([
    'dash list' => ['- Pikachu', '\- Pikachu'],
    'plus list' => ['+ Pikachu', '\+ Pikachu'],
    'numbered list' => ['1. Pikachu', '1\. Pikachu'],
    'long number' => ['151. Mew', '151\. Mew'],
    'heading' => ['# Mew', '\# Mew'],
    'inner dash and dot' => ['Name of base1-4 v1.0', 'Name of base1-4 v1.0'],
    'every metacharacter' => ['a\\*_~`|<>[]()#@:b', 'a\\\\\*\_\~\`\|\<\>\[\]\(\)\#\@\:b'],
    'number without dot' => ['151 Mew', '151 Mew'],
]);

it('escapes the Card fallback label the same way as a name', function (): void {
    // A card id may hold '_' (CardId::PATTERN), a Discord italic marker; the '-' inside it stays.
    $row = q3eRow('sv_promo-1:holo:-:-');

    expect(explode("\n", NotificationText::discordAlert($row, null, 5))[0])->toBe('Card sv\_promo-1 · holo')
        ->and(NotificationText::alert($row, null, 5)['subject'])->toBe('PokéPrices alert: Card sv_promo-1 moved +23.00% (2026-10-12)');
});

it('measures the Discord digest after escaping, so escaped names still fit in 2000 characters with an exact more line', function (): void {
    $moves = [];
    for ($i = 1; $i <= 12; $i++) {
        $moves[] = ['event' => q3eRow(sprintf('base1-%d:holo:-:-', $i), ['id' => $i]), 'card' => q3eCard(str_repeat('*', 60))];
    }
    $line = str_repeat('\*', 60).' · holo: $10.00 → $12.30, ▲ +$2.30 (+23.00%) vs previous close (2026-10-11)';

    $email = NotificationText::digest('2026-10-12', $moves, 25)['text'];
    $text = NotificationText::discordDigest('2026-10-12', $moves);
    $lines = explode("\n", $text);
    $shown = array_slice($lines, 1, -2);

    expect(mb_strlen($email))->toBeLessThan(2000)
        ->and(mb_strlen($text))->toBeLessThanOrEqual(2000)
        ->and($shown)->toBe(array_fill(0, 8, $line))
        ->and($lines[count($lines) - 2])->toBe('…and 4 more: '.Q3E_APP.'/watchlist')
        ->and(end($lines))->toBe(NotificationText::FOOTNOTE)
        ->and(ForbiddenWords::textMatches($text))->toBe([]);
});
