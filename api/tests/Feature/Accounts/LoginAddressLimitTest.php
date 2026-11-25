<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts;

use function Pest\Laravel\withServerVariables;

// Sign-in attempts per client address across emails: 20 a minute (accounts.rate_limits.login-address), on top of the
// login limit of 5 per email and address.

beforeEach(function (): void {
    Accounts::setUp();
    $this->freezeTime();
});

/** One sign-in attempt from an address, with the right password or a wrong one. */
function laSignIn(string $ip, string $email, bool $right = false): TestResponse
{
    Auth::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    return withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/login', ['email' => $email, 'password' => $right ? Accounts::PASSWORD : strrev(Accounts::PASSWORD)]);
}

/**
 * Wrong sign-ins from an address, each with its own email, and their statuses.
 *
 * @return list<int>
 */
function laSpray(string $ip, int $count, string $prefix = 'spray'): array
{
    $statuses = [];
    for ($i = 1; $i <= $count; $i++) {
        $statuses[] = laSignIn($ip, $prefix.$i.'@example.com')->getStatusCode();
    }

    return $statuses;
}

/**
 * A 429's status, body and headers, with the per-request values left out: the Date, the request id (in the body and
 * in any header) and the cookie values (cookie names stay).
 *
 * @return array<string, mixed>
 */
function laShape(TestResponse $response): array
{
    $body = (array) $response->json();
    $requestId = $body['request_id'] ?? null;
    unset($body['request_id']);
    $headers = array_filter($response->headers->all(), fn (array $values): bool => $values !== [$requestId]);
    unset($headers['date'], $headers['set-cookie']);
    ksort($headers);
    $cookies = array_map(fn ($cookie): string => $cookie->getName(), $response->headers->getCookies());
    sort($cookies);

    return ['status' => $response->getStatusCode(), 'body' => $body, 'headers' => $headers, 'cookies' => $cookies];
}

it('answers the first 20 sign-ins from one address with different emails as now and the 21st with the 429 problem', function (): void {
    $first = laSpray('192.0.2.40', 20);
    $over = laSignIn('192.0.2.40', 'spray21@example.com');

    expect(config('accounts.rate_limits.login-address'))->toBe(20)
        ->and($first)->toBe(array_fill(0, 20, 422))
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($over->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and((int) $over->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $over->headers->get('Retry-After'))->toBeLessThanOrEqual(60);
});

it('answers over the address limit identically for an email that exists, with a right or wrong password, and one that does not', function (): void {
    Accounts::user(['email' => 'andres@example.com']);
    laSpray('192.0.2.41', 20);

    $missing = laSignIn('192.0.2.41', 'nobody@example.com');
    $wrong = laSignIn('192.0.2.41', 'andres@example.com');
    $right = laSignIn('192.0.2.41', 'andres@example.com', right: true);

    expect($missing->getStatusCode())->toBe(429)
        ->and(laShape($wrong))->toBe(laShape($missing))
        ->and(laShape($right))->toBe(laShape($missing))
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('leaves one person\'s ordinary mistakes to the per-email limit, and a correct sign-in from another address unaffected', function (): void {
    Accounts::user(['email' => 'andres@example.com']);
    $mistakes = [];
    for ($i = 1; $i <= 5; $i++) {
        $mistakes[] = laSignIn('192.0.2.42', 'andres@example.com')->getStatusCode();
    }
    $sixth = laSignIn('192.0.2.42', 'andres@example.com');
    $elsewhere = laSignIn('192.0.2.43', 'andres@example.com', right: true);

    // The five mistakes are attempts from 192.0.2.42; the refused sixth is not: 15 more emails reach its 20.
    $rest = laSpray('192.0.2.42', 15);
    $over = laSignIn('192.0.2.42', 'late@example.com');

    expect($mistakes)->toBe(array_fill(0, 5, 422))
        ->and($sixth->getStatusCode())->toBe(429)
        ->and($sixth->headers->get('X-RateLimit-Limit'))->toBe('5')
        ->and($elsewhere->getStatusCode())->toBe(200)
        ->and($rest)->toBe(array_fill(0, 15, 422))
        ->and($over->getStatusCode())->toBe(429)
        ->and($over->headers->get('X-RateLimit-Limit'))->toBe('20');
});

it('gives another address its own count and resets the count after a minute', function (): void {
    laSpray('192.0.2.44', 20);
    $over = laSignIn('192.0.2.44', 'spray21@example.com');
    $otherAddress = laSignIn('192.0.2.45', 'spray21@example.com');

    $this->travel(61)->seconds();
    $afterMinute = laSignIn('192.0.2.44', 'spray22@example.com');

    expect($over->getStatusCode())->toBe(429)
        ->and($otherAddress->getStatusCode())->toBe(422)
        ->and($afterMinute->getStatusCode())->toBe(422);
});

it('counts a successful sign-in as an attempt', function (): void {
    Accounts::user(['email' => 'andres@example.com']);

    $success = laSignIn('192.0.2.46', 'andres@example.com', right: true);
    $rest = laSpray('192.0.2.46', 19);
    $over = laSignIn('192.0.2.46', 'spray20@example.com');

    expect($success->getStatusCode())->toBe(200)
        ->and($rest)->toBe(array_fill(0, 19, 422))
        ->and($over->getStatusCode())->toBe(429);
});

it('does not count the two-factor challenge or the demo button toward the address limit', function (): void {
    $other = [];
    for ($i = 1; $i <= 6; $i++) {
        $other[] = withServerVariables(['REMOTE_ADDR' => '192.0.2.47'])->postJson('/two-factor-challenge', ['code' => '000000'])->getStatusCode();
        $other[] = withServerVariables(['REMOTE_ADDR' => '192.0.2.47'])->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session')->getStatusCode();
    }
    $first = laSpray('192.0.2.47', 20);
    $over = laSignIn('192.0.2.47', 'spray21@example.com');

    expect($other)->not->toContain(500)
        ->and($first)->toBe(array_fill(0, 20, 422))
        ->and($over->getStatusCode())->toBe(429);
});
