<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\IgnoreQueryCredentials;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\Accounts;
use Tests\TestCase;

// Review round 1 of the trusted-proxies task: values TRUSTED_PROXIES passes through unchanged, header variants,
// IPv6, the ignored Forwarded header, per-client rate-limit buckets and the session cookie's Secure flag.
// Addresses are RFC 5737 / RFC 3849 documentation ranges only.

const TPR_PEER = '192.0.2.10';

const TPR_PROBE = '/__trusted-proxy-review-probe';

beforeEach(function (): void {
    Route::get(TPR_PROBE, fn (Request $request) => response()->json([
        'secure' => $request->isSecure(),
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'port' => $request->getPort(),
        'url' => url('/v1/ready'),
    ]));
});

afterEach(function (): void {
    Request::setTrustedProxies([], -1);
});

/**
 * @param  array<string, string>  $headers
 * @return array{secure: bool, ip: string, host: string, port: int, url: string}
 */
function tprProbe(TestCase $case, array $headers, string $peer = TPR_PEER): array
{
    /** @var array{secure: bool, ip: string, host: string, port: int, url: string} $seen */
    $seen = $case->withServerVariables(['REMOTE_ADDR' => $peer])->withHeaders($headers)->getJson(TPR_PROBE)
        ->assertOk()->json();

    return $seen;
}

it('passes every TRUSTED_PROXIES spelling through to the peer decision: who is trusted and who is nobody', function (string $proxies, string $peer, string $expectedIp): void {
    config(['trustedproxy.proxies' => $proxies]);

    $seen = tprProbe($this, ['X-Forwarded-For' => '203.0.113.9, 198.51.100.7'], $peer);

    expect($seen['ip'])->toBe($expectedIp);
})->with([
    'a CIDR holding the peer' => ['192.0.2.0/24', TPR_PEER, '198.51.100.7'],
    'a CIDR not holding the peer' => ['198.51.100.0/24', TPR_PEER, TPR_PEER],
    'a list with spaces' => ['REMOTE_ADDR, 198.51.100.99', TPR_PEER, '198.51.100.7'],
    'a trailing comma' => ['REMOTE_ADDR,', TPR_PEER, '198.51.100.7'],
    'an empty string trusts nobody' => ['', TPR_PEER, TPR_PEER],
    'a lone comma trusts nobody' => [',', TPR_PEER, TPR_PEER],
    'a single other address trusts nobody here' => ['192.0.2.99', TPR_PEER, TPR_PEER],
]);

it('ignores the RFC 7239 Forwarded header even from a trusted peer: client, scheme and host stay the peer\'s', function (): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    $seen = tprProbe($this, ['Forwarded' => 'for=203.0.113.50;proto=https;host=evil.example']);

    expect($seen['ip'])->toBe(TPR_PEER)
        ->and($seen['secure'])->toBeFalse()
        ->and($seen['host'])->not->toBe('evil.example')
        ->and($seen['url'])->toStartWith('http://')->not->toContain('evil');
});

it('reads only a real https value of X-Forwarded-Proto as secure under REMOTE_ADDR', function (string $proto, bool $secure): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    expect(tprProbe($this, ['X-Forwarded-Proto' => $proto])['secure'])->toBe($secure);
})->with([
    'https' => ['https', true],
    'upper case HTTPS' => ['HTTPS', true],
    'http' => ['http', false],
    'garbage' => ['javascript', false],
    'empty' => ['', false],
]);

it('keeps the default port out of generated URLs under forwarded https with port 443, and never trusts a forwarded host beside it', function (): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    $seen = tprProbe($this, [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Host' => 'evil.example:8095',
    ]);

    expect($seen['url'])->toStartWith('https://')->not->toContain(':443')->not->toContain('evil')
        ->and($seen['port'])->toBe(443);
});

