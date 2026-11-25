<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts;

use function Pest\Laravel\withServerVariables;

// Review tests for the per-address sign-in limit across emails (accounts.rate_limits.login-address = 20 a minute).

beforeEach(function (): void {
    Accounts::setUp();
    $this->freezeTime();
});

afterEach(function (): void {
    // Symfony keeps the trusted proxies in static state; leave nothing trusted for the next test in this process.
    Request::setTrustedProxies([], -1);
});

/**
 * One sign-in attempt from an address.
 *
 * @param  array<string, string>  $headers
 */
function lbSignIn(string $ip, mixed $email, bool $right = false, array $headers = []): TestResponse
{
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders($headers)->postJson('/login', ['email' => $email, 'password' => $right ? Accounts::PASSWORD : strrev(Accounts::PASSWORD)]);
}

it('refuses a correct sign-in as the 21st attempt from one address and signs nobody in', function (): void {
    Accounts::user(['email' => 'andres@example.com']);
    for ($i = 1; $i <= 20; $i++) {
        expect(lbSignIn('192.0.2.50', 'spray'.$i.'@example.com')->getStatusCode())->toBe(422);
    }

    $right = lbSignIn('192.0.2.50', 'andres@example.com', right: true);

    expect($right->getStatusCode())->toBe(429)
        ->and($right->headers->get('X-RateLimit-Limit'))->toBe('20')
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('counts one email in different letter case and with spaces as one per-email bucket and each attempt once toward the address', function (): void {
    $variants = ['andres@example.com', 'ANDRES@example.com', ' andres@example.com ', 'Andres@Example.com', "andres@example.com\t"];
    $statuses = [];
    foreach ($variants as $email) {
        $statuses[] = lbSignIn('192.0.2.51', $email)->getStatusCode();
    }
    $sixth = lbSignIn('192.0.2.51', '  ANDRES@EXAMPLE.COM');
    $rest = [];
    for ($i = 1; $i <= 15; $i++) {
        $rest[] = lbSignIn('192.0.2.51', 'other'.$i.'@example.com')->getStatusCode();
    }
    $over = lbSignIn('192.0.2.51', 'other16@example.com');

    expect($statuses)->toBe(array_fill(0, 5, 422))
        ->and($sixth->getStatusCode())->toBe(429)
        ->and($sixth->headers->get('X-RateLimit-Limit'))->toBe('5')
        ->and($rest)->toBe(array_fill(0, 15, 422))
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->headers->get('X-RateLimit-Limit'))->toBe('20');
});

it('does not let a client-set X-Forwarded-For reset the address count when no proxy is trusted', function (): void {
    for ($i = 1; $i <= 20; $i++) {
        lbSignIn('192.0.2.52', 'spray'.$i.'@example.com', headers: ['X-Forwarded-For' => '198.51.100.'.$i]);
    }

    $over = lbSignIn('192.0.2.52', 'spray21@example.com', headers: ['X-Forwarded-For' => '203.0.113.99', 'X-Real-IP' => '203.0.113.98', 'Forwarded' => 'for=203.0.113.97']);

    expect($over->getStatusCode())->toBe(429);
});

it('keys the address count by the rightmost X-Forwarded-For entry when only the immediate peer is trusted', function (): void {
    config(['trustedproxy.proxies' => 'REMOTE_ADDR']);
    Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);

    for ($i = 1; $i <= 20; $i++) {
        lbSignIn('192.0.2.10', 'spray'.$i.'@example.com', headers: ['X-Forwarded-For' => '198.51.100.'.$i.', 192.0.2.53']);
    }
    $sameClient = lbSignIn('192.0.2.10', 'spray21@example.com', headers: ['X-Forwarded-For' => '203.0.113.7, 192.0.2.53']);
    $otherClient = lbSignIn('192.0.2.10', 'spray21@example.com', headers: ['X-Forwarded-For' => '203.0.113.7, 192.0.2.54']);

    expect($sameClient->getStatusCode())->toBe(429)
        ->and($otherClient->getStatusCode())->toBe(422);
});

it('answers the per-email 429 identically for an email that exists and one that does not when the address limit is also met', function (): void {
    Accounts::user(['email' => 'andres@example.com']);
    for ($i = 1; $i <= 5; $i++) {
        lbSignIn('192.0.2.54', 'andres@example.com');
        lbSignIn('192.0.2.54', 'nobody@example.com');
    }
    // 10 counted attempts so far; the refused sixth ones are not counted. Bring the address to its 20.
    for ($i = 1; $i <= 10; $i++) {
        lbSignIn('192.0.2.54', 'spray'.$i.'@example.com');
    }

    $known = lbSignIn('192.0.2.54', 'andres@example.com');
    $unknown = lbSignIn('192.0.2.54', 'nobody@example.com');
    $fresh = lbSignIn('192.0.2.54', 'fresh@example.com');

    $strip = static fn (TestResponse $r): array => [
        $r->getStatusCode(),
        array_diff_key((array) $r->json(), ['request_id' => 1]),
        $r->headers->get('X-RateLimit-Limit'),
        $r->headers->get('X-RateLimit-Remaining'),
        $r->headers->get('Content-Type'),
    ];

    expect($known->getStatusCode())->toBe(429)
        ->and($known->headers->get('X-RateLimit-Limit'))->toBe('5')
        ->and($strip($unknown))->toBe($strip($known))
        ->and($fresh->getStatusCode())->toBe(429)
        ->and($fresh->headers->get('X-RateLimit-Limit'))->toBe('20');
});

it('counts requests with a non-string email toward the address limit without an error', function (): void {
    $arrays = [];
    for ($i = 1; $i <= 5; $i++) {
        $arrays[] = withServerVariables(['REMOTE_ADDR' => '192.0.2.55'])->postJson('/login', ['email' => ['a'.$i], 'password' => 'x'])->getStatusCode();
    }
    $rest = [];
    for ($i = 1; $i <= 15; $i++) {
        $rest[] = lbSignIn('192.0.2.55', 'other'.$i.'@example.com')->getStatusCode();
    }
    $over = lbSignIn('192.0.2.55', 'other16@example.com');

    expect($arrays)->toBe(array_fill(0, 5, 422))
        ->and($rest)->toBe(array_fill(0, 15, 422))
        ->and($over->getStatusCode())->toBe(429);
});

it('still limits the address at the 59th second and frees it after the minute for the next attempt', function (): void {
    for ($i = 1; $i <= 20; $i++) {
        lbSignIn('192.0.2.56', 'spray'.$i.'@example.com');
    }
    $this->travel(59)->seconds();
    $early = lbSignIn('192.0.2.56', 'spray21@example.com');
    $this->travel(2)->seconds();
    $after = lbSignIn('192.0.2.56', 'spray22@example.com');

    expect($early->getStatusCode())->toBe(429)
        ->and($after->getStatusCode())->toBe(422);
});
