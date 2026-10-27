<?php

declare(strict_types=1);

use App\Domain\Alerts\UrlGuard;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

// Plan P5-i1 T02 criterion 7 (D6): the address guard. Every blocked address in this file is computed at run time
// from UrlGuard::BLOCKS (written abbreviated, the parser right-pads IPv4 octets); no private address is a literal.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['alerts.webhooks.private_allow' => []]);
});

/**
 * The test's own reading of one block: [network bytes, prefix length].
 *
 * @return array{0: string, 1: int}
 */
function p5Block(string $block): array
{
    [$net, $prefix] = explode('/', $block);
    if (! str_contains($net, ':')) {
        $net = implode('.', array_pad(explode('.', $net), 4, '0'));
    }
    $bytes = inet_pton($net);
    assert(is_string($bytes));

    return [$bytes, (int) $prefix];
}

/** The first or last address of a block, as bytes. */
function p5Edge(string $block, bool $last): string
{
    [$bytes, $prefix] = p5Block($block);
    $out = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $bits = max(0, min(8, $prefix - 8 * $i));
        $mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
        $byte = ord($bytes[$i]) & $mask;
        $out .= chr($last ? $byte | (~$mask & 0xFF) : $byte);
    }

    return $out;
}

/** Adds $delta (+1 or -1) to an address; null when it wraps. */
function p5Step(string $bytes, int $delta): ?string
{
    $out = $bytes;
    for ($i = strlen($out) - 1; $i >= 0; $i--) {
        $v = ord($out[$i]) + $delta;
        if ($v >= 0 && $v <= 255) {
            $out[$i] = chr($v);

            return $out;
        }
        $out[$i] = chr($delta > 0 ? 0 : 255);
    }

    return null;
}

/** A middle address of the block: the first address with the bit after the prefix set (or the first itself). */
function p5Inside(string $block): string
{
    [, $prefix] = p5Block($block);
    $first = p5Edge($block, false);
    if ($prefix >= strlen($first) * 8) {
        return $first;
    }
    $i = intdiv($prefix, 8);
    $first[$i] = chr(ord($first[$i]) | (0x80 >> ($prefix % 8)));

    return $first;
}

function p5Covered(string $bytes): bool
{
    foreach (UrlGuard::BLOCKS as $block) {
        [$net, $prefix] = p5Block($block);
        if (strlen($net) !== strlen($bytes)) {
            continue;
        }
        if (p5Edge($block, false) <= $bytes && $bytes <= p5Edge($block, true)) {
            return true;
        }
    }

    return false;
}

function p5Literal(string $bytes): string
{
    $text = (string) inet_ntop($bytes);

    return strlen($bytes) === 16 ? '['.$text.']' : $text;
}

/** @return list<array{0: string, 1: string}> every blocked block's first, last and inside address */
function p5BlockedAddresses(): array
{
    $out = [];
    foreach (UrlGuard::BLOCKS as $block) {
        foreach (['first' => p5Edge($block, false), 'last' => p5Edge($block, true), 'inside' => p5Inside($block)] as $which => $bytes) {
            $out[] = ["{$block} {$which}", (string) inet_ntop($bytes)];
        }
    }

    return $out;
}