it('resolves forwarded IPv6 clients and drops bracket and port decoration, garbage entries and the trusted peer', function (string $peer, string $xff, string $expected): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    expect(tprProbe($this, ['X-Forwarded-For' => $xff], $peer)['ip'])->toBe($expected);
})->with([
    'ipv6 client, ipv4 peer' => [TPR_PEER, '2001:db8::1', '2001:db8::1'],
    'ipv6 peer, ipv6 client' => ['2001:db8::10', '2001:db8::1', '2001:db8::1'],
    'left spoof, right ipv6 client' => [TPR_PEER, '203.0.113.9, 2001:db8::1', '2001:db8::1'],
    'bracketed ipv6 with a port' => [TPR_PEER, '[2001:db8::1]:5555', '2001:db8::1'],
    'ipv4 with a port' => [TPR_PEER, '198.51.100.7:4711', '198.51.100.7'],
    'garbage only falls back to the peer' => [TPR_PEER, 'unknown', TPR_PEER],
    'garbage on the right falls back to the left entry' => [TPR_PEER, '203.0.113.9, unknown', '203.0.113.9'],
]);

/** Six registrations from the peer, each with its own X-Forwarded-For; the statuses come back. */
function tprRegisterSix(TestCase $case, array $forwardedFor): array
{
    $statuses = [];
    foreach ($forwardedFor as $xff) {
        $statuses[] = $case->withServerVariables(['REMOTE_ADDR' => TPR_PEER])
            ->withHeaders(['X-Forwarded-For' => $xff])
            ->postJson('/register', [
                'invite_code' => 'wrong',
                'email' => 'andres@example.com',
                'password' => Accounts::PASSWORD,
                'password_confirmation' => Accounts::PASSWORD,
            ])->getStatusCode();
    }

    return $statuses;
}

it('keeps one register bucket for one real client across six different spoofed leftmost X-Forwarded-For entries under REMOTE_ADDR', function (): void {
    Accounts::setUp();
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);

    $statuses = tprRegisterSix($this, array_map(
        static fn (int $i): string => "203.0.113.{$i}, 198.51.100.7",
        [1, 2, 3, 4, 5, 6],
    ));

    expect($statuses)->toBe([422, 422, 422, 422, 422, 429]);
});

it('limits POST /v1/demo/session per forwarded client under REMOTE_ADDR: a second client has its own demo-session bucket', function (): void {
    Accounts::setUp();
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);
    $limit = (int) config('accounts.rate_limits.demo-session');
    expect($limit)->toBeGreaterThan(0);

    $first = [];
    for ($i = 0; $i < $limit + 2; $i++) {
        $first[] = $this->withServerVariables(['REMOTE_ADDR' => TPR_PEER])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
            ->postJson('/v1/demo/session')->getStatusCode();
    }
    $other = $this->withServerVariables(['REMOTE_ADDR' => TPR_PEER])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.8'])
        ->postJson('/v1/demo/session')->getStatusCode();

    expect($first)->toContain(429)
        ->and($other)->not->toBe(429);
});

it('marks the session cookie Secure from the trusted forwarded proto when SESSION_SECURE_COOKIE is unset, and not from an untrusted one', function (?string $proxies, bool $expected): void {
    config(['session.secure' => null, 'trustedproxy.proxies' => $proxies]);

    $response = $this->withServerVariables(['REMOTE_ADDR' => TPR_PEER])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('/sanctum/csrf-cookie');

    $session = $response->getCookie((string) config('session.cookie'), decrypt: false);
    expect($session)->not->toBeNull()
        ->and($session?->isSecure())->toBe($expected);
})->with([
    'REMOTE_ADDR' => ['REMOTE_ADDR', true],
    'unset' => [null, false],
]);

it('keeps the client IP out of the two prepended middleware, which run before TrustProxies and would see the peer, not the client', function (): void {
    $global = (fn (): array => $this->middleware)->call(app(Kernel::class));
    $trust = array_search(TrustProxies::class, $global, true);
    expect($trust)->toBeInt();

    foreach ([AssignRequestId::class, IgnoreQueryCredentials::class] as $early) {
        $position = array_search($early, $global, true);
        if ($position !== false && $position < $trust) {
            $source = (string) file_get_contents((string) (new ReflectionClass($early))->getFileName());
            expect($source)->not->toContain('->ip()')->not->toContain('getClientIp')->not->toContain('->ips()');
        }
    }
});
