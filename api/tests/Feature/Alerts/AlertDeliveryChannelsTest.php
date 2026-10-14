<?php

declare(strict_types=1);

use App\Domain\Alerts\DiscordMessage;
use App\Domain\Alerts\NotificationText;
use App\Domain\Alerts\PriceMovedPayload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\AccountRows;
use Tests\Support\AlertRows;
use Tests\Support\CatalogCache;
use Tests\Support\DeliveryRows;
use Tests\Support\FakeHostResolver;

// Plan P5-i1 T06 criteria 5 and 6 (D4, D7; review A08): an email delivery is one raw message to the person's
// address as it is at send time, with the D4 subject; a mailer exception is retried as internal and neither the
// address nor the exception's text reaches a log line, failed_jobs or the exception handler. On the Discord channel
// an alert and a digest are the D4 envelope of the same text, the digest cut to Discord's 2000 characters.
// The tests read the default (array) mailer's transport: Mail::fake() discards Mail::raw() messages.

const T6C_A = 'base1-4:holo:-:-';
const T6C_DISCORD = 'https://discord.com/api/webhooks/1/test-token-0000';

beforeEach(function (): void {
    $this->catalogPrefix = AccountRows::fakePokeSearch();
    AlertRows::captureLogs();
    config(['app.url' => 'http://pokeprices.localhost:8095']);
    DeliveryRows::jitter('min');
    FakeHostResolver::install(['discord.com' => ['198.51.100.7']]);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CatalogCache::flush($this->catalogPrefix);
});

/** An eligible person watching T6C_A at 5 % with the digest on, on $channel. */
function t6cPerson(string $email, string $channel = 'email'): User
{
    $user = AlertRows::user($email);
    AccountRows::watch($user, T6C_A);
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => 5]);
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => $channel, 'digest' => true]);

    return $user;
}

/** Swaps the catalog fake for a fresh HTTP fake: nothing but the given stubs answers. */
function t6cFreshHttp(array $stubs = []): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake($stubs);
}

/** @return list<Email> the messages the array mailer holds */
function t6cMails(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport->messages()->map(function (SentMessage $sent): Email {
        $message = $sent->getOriginalMessage();
        assert($message instanceof Email);

        return $message;
    })->values()->all();
}

it('mails the alert to the person\'s address as it is at send time, with the D4 subject, and marks it delivered', function (): void {
    AlertRows::runs('2026-10-11', '2026-10-12');
    AlertRows::event(T6C_A, '2026-10-12');
    $user = t6cPerson('before@example.com');
    DB::table('notification_settings')->where('user_id', $user->id)->update(['digest' => false]);
    AlertRows::dispatch();
    $id = (int) DB::table('deliveries')->where('kind', 'alert.triggered')->value('id');
    $stored = json_decode((string) DB::table('deliveries')->where('id', $id)->value('body'), true);

    DB::table('users')->where('id', $user->id)->update(['email' => 'after@example.com']);
    t6cFreshHttp();
    [$code, $summary] = DeliveryRows::sweep();

    $mails = t6cMails();
    $row = DeliveryRows::row($id);
    $line = AlertRows::logged('delivery_attempt')[0]['context'];
    expect($code)->toBe(0)
        ->and($summary)->toMatchArray(['claimed' => 1, 'delivered' => 1])
        ->and($mails)->toHaveCount(1)
        ->and(array_map(fn ($a): string => $a->getAddress(), $mails[0]->getTo()))->toBe(['after@example.com'])
        ->and($mails[0]->getSubject())->toBe('PokéPrices alert: Name of base1-4 moved +23.00% (2026-10-12)')
        ->and($mails[0]->getSubject())->toBe($stored['subject'])
        ->and($mails[0]->getTextBody())->toBe($stored['text'])
        ->and([$row->status, $row->attempts, $row->last_status, $row->last_error])->toBe(['delivered', 1, null, null])
        ->and($row->delivered_at)->not->toBeNull()
        ->and($line)->toMatchArray(['kind' => 'alert.triggered', 'channel' => 'email', 'endpoint_id' => null, 'outcome' => 'delivered', 'status' => null, 'error' => null])
        ->and(Http::recorded())->toHaveCount(0);
});

it('mails the digest once, and never again once delivered', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(T6C_A, '2026-10-12');
    $user = t6cPerson('reader@example.com');
    DB::table('watchlist_items')->where('user_id', $user->id)->update(['alert_pct' => null]);
    AlertRows::dispatch();

    t6cFreshHttp();
    DeliveryRows::sweep();
    $this->travel(3601)->seconds();
    DeliveryRows::sweep();
    AlertRows::dispatch();
    DeliveryRows::sweep();

    $mails = t6cMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe('PokéPrices daily digest: closes of 2026-10-12')
        ->and(DB::table('deliveries')->where('kind', 'digest')->pluck('status')->all())->toBe(['delivered']);
});

