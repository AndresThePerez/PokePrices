<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\Accounts;
use Tests\TestCase;

// Plan P6-DEPLOY-i1 T03 (Amendment 1, PR1): trusted proxies. Production sets TRUSTED_PROXIES=REMOTE_ADDR, which
// trusts only the immediate peer (nginx); unset trusts nobody (dev). The trusted header set is X-Forwarded-For,
// -Proto and -Port; X-Forwarded-Host (and -Prefix) are never trusted. Addresses are RFC 5737 only (R113).

const TP_PEER = '192.0.2.10';

const TP_PROBE = '/__trusted-proxy-probe';

beforeEach(function (): void {
    Route::get(TP_PROBE, fn (Request $request) => response()->json([
        'secure' => $request->isSecure(),
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'url' => url('/v1/ready'),
    ]));
});

afterEach(function (): void {
    // Symfony keeps the trusted proxies in static state; leave nothing trusted for the next test in this process.
    Request::setTrustedProxies([], -1);
});

/**
 * Sends the probe request from the peer TP_PEER with the given forwarded headers.
 *
 * @param  array<string, string>  $headers
 * @return array{secure: bool, ip: string, host: string, url: string}
 */
function tpProbe(TestCase $case, array $headers): array
{
    /** @var array{secure: bool, ip: string, host: string, url: string} $seen */
    $seen = $case->withServerVariables(['REMOTE_ADDR' => TP_PEER])->withHeaders($headers)->getJson(TP_PROBE)
        ->assertOk()->json();

    return $seen;
}

/**
 * Evaluates config/trustedproxy.php with TRUSTED_PROXIES set to $value (null = unset), then restores the variable.
 */
function tpConfigWith(?string $value): mixed
{
    $file = config_path('trustedproxy.php');
    expect($file)->toBeFile();
    $before = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null, getenv('TRUSTED_PROXIES')];
    unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
    putenv('TRUSTED_PROXIES');
    if ($value !== null) {
        $_SERVER['TRUSTED_PROXIES'] = $value;
        $_ENV['TRUSTED_PROXIES'] = $value;
        putenv('TRUSTED_PROXIES='.$value);
    }
    try {
        $config = require $file;
    } finally {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');
        if ($before[0] !== null) {
            $_SERVER['TRUSTED_PROXIES'] = $before[0];
        }
        if ($before[1] !== null) {
            $_ENV['TRUSTED_PROXIES'] = $before[1];
        }
        if ($before[2] !== false) {
            putenv('TRUSTED_PROXIES='.$before[2]);
        }
    }
    expect($config)->toBeArray()->toHaveKey('proxies');

    return $config['proxies'];
}

it('(a) reads TRUSTED_PROXIES=REMOTE_ADDR from the environment into trustedproxy.proxies, unchanged', function (): void {
    expect(tpConfigWith('REMOTE_ADDR'))->toBe('REMOTE_ADDR')
        ->and(tpConfigWith(null))->toBeNull();
});

it('(a) under TRUSTED_PROXIES=REMOTE_ADDR, trusts the peer\'s forwarded https and client: secure, client ip, https URLs', function (): void {
    config(['trustedproxy.proxies' => tpConfigWith('REMOTE_ADDR')]);

    $seen = tpProbe($this, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7']);

    expect($seen['secure'])->toBeTrue()
        ->and($seen['ip'])->toBe('198.51.100.7')
        ->and($seen['url'])->toStartWith('https://');
});

it('(b) with TRUSTED_PROXIES unset trusts nobody: not secure, the peer is the client, http URLs', function (): void {
    config(['trustedproxy.proxies' => tpConfigWith(null)]);

    $seen = tpProbe($this, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7']);

    expect($seen['secure'])->toBeFalse()
        ->and($seen['ip'])->toBe(TP_PEER)
        ->and($seen['url'])->toStartWith('http://');
});

it('(b) dev as booted (no TRUSTED_PROXIES in the test environment) trusts nobody', function (): void {
    expect(config('trustedproxy.proxies'))->toBeNull();

    $seen = tpProbe($this, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7']);

    expect($seen['secure'])->toBeFalse()
        ->and($seen['ip'])->toBe(TP_PEER);
});

it('(c) under REMOTE_ADDR takes the rightmost X-Forwarded-For entry: a client-sent left entry is ignored', function (): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    $seen = tpProbe($this, ['X-Forwarded-For' => '203.0.113.9, 198.51.100.7']);

    expect($seen['ip'])->toBe('198.51.100.7');
});

/** The host every test request is sent to (APP_URL's host). */
function tpHost(): string
{
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);
    expect($host)->toBeString()->not->toBe('evil.example');

    return (string) $host;
}

it('(d) never trusts X-Forwarded-Host or X-Forwarded-Prefix, even from a trusted peer', function (?string $proxies): void {
    config(['trustedproxy.proxies' => $proxies]);

    $seen = tpProbe($this, [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Prefix' => '/evil-prefix',
    ]);

    expect($seen['host'])->toBe(tpHost())
        ->and($seen['url'])->not->toContain('evil')
        ->and(parse_url($seen['url'], PHP_URL_HOST))->toBe(tpHost())
        ->and(parse_url($seen['url'], PHP_URL_PATH))->toBe('/v1/ready');
})->with([
    'REMOTE_ADDR' => ['REMOTE_ADDR'],
    'unset' => [null],
    '* (every hop)' => ['*'],
]);

it('(d) trusts X-Forwarded-Port only from a trusted peer', function (?string $proxies, bool $trusted): void {
    config(['trustedproxy.proxies' => $proxies]);

    $seen = tpProbe($this, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '8443']);

    if ($trusted) {
        expect($seen['url'])->toBe('https://'.tpHost().':8443/v1/ready');
    } else {
        expect($seen['url'])->toStartWith('http://')->not->toContain(':8443');
    }
})->with([
    'REMOTE_ADDR' => ['REMOTE_ADDR', true],
    'unset' => [null, false],
]);

