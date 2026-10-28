<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\FakeHostResolver;
use Tests\Support\ForbiddenWords;

// Plan P5-i1 T03 criterion 7 (D13, R230): pokeprices:alerts:discord-test is the live Discord switch. Unset, it says
// so and exits 1 with no request; set, it checks the URL as a Discord webhook, sends one envelope synchronously with
// the deliverable day's three largest moves, prints {"status", "delivered"} and exits 0 on a 2xx. The URL never
// reaches the output or a log line. HTTP is faked; the value is set with config([...]).

const T3C_URL = 'https://discord.com/api/webhooks/123456789012345678/SENTINELTOKEN-0001';

beforeEach(function (): void {
    FakeHostResolver::install(['discord.com' => ['198.51.100.7'], 'hooks.example.com' => ['203.0.113.10']]);
    AlertRows::captureLogs();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
    $this->catalogPrefix = null;
});

afterEach(function (): void {
    if (is_string($this->catalogPrefix)) {
        CatalogCache::flush($this->catalogPrefix);
    }
});

/** @return array{0: int, 1: string} */
function t3cRun(): array
{
    $code = Artisan::call('pokeprices:alerts:discord-test');

    return [$code, Artisan::output()];
}

/**
 * Fakes Discord with $answer and the PokéSearch catalog (meta and the batch name read, as AccountRows answers them;
 * every card id missing while $catalogDown) in one callback: Laravel runs every stub callback for every request, so
 * AccountRows::fakePokeSearch()'s catch-all cannot sit beside a Discord stub.
 */