it('retries a mailer failure as internal and leaks neither the address nor the exception text', function (): void {
    AlertRows::runs('2026-10-12');
    AlertRows::event(T6C_A, '2026-10-12');
    $user = t6cPerson('secret-reader@example.com');
    DB::table('notification_settings')->where('user_id', $user->id)->update(['digest' => false]);
    AlertRows::dispatch();
    $id = (int) DB::table('deliveries')->where('kind', 'alert.triggered')->value('id');

    Exceptions::fake();
    t6cFreshHttp();
    Mail::mailer()->setSymfonyTransport(new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Expected response code "250" for secret-reader@example.com but got SENTINEL-MAIL-FAILURE');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    [, $summary] = DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    $line = AlertRows::logged('delivery_attempt')[0]['context'];
    $all = json_encode(AlertRows::$logs, JSON_UNESCAPED_SLASHES).json_encode(DB::table('failed_jobs')->get());
    expect($summary)->toMatchArray(['claimed' => 1, 'retry' => 1])
        ->and([$row->status, $row->attempts, $row->last_status, $row->last_error])->toBe(['pending', 1, null, 'internal'])
        ->and((int) round(DeliveryRows::dueIn($id)))->toBe(15)
        ->and($line)->toMatchArray(['channel' => 'email', 'outcome' => 'retry', 'error' => 'internal', 'exception' => 'TransportException'])
        ->and($all)->not->toContain('secret-reader')
        ->and($all)->not->toContain('SENTINEL-MAIL-FAILURE')
        ->and($all)->not->toContain('@example.com')
        ->and(DB::table('failed_jobs')->count())->toBe(0);
    Exceptions::assertNothingReported();
});

it('fails an email delivery whose person has no address left with no_channel and no message', function (): void {
    $user = AlertRows::user('gone@example.com');
    $id = (int) DB::table('deliveries')->insertGetId([
        'message_id' => 'msg_01JAAAAAAAAAAAAAAAAAAAAAAA', 'user_id' => $user->id, 'kind' => 'digest', 'channel' => 'email',
        'endpoint_id' => null, 'day' => '2026-10-12', 'body' => '{"subject":"s","text":"t"}',
        'next_attempt_at' => '2026-10-15 12:00:00+00',
    ]);
    DB::table('users')->where('id', $user->id)->update(['email' => '']);

    DeliveryRows::sweep();

    $row = DeliveryRows::row($id);
    expect([$row->status, $row->attempts, $row->last_error])->toBe(['failed', 0, 'no_channel'])
        ->and(t6cMails())->toBe([]);
});

it('sends an alert and a digest on the Discord channel as the D4 envelope of the same text', function (): void {
    AlertRows::runs('2026-10-12');
    $event = AlertRows::event(T6C_A, '2026-10-12');
    $user = t6cPerson('discord-reader@example.com', 'discord');
    $endpoint = AlertRows::endpoint($user, 'discord');
    AlertRows::dispatch();
    $text = NotificationText::alert(
        (object) ((array) DB::selectOne('SELECT '.PriceMovedPayload::COLUMNS.' FROM price_events e WHERE e.id = ?', [$event])),
        ['name' => 'Name of base1-4', 'set_name' => 'Set of base1-4', 'number' => '4'],
        5,
    )['text'];

    // A fresh response per request: the sender closes each body after reading it.
    t6cFreshHttp(['discord.com/*' => fn () => Http::response('', 204)]);
    [, $summary] = DeliveryRows::sweep();

    $requests = Http::recorded()->map(fn (array $pair): ClientRequest => $pair[0])->values()->all();
    $contents = array_map(fn (ClientRequest $r): string => (string) json_decode($r->body(), true)['content'], $requests);
    $rows = DB::table('deliveries')->where('user_id', $user->id)->orderBy('kind')->get(['kind', 'channel', 'endpoint_id', 'status', 'body']);
    expect($summary)->toMatchArray(['claimed' => 2, 'delivered' => 2])
        ->and($requests)->toHaveCount(2)
        ->and(array_map(fn (ClientRequest $r): string => $r->url(), $requests))->toBe([T6C_DISCORD, T6C_DISCORD])
        ->and(array_map(fn (ClientRequest $r): string => $r->body(), $requests))->toEqualCanonicalizing($rows->pluck('body')->all())
        ->and($rows->pluck('kind')->all())->toBe(['alert.triggered', 'digest'])
        ->and($rows->pluck('channel')->unique()->values()->all())->toBe(['discord'])
        ->and($rows->pluck('endpoint_id')->unique()->values()->all())->toBe([$endpoint])
        ->and($rows->pluck('status')->unique()->values()->all())->toBe(['delivered'])
        ->and($contents)->toContain($text)
        ->and($rows[0]->body)->toBe(DiscordMessage::envelope($text))
        ->and(t6cMails())->toBe([]);
});

it('cuts a long Discord digest to 2000 characters on a line boundary and keeps its source line', function (): void {
    AlertRows::runs('2026-10-12');
    $user = AlertRows::user('discord-reader@example.com');
    DB::table('notification_settings')->insert(['user_id' => $user->id, 'channel' => 'discord', 'digest' => true]);
    AlertRows::endpoint($user, 'discord');
    for ($i = 1; $i <= 30; $i++) {
        $key = "base1-{$i}:holo:-:-";
        AlertRows::event($key, '2026-10-12', ['change_pct' => sprintf('%d.00', 10 + $i), 'close' => '1234567.89', 'previous_close' => '1000000.00', 'change' => '234567.89']);
        AccountRows::watch($user, $key);
    }

    AlertRows::dispatch();
    $content = (string) json_decode((string) DB::table('deliveries')->where('kind', 'digest')->value('body'), true)['content'];
    $lines = explode("\n", $content);
    $moves = array_slice($lines, 1, count($lines) - 3);
    $more = $lines[count($lines) - 2];

    expect(mb_strlen($content))->toBeLessThanOrEqual(2000)
        ->and(mb_strlen($content))->toBeGreaterThan(1800)
        ->and($lines[0])->toStartWith('Cards on your watchlist and portfolio that moved by at least 5% and $1.00')
        ->and($moves[0])->toStartWith('Name of base1-30 · holo: $1,000,000.00 → $1,234,567.89, ')
        ->and($more)->toBe('…and '.(30 - count($moves)).' more: http://pokeprices.localhost:8095/watchlist')
        ->and(count($moves))->toBeLessThan(25)
        ->and(end($lines))->toBe('TCGplayer market prices via TCGCSV, collected once a day.');
});