/** @return array<string, string> */
function tpRegisterBody(): array
{
    return [
        'invite_code' => 'wrong',
        'email' => 'andres@example.com',
        'password' => Accounts::PASSWORD,
        'password_confirmation' => Accounts::PASSWORD,
    ];
}

/** Six registrations from the peer for one forwarded client, then the status the other client's first one gets. */
function tpRegisterTwoClients(TestCase $case): array
{
    $statuses = [];
    for ($i = 1; $i <= 6; $i++) {
        $statuses[] = $case->withServerVariables(['REMOTE_ADDR' => TP_PEER])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
            ->postJson('/register', tpRegisterBody())->getStatusCode();
    }
    $other = $case->withServerVariables(['REMOTE_ADDR' => TP_PEER])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.8'])
        ->postJson('/register', tpRegisterBody())->getStatusCode();

    return [$statuses, $other];
}

it('(e) under REMOTE_ADDR the register limiter (5/min, per IP) gives two forwarded clients separate buckets', function (): void {
    Accounts::setUp();
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    [$statuses, $other] = tpRegisterTwoClients($this);

    expect($statuses)->toBe([422, 422, 422, 422, 422, 429])
        ->and($other)->toBe(422);
});

it('(e) with TRUSTED_PROXIES unset every forwarded client shares the peer\'s one register bucket', function (): void {
    Accounts::setUp();
    config(['trustedproxy.proxies' => null]);

    [$statuses, $other] = tpRegisterTwoClients($this);

    expect($statuses)->toBe([422, 422, 422, 422, 422, 429])
        ->and($other)->toBe(429);
});

it('(f) under REMOTE_ADDR with SESSION_SECURE_COOKIE=true, GET /sanctum/csrf-cookie over forwarded https sets a Secure XSRF-TOKEN and a Secure session cookie', function (): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR', 'session.secure' => true]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => TP_PEER])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '198.51.100.7'])
        ->get('/sanctum/csrf-cookie');

    $response->assertNoContent();
    $xsrf = $response->getCookie('XSRF-TOKEN', decrypt: false);
    $session = $response->getCookie((string) config('session.cookie'), decrypt: false);
    expect($xsrf)->not->toBeNull()
        ->and($xsrf?->isSecure())->toBeTrue()
        ->and($xsrf?->isHttpOnly())->toBeFalse()
        ->and($session)->not->toBeNull()
        ->and($session?->isSecure())->toBeTrue()
        ->and($session?->isHttpOnly())->toBeTrue();
});

// How the XSRF-TOKEN Secure flag is decided: PreventRequestForgery builds the cookie with config('session.secure')
// (SESSION_SECURE_COOKIE); true or false is final, and null lets Symfony's Response::prepare() mark it Secure only
// when $request->isSecure(), i.e. only when the forwarded proto is trusted. One request per case: after a request the
// URL generator keeps that request's scheme, and the test client would build the next request URL with it.
it('(f) the XSRF-TOKEN Secure flag is session.secure when set; unset, it follows the trusted forwarded proto', function (?bool $secure, ?string $proxies, bool $expected): void {
    config(['session.secure' => $secure, 'trustedproxy.proxies' => $proxies]);

    $cookie = $this->withServerVariables(['REMOTE_ADDR' => TP_PEER])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('/sanctum/csrf-cookie')->getCookie('XSRF-TOKEN', decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isSecure())->toBe($expected);
})->with([
    'true, REMOTE_ADDR' => [true, 'REMOTE_ADDR', true],
    'true, unset' => [true, null, true],
    'unset, REMOTE_ADDR' => [null, 'REMOTE_ADDR', true],
    'unset, unset' => [null, null, false],
    'false, REMOTE_ADDR' => [false, 'REMOTE_ADDR', false],
]);

it('(h) under * every hop is trusted, so the LEFTMOST, client-chosen X-Forwarded-For entry becomes the IP (why * is never for production)', function (): void {
    config(['trustedproxy.proxies' => '*']);

    $seen = tpProbe($this, ['X-Forwarded-For' => '203.0.113.9, 198.51.100.7']);

    expect($seen['ip'])->toBe('203.0.113.9');
});