it('lists the D6 blocks, abbreviated', function (): void {
    expect(UrlGuard::BLOCKS)->toBe([
        '0/8', '10/8', '100.64/10', '127/8', '169.254/16', '172.16/12', '192.0.0/24', '192.168/16', '198.18/15', '224/4', '240/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ]);
});

it('refuses the first, last and an inside address of every block, as a literal and as a resolved address', function (): void {
    $cases = p5BlockedAddresses();
    expect(count($cases))->toBe(3 * count(UrlGuard::BLOCKS));

    foreach ($cases as [$label, $address]) {
        $literal = str_contains($address, ':') ? "[{$address}]" : $address;
        FakeHostResolver::install(['hooks.example.com' => [$address]]);
        $guard = app(UrlGuard::class);

        expect($guard->check("https://{$literal}/hook")->error)->toBe('blocked_address', "literal {$label}")
            ->and($guard->check('https://hooks.example.com/hook')->error)->toBe('blocked_address', "resolved {$label}");
    }
});

it('allows the IPv4 addresses just outside each block unless another block covers them', function (): void {
    FakeHostResolver::install([]);
    $guard = app(UrlGuard::class);
    $checked = 0;
    foreach (UrlGuard::BLOCKS as $block) {
        if (str_contains($block, ':')) {
            continue;
        }
        foreach ([p5Step(p5Edge($block, false), -1), p5Step(p5Edge($block, true), 1)] as $neighbour) {
            if ($neighbour === null) {
                continue;
            }
            $expected = p5Covered($neighbour) ? 'blocked_address' : null;
            expect($guard->check('https://'.p5Literal($neighbour).'/hook')->error)->toBe($expected, $block.' neighbour '.inet_ntop($neighbour));
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(15);
});

it('allows the RFC 5737 documentation addresses', function (string $address): void {
    FakeHostResolver::install(['hooks.example.com' => [$address]]);
    $guard = app(UrlGuard::class);
    $literal = $guard->check("https://{$address}/hook");
    $resolved = $guard->check('https://hooks.example.com/hook');

    expect($literal->error)->toBeNull()
        ->and($literal->address)->toBe($address)
        ->and($resolved->error)->toBeNull()
        ->and($resolved->host)->toBe('hooks.example.com')
        ->and($resolved->port)->toBe(443)
        ->and($resolved->address)->toBe($address)
        ->and($resolved->curlResolve())->toBe(["hooks.example.com:443:{$address}"]);
})->with(['203.0.113.10', '198.51.100.7', '192.0.2.33']);

it('pins an IPv6 answer in brackets', function (): void {
    FakeHostResolver::install(['hooks.example.com' => ['2001:db8::10']]);

    expect(app(UrlGuard::class)->check('https://hooks.example.com:8443/hook')->curlResolve())->toBe(['hooks.example.com:8443:[2001:db8::10]']);
});

it('refuses a host when any one of its addresses is blocked', function (string $block, bool $blockedFirst): void {
    $blocked = (string) inet_ntop(p5Inside($block));
    $answers = $blockedFirst ? [$blocked, '203.0.113.10'] : ['203.0.113.10', $blocked];
    $resolver = FakeHostResolver::install(['hooks.example.com' => $answers]);

    expect(app(UrlGuard::class)->check('https://hooks.example.com/hook')->error)->toBe('blocked_address')
        ->and($resolver->asked)->toBe(['hooks.example.com']);
})->with([
    'loopback second' => ['127/8', false],
    'private first' => ['10/8', true],
    'IPv6 loopback second' => ['::1/128', false],
]);

it('answers unresolvable when the host has no address', function (): void {
    FakeHostResolver::install([]);

    expect(app(UrlGuard::class)->check('https://nowhere.example.com/hook')->error)->toBe('unresolvable');
});

it('refuses the IPv6 loopback, mapped, NAT64 and link-local literals', function (string $url): void {
    FakeHostResolver::install([]);

    expect(app(UrlGuard::class)->check($url)->error)->toBe('blocked_address');
})->with([
    'loopback' => ['https://[::1]/hook'],
    'mapped loopback' => ['https://[::ffff:7f00:1]/hook'],
    'NAT64 loopback' => ['https://[64:ff9b::7f00:1]/hook'],
    'link-local' => ['https://[fe80::1]/hook'],
]);

it('refuses the IPv4 literal forms that curl would read as loopback, without resolving them', function (string $host): void {
    $resolver = FakeHostResolver::install([$host => ['203.0.113.10']]);
    $result = app(UrlGuard::class)->check("https://{$host}/hook");

    expect($result->error)->toBe('blocked_address')
        ->and($resolver->asked)->toBe([]);
})->with(['2130706433', '0x7f000001', '017700000001', '127.1', '0177.0.0.1', '127.0.0.1.']);

it('refuses plain http, user-info, a fragment, an overlong URL and malformed URLs', function (string $url, string $error): void {
    p5PublicResolver();

    expect(app(UrlGuard::class)->check($url)->error)->toBe($error);
})->with([
    'http' => ['http://hooks.example.com/hook', 'scheme'],
    'ftp' => ['ftp://hooks.example.com/hook', 'scheme'],
    'user-info' => ['https://user:pass@hooks.example.com/', 'invalid'],
    'bare at sign' => ['https://@hooks.example.com/', 'invalid'],
    'fragment' => ['https://hooks.example.com/hook#part', 'invalid'],
    'empty fragment' => ['https://hooks.example.com/hook#', 'invalid'],
    'backslash' => ['https://hooks.example.com\\@203.0.113.10/hook', 'invalid'],
    'space' => ['https://hooks.example.com /hook', 'invalid'],
    'no host' => ['https:///hook', 'invalid'],
    'no scheme' => ['hooks.example.com/hook', 'invalid'],
    'port zero' => ['https://hooks.example.com:0/hook', 'invalid'],
    'port too large' => ['https://hooks.example.com:65536/hook', 'invalid'],
    'empty port' => ['https://hooks.example.com:/hook', 'invalid'],
    'unicode host' => ['https://hööks.example.com/hook', 'invalid'],
    'IPv6 zone id' => ['https://[fe80::1%25eth0]/hook', 'invalid'],
    'IPv4 in brackets' => ['https://[203.0.113.10]/hook', 'invalid'],
    'overlong' => ['https://hooks.example.com/'.str_repeat('a', 2049 - strlen('https://hooks.example.com/')), 'too_long'],
]);

it('accepts a URL of exactly 2048 bytes', function (): void {
    p5PublicResolver();
    $url = 'https://hooks.example.com/'.str_repeat('a', 2048 - strlen('https://hooks.example.com/'));

    expect(strlen($url))->toBe(2048)
        ->and(app(UrlGuard::class)->check($url)->error)->toBeNull();
});

it('lets an allow-listed host:port through without resolution, over http only on that exact port', function (): void {
    $resolver = FakeHostResolver::install([]);
    config(['alerts.webhooks.private_allow' => ['api:8096']]);
    $guard = app(UrlGuard::class);

    $allowed = $guard->check('http://api:8096/hook');
    expect($allowed->error)->toBeNull()
        ->and($allowed->address)->toBeNull()
        ->and($allowed->curlResolve())->toBe([])
        ->and($resolver->asked)->toBe([])
        ->and($guard->check('http://API:8096/hook')->error)->toBeNull()
        ->and($guard->check('https://api:8096/hook')->error)->toBeNull()
        ->and($guard->check('http://api:8097/hook')->error)->toBe('scheme')
        ->and($guard->check('http://api/hook')->error)->toBe('scheme')
        ->and($guard->check('https://api:8097/hook')->error)->toBe('unresolvable')
        ->and($guard->check('http://api:8096@hooks.example.com/hook')->error)->toBe('invalid')
        ->and($guard->check('http://api.:8096/hook')->error)->not->toBeNull();
});

it('reads the allow-list from WEBHOOK_PRIVATE_ALLOW, trimmed, with the local default only under APP_ENV local', function (): void {
    $file = base_path('config/alerts.php');
    $read = function (array $env) use ($file): array {
        $saved = [];
        foreach ($env as $k => $v) {
            $saved[$k] = $_ENV[$k] ?? null;
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $_SERVER[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
        try {
            return (require $file)['webhooks']['private_allow'];
        } finally {
            foreach ($saved as $k => $v) {
                if ($v === null) {
                    unset($_ENV[$k], $_SERVER[$k]);
                    putenv($k);
                } else {
                    $_ENV[$k] = $_SERVER[$k] = $v;
                    putenv("{$k}={$v}");
                }
            }
        }
    };

    expect($read(['WEBHOOK_PRIVATE_ALLOW' => null, 'APP_ENV' => 'local']))->toBe(['api:8096'])
        ->and($read(['WEBHOOK_PRIVATE_ALLOW' => null, 'APP_ENV' => 'testing']))->toBe([])
        ->and($read(['WEBHOOK_PRIVATE_ALLOW' => ' a:1 , ,b:2 ', 'APP_ENV' => 'local']))->toBe(['a:1', 'b:2'])
        ->and($read(['WEBHOOK_PRIVATE_ALLOW' => '', 'APP_ENV' => 'local']))->toBe([])
        ->and(config('alerts.webhooks.connect_timeout_seconds'))->toBe(2)
        ->and(config('alerts.webhooks.timeout_seconds'))->toBe(5)
        ->and(config('alerts.webhooks.max_response_bytes'))->toBe(65536)
        ->and(config('alerts.delivery'))->toBe([
            'max_attempts' => 6, 'base_seconds' => 30, 'factor' => 4, 'cap_seconds' => 3600, 'lease_seconds' => 300,
            'batch' => 10, 'per_endpoint' => 3, 'sweep_budget_seconds' => 60, 'retention_days' => 30,
        ]);
});

function p5PublicResolver(): void
{
    FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10']]);
}

// Plan Q1-i2 T03 (sec C2): an IPv6 address that is not IPv4-mapped or compatible must be global unicast (2000::/3)
// and outside the IETF protocol assignments (2001::/23). Review b's allowed addresses are refused; the IPv6
// documentation ranges and a public address stay allowed.
it('refuses the IPv6 addresses outside global unicast or inside the IETF protocol assignments, as a literal and as a resolved address', function (string $address): void {
    FakeHostResolver::install(['hooks.example.com' => [$address]]);
    $guard = app(UrlGuard::class);

    expect($guard->check("https://[{$address}]/hook")->error)->toBe('blocked_address')
        ->and($guard->check('https://hooks.example.com/hook')->error)->toBe('blocked_address');
})->with([
    'local-use NAT64' => ['64:ff9b:1::7f00:1'],
    'SIIT translated' => ['::ffff:0:7f00:1'],
    'Teredo' => ['2001::1'],
    'discard-only' => ['100::1'],
    'site-local' => ['fec0::1'],
    'ORCHID' => ['2001:10::1'],
    'benchmarking' => ['2001:2::1'],
    'outside 2000::/3' => ['5f00::1'],
]);

it('allows the IPv6 documentation ranges and a public IPv6 address, as a literal and as a resolved address', function (string $address): void {
    FakeHostResolver::install(['hooks.example.com' => [$address]]);
    $guard = app(UrlGuard::class);
    $literal = $guard->check("https://[{$address}]/hook");
    $resolved = $guard->check('https://hooks.example.com/hook');

    expect($literal->error)->toBeNull()
        ->and($literal->address)->toBe($address)
        ->and($resolved->error)->toBeNull()
        ->and($resolved->address)->toBe($address);
})->with(['2001:db8::1', '3fff::1', '2606:4700::1111']);

// Reviewer edge cases for plan Q1-i2 T03: the prefix arithmetic at its edges, textual forms, embedded IPv4, and a mixed answer.
it('draws the global-unicast and IETF-assignment lines exactly at their prefix edges', function (string $address, bool $allowed): void {
    FakeHostResolver::install(['hooks.example.com' => [$address]]);
    $guard = app(UrlGuard::class);
    $expected = $allowed ? null : 'blocked_address';

    expect($guard->check("https://[{$address}]/hook")->error)->toBe($expected, "literal {$address}")
        ->and($guard->check('https://hooks.example.com/hook')->error)->toBe($expected, "resolved {$address}")
        ->and(UrlGuard::blocked($address))->toBe(! $allowed);
})->with([
    'first of 2000::/3' => ['2000::', true],
    'last of 2000::/3' => ['3fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    'first past 2000::/3' => ['4000::', false],
    'last before 2000::/3' => ['1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', false],
    'first of 2001::/23' => ['2001::', false],
    'last of 2001::/23' => ['2001:1ff:ffff:ffff:ffff:ffff:ffff:ffff', false],
    'first past 2001::/23' => ['2001:200::', true],
    'just before 2001::/23' => ['2000:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    'first of 3fff::/20' => ['3fff::', true],
    'last of 3fff::/20' => ['3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    'first of 2001:db8::/32' => ['2001:db8::', true],
    'last of 2001:db8::/32' => ['2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', true],
]);

it('reads uppercase, zero-padded and expanded IPv6 text the same as its short form, and refuses any zone id', function (string $host, bool $allowed): void {
    FakeHostResolver::install([]);
    $result = app(UrlGuard::class)->check("https://{$host}/hook");

    expect($result->error === null)->toBe($allowed, $host);
})->with([
    'uppercase documentation' => ['[2001:DB8::1]', true],
    'zero-padded documentation' => ['[2001:0db8:0000:0000:0000:0000:0000:0001]', true],
    'zero-padded ORCHID' => ['[2001:0010:0000:0000:0000:0000:0000:0001]', false],
    'uppercase Teredo' => ['[2001:0:4136:E378:8000:63BF:3FFF:FDD2]', false],
    'uppercase link-local' => ['[FE80::1]', false],
    'uppercase site-local' => ['[FEC0::1]', false],
    'zone on link-local' => ['[fe80::1%eth0]', false],
    'encoded zone on link-local' => ['[fe80::1%25eth0]', false],
    'zone on a global address' => ['[2606:4700::1111%25eth0]', false],
    'zone on a documentation address' => ['[2001:db8::1%eth0]', false],
    'unbracketed global address' => ['2606:4700::1111', false],
]);

it('refuses every IPv6 spelling that carries a private IPv4 target, as a literal and as a resolved address', function (string $address): void {
    FakeHostResolver::install(['hooks.example.com' => [$address]]);
    $guard = app(UrlGuard::class);

    expect($guard->check("https://[{$address}]/hook")->error)->toBe('blocked_address', "literal {$address}")
        ->and($guard->check('https://hooks.example.com/hook')->error)->toBe('blocked_address', "resolved {$address}");
})->with([
    'compatible loopback' => ['::127.0.0.1'],
    'compatible benchmarking' => ['::198.18.0.1'],
    'compatible metadata' => ['::169.254.169.254'],
    'compatible CGNAT in hex' => ['::6440:1'],
    'mapped benchmarking' => ['::ffff:198.18.0.1'],
    'mapped loopback in uppercase hex' => ['::FFFF:7F00:1'],
    'translated benchmarking' => ['::ffff:0:198.18.0.1'],
    'NAT64 benchmarking' => ['64:ff9b::198.18.0.1'],
    'local-use NAT64 loopback' => ['64:ff9b:1::127.0.0.1'],
    '6to4 loopback' => ['2002:7f00:1::'],
    'Teredo-wrapped loopback' => ['2001:0:7f00:1::'],
    'zero-padded mapped metadata' => ['0:0:0:0:0:ffff:a9fe:a9fe'],
]);

it('refuses a host when any one of its IPv6 answers is outside global unicast, wherever it sits in the list', function (array $answers): void {
    FakeHostResolver::install(['hooks.example.com' => $answers]);

    expect(app(UrlGuard::class)->check('https://hooks.example.com/hook')->error)->toBe('blocked_address');
})->with([
    'bad IPv6 last' => [['2606:4700::1111', '2001:db8::1', 'fec0::1']],
    'bad IPv6 first' => [['2001::1', '2606:4700::1111']],
    'bad IPv6 between IPv4' => [['203.0.113.10', '5f00::1', '198.51.100.7']],
    'unparseable text' => [['2606:4700::1111', 'fe80::1%eth0']],
]);