function t3cFake(object $test, mixed $answer, bool $catalogDown = false): void
{
    config(['services.pokesearch.url' => AccountRows::BASE, 'services.pokesearch.api_key' => null]);
    $test->catalogPrefix = CatalogCache::isolate();
    Http::fake(function (ClientRequest $request, array $options) use ($answer, $catalogDown) {
        if (str_starts_with($request->url(), 'https://discord.com/')) {
            return $answer instanceof Closure ? $answer($request, $options) : $answer;
        }
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $seed = ['ref' => AccountRows::SEED_REF, 'seeded_at' => '2026-10-11T20:05:45Z'];
        if ($path === '/api/meta') {
            return Http::response(['version' => 'test', 'commit' => '0000000', 'built' => '', 'docs' => 3, 'seed' => $seed, 'request_id' => 'upstream-meta']);
        }
        if ($catalogDown) {
            return Http::response(['error' => 'unavailable'], 503);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return Http::response(['data' => array_map(fn (string $id): array => AccountRows::card($id), explode(',', (string) $query['ids'])), 'missing' => [], 'seed' => $seed]);
    });
}

/** @return list<ClientRequest> */
function t3cDiscordRequests(): array
{
    return Http::recorded(fn (ClientRequest $r): bool => str_starts_with($r->url(), 'https://discord.com/'))
        ->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
}

/** @return string the content of the one envelope sent */
function t3cContent(): string
{
    $requests = t3cDiscordRequests();
    expect($requests)->toHaveCount(1);
    $body = json_decode($requests[0]->body(), true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($body))->toBe(['content', 'username', 'allowed_mentions'])
        ->and($body['username'])->toBe('PokéPrices')
        ->and($body['allowed_mentions'])->toBe(['parse' => []]);

    return (string) $body['content'];
}

it('says the switch is off and sends nothing while the value is unset or empty', function (?string $value): void {
    config(['alerts.discord.test_webhook_url' => $value]);
    Http::fake();

    [$code, $output] = t3cRun();

    expect($code)->toBe(1)
        ->and(trim($output))->toBe('DISCORD_TEST_WEBHOOK_URL is not set; nothing was sent.');
    Http::assertNothingSent();
})->with(['unset' => null, 'empty' => '']);

it('reads the switch from DISCORD_TEST_WEBHOOK_URL through config', function (): void {
    $config = (string) file_get_contents(config_path('alerts.php'));

    expect($config)->toContain("'test_webhook_url' => env('DISCORD_TEST_WEBHOOK_URL')")
        ->and(config('alerts.rate_limits'))->toBe(['webhook-test' => 5])
        ->and(config('alerts.webhooks.limit'))->toBe(5)
        ->and(config('alerts.webhooks.discord_limit'))->toBe(1);
});

it('sends one envelope with the three largest moves of the deliverable day and prints the status', function (): void {
    config(['alerts.discord.test_webhook_url' => T3C_URL]);
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event('base1-4:holo:unlimited:-', '2026-10-12', ['close' => '968.91', 'previous_close' => '850.00', 'change' => '118.91', 'change_pct' => '13.99']);
    AlertRows::event('base1-2:holo:-:-', '2026-10-12', ['direction' => 'down', 'close' => '600.00', 'previous_close' => '1000.00', 'change' => '-400.00', 'change_pct' => '-40.00']);
    AlertRows::event('ex5-17:reverse:-:-', '2026-10-12', ['close' => '12.30', 'previous_close' => '10.00', 'change' => '2.30', 'change_pct' => '23.00']);
    AlertRows::event('base1-3:holo:-:-', '2026-10-12', ['close' => '1060.00', 'previous_close' => '1000.00', 'change' => '60.00', 'change_pct' => '6.00']);
    AlertRows::event('base1-5:holo:-:-', '2026-10-11', ['change_pct' => '90.00']);
    t3cFake($this, Http::response('', 204));
    $days = DB::table('dispatch_days')->count();

    [$code, $output] = t3cRun();

    $content = t3cContent();
    expect($code)->toBe(0)
        ->and(json_decode(trim($output), true))->toBe(['status' => 204, 'delivered' => true])
        ->and($content)->toBe(implode("\n", [
            'PokéPrices test message.',
            'The largest moves between two daily closes, as of 2026-10-12 from TCGCSV (daily close):',
            'Name of base1-2 · holo: $1,000.00 → $600.00, ▼ -$400.00 (-40.00%) vs previous close (2026-10-11)',
            'Name of ex5-17 · reverse: $10.00 → $12.30, ▲ +$2.30 (+23.00%) vs previous close (2026-10-11)',
            'Name of base1-4 · holo, unlimited: $850.00 → $968.91, ▲ +$118.91 (+13.99%) vs previous close (2026-10-11)',
            'TCGplayer market prices via TCGCSV, collected once a day.',
        ]))
        ->and(ForbiddenWords::textMatches($content))->toBe([])
        ->and(t3cDiscordRequests()[0]->header('Content-Type'))->toBe(['application/json'])
        ->and(DB::table('dispatch_days')->count())->toBe($days)
        ->and(DB::table('deliveries')->count())->toBe(0)
        ->and($output)->not->toContain('SENTINEL');
});

it('shows only events up to the day\'s recorded ceiling, and Card <id> while the catalog is down', function (): void {
    config(['alerts.discord.test_webhook_url' => T3C_URL]);
    AlertRows::runs('2026-10-12');
    $kept = AlertRows::event('base1-4:holo:unlimited:-', '2026-10-12', ['close' => '968.91', 'previous_close' => '850.00', 'change' => '118.91', 'change_pct' => '13.99']);
    DB::table('dispatch_days')->insert(['source' => 'tcgplayer', 'day' => '2026-10-12', 'event_id_ceiling' => $kept]);
    AlertRows::event('base1-2:holo:-:-', '2026-10-12', ['change_pct' => '80.00']);
    t3cFake($this, Http::response('', 204), catalogDown: true);

    [$code] = t3cRun();

    expect($code)->toBe(0)
        ->and(t3cContent())->toBe(implode("\n", [
            'PokéPrices test message.',
            'The largest moves between two daily closes, as of 2026-10-12 from TCGCSV (daily close):',
            'Card base1-4 · holo, unlimited: $850.00 → $968.91, ▲ +$118.91 (+13.99%) vs previous close (2026-10-11)',
            'TCGplayer market prices via TCGCSV, collected once a day.',
        ]));
});

it('says no card crossed the rule on a deliverable day without events, and no finalized day before any', function (bool $withDay, string $line): void {
    config(['alerts.discord.test_webhook_url' => T3C_URL]);
    if ($withDay) {
        AlertRows::runs('2026-10-12');
    }
    t3cFake($this, Http::response('', 204));

    [$code] = t3cRun();

    expect($code)->toBe(0)
        ->and(t3cContent())->toBe("PokéPrices test message.\n{$line}");
})->with([
    'a day without events' => [true, 'No card crossed the movement rule on 2026-10-12.'],
    'no finalized day' => [false, 'No finalized day yet.'],
]);

it('refuses an invalid URL with the field message, exit 1, no request and no echo of the URL', function (string $url, string $message): void {
    FakeHostResolver::install(['discord.com' => ['::1'], 'hooks.example.com' => ['203.0.113.10']]);
    config(['alerts.discord.test_webhook_url' => $url]);
    Http::fake();

    [$code, $output] = t3cRun();

    expect($code)->toBe(1)
        ->and(trim($output))->toBe($message)
        ->and($output)->not->toContain('SENTINEL');
    Http::assertNothingSent();
})->with([
    'not a Discord webhook' => ['https://hooks.example.com/api/webhooks/1/SENTINELTOKEN', 'A Discord webhook URL looks like https://discord.com/api/webhooks/<id>/<token>.'],
    'a blocked address' => [T3C_URL, 'PokéPrices does not send to private, local or reserved network addresses.'],
]);

it('exits 1 and prints the status when Discord does not answer 2xx, or null when the send failed', function (Closure $answer, ?int $status): void {
    config(['alerts.discord.test_webhook_url' => T3C_URL]);
    AlertRows::runs('2026-10-12');
    t3cFake($this, $answer);

    [$code, $output] = t3cRun();

    $logs = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES);
    expect($code)->toBe(1)
        ->and(json_decode(trim($output), true))->toBe(['status' => $status, 'delivered' => false])
        ->and($output.$logs)->not->toContain('SENTINEL')
        ->and($output.$logs)->not->toContain('api/webhooks');
})->with([
    'a 404' => [fn () => Http::response('{"message": "Unknown Webhook"}', 404), 404],
    'a 500' => [fn () => Http::response('', 500), 500],
    'a connection error carrying the URL' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect for '.T3C_URL), null],
    'an exception carrying the URL' => [fn () => throw new RuntimeException('boom '.T3C_URL), null],
]);
