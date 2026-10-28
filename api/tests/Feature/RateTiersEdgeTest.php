<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountRateLimits;
use App\Domain\Alerts\AlertRateLimits;
use App\Domain\Catalog\RateLimits;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use App\Support\NamedRateLimiters;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Q1-i5 T03 review tests: the shared registrar and user key at the edges the characterisation does not reach (an id of
// 0, a string id, a forwarded header, a long address), a registration made twice, and the keys end to end through the
// router.

beforeEach(function (): void {
    Accounts::setUp();
});

function reUser(int $id, bool $demo = false): User
{
    $user = new User;
    $user->forceFill(['id' => $id, 'is_demo' => $demo]);

    return $user;
}

it('keys a signed-in request user:<id> for an id of 0, and any other request ip:<ip>, even with a forwarded header', function (?User $user, array $server, string $expected): void {
    $request = Request::create('/v1/example', 'GET', server: $server);
    $request->setUserResolver(fn (): ?User => $user);

    expect(NamedRateLimiters::userKey($request))->toBe($expected);
})->with([
    'id 0' => [fn (): User => reUser(0), ['REMOTE_ADDR' => '192.0.2.30'], 'user:0'],
    'a guest' => [null, ['REMOTE_ADDR' => '192.0.2.30'], 'ip:192.0.2.30'],
    'a guest behind an untrusted forwarded header' => [null, ['REMOTE_ADDR' => '192.0.2.30', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'], 'ip:192.0.2.30'],
    'a guest with no address' => [null, ['REMOTE_ADDR' => ''], 'ip:'],
    'a guest on an IPv6 address' => [null, ['REMOTE_ADDR' => '2001:db8::1'], 'ip:2001:db8::1'],
]);

it('answers the same limits however often the registrars run: three registrations before and after the limiter is built change nothing', function (): void {
    $request = Request::create('/v1/example', 'GET', server: ['REMOTE_ADDR' => '192.0.2.30']);
    $request->setUserResolver(fn (): User => reUser(7, true));
    $before = [];
    foreach (['search', 'account', 'webhook-test', 'login'] as $tier) {
        $limit = app(RateLimiter::class)->limiter($tier)($request);
        $before[$tier] = [$limit->maxAttempts, $limit->decaySeconds, $limit->key];
    }

    for ($i = 0; $i < 3; $i++) {
        RateLimits::register();
        AccountRateLimits::register();
        AlertRateLimits::register();
    }
    app()->forgetInstance(RateLimiter::class);
    $after = [];
    foreach (['search', 'account', 'webhook-test', 'login'] as $tier) {
        $limit = app(RateLimiter::class)->limiter($tier)($request);
        $after[$tier] = [$limit->maxAttempts, $limit->decaySeconds, $limit->key];
    }

    expect($after)->toBe($before)
        ->and($after['account'])->toBe([30, 60, 'demo:192.0.2.30'])
        ->and($after['webhook-test'])->toBe([5, 60, 'user:7']);
});

it('registers a tier closure per tier, not one that every tier shares: the limits differ and each keeps its own name', function (): void {
    $seen = [];
    NamedRateLimiters::register(['q1-a' => 3, 'q1-b' => 9], function (string $tier, int $perMinute, Request $request) use (&$seen): Limit {
        $seen[] = [$tier, $perMinute];

        return Limit::perMinute($perMinute)->by($tier.'|'.NamedRateLimiters::userKey($request));
    });
    $request = Request::create('/v1/example', 'GET', server: ['REMOTE_ADDR' => '192.0.2.30']);
    $limiter = app(RateLimiter::class);

    $b = $limiter->limiter('q1-b')($request);
    $a = $limiter->limiter('q1-a')($request);

    expect([$a->maxAttempts, $a->key])->toBe([3, 'q1-a|ip:192.0.2.30'])
        ->and([$b->maxAttempts, $b->key])->toBe([9, 'q1-b|ip:192.0.2.30'])
        ->and($seen)->toBe([['q1-b', 9], ['q1-a', 3]]);
});

it('throttles password-confirm per person, not per address: one user\'s spent five never throttles another on the same IP, and the 429 keeps its problem shape', function (): void {
    $first = Accounts::user(['email' => 'first@example.com']);
    $second = Accounts::user(['email' => 'second@example.com']);
    $server = ['REMOTE_ADDR' => '192.0.2.77'];
    $delete = fn (User $user) => AccountRows::signIn($this, $user)->withServerVariables($server)->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/v1/me/deletion', ['password' => 'not-the-password-0000']);

    for ($i = 0; $i < 5; $i++) {
        expect($delete($first)->getStatusCode())->toBe(422);
    }
    $sixth = $delete($first);
    $other = $delete($second);

    expect($sixth->getStatusCode())->toBe(429)
        ->and($sixth->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($sixth->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($sixth->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and((int) $sixth->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)
        ->and((int) $sixth->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
        ->and($other->getStatusCode())->toBe(422);
});
