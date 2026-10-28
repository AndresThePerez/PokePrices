<?php

declare(strict_types=1);

use App\Domain\Accounts\AccountRateLimits;
use App\Domain\Alerts\AlertRateLimits;
use App\Domain\Catalog\RateLimits;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\Accounts;

// Q1-i5 T03 characterisation: the three rate-tier registrars (catalog, account, alert) give every tier the same
// maxAttempts, decay and key for a guest, a user, the demo user and a pending two-factor sign-in, before and after
// they share one registrar.

beforeEach(function (): void {
    Accounts::setUp();
});

/** A request from 192.0.2.30 with an email in the body, signed in as $user, holding $pending as login.id. */
function rtRequest(?User $user, ?int $pending = null): Request
{
    $request = Request::create('/v1/example', 'POST', ['email' => ' Person@Example.com '], server: ['REMOTE_ADDR' => '192.0.2.30']);
    $request->setUserResolver(fn (): ?User => $user);
    if ($pending !== null) {
        $session = new Store('rate-tiers', new ArraySessionHandler(10));
        $session->put('login.id', $pending);
        $request->setLaravelSession($session);
    }

    return $request;
}

function rtUser(int $id, bool $demo): User
{
    $user = new User;
    $user->forceFill(['id' => $id, 'is_demo' => $demo]);

    return $user;
}

/** @return array<string, array{int, string}> maxAttempts and key, by tier */
function rtLimits(Request $request): array
{
    $limiter = app(RateLimiter::class);
    $out = [];
    foreach (['public-read', 'search', 'login', 'two-factor', 'register', 'account-guest', 'password-confirm', 'account', 'account-demo', 'demo-session', 'webhook-test'] as $tier) {
        $callback = $limiter->limiter($tier);
        expect($callback)->toBeInstanceOf(Closure::class, $tier);
        $limit = $callback($request);
        expect($limit)->toBeInstanceOf(Limit::class, $tier)
            ->and($limit->decaySeconds)->toBe(60, $tier);
        $out[$tier] = [$limit->maxAttempts, $limit->key];
    }

    return $out;
}

it('gives every tier of the three registrars today\'s limit and key for a guest, a user, the demo user and a pending two-factor sign-in', function (?User $user, ?int $pending, array $expected): void {
    expect(rtLimits(rtRequest($user, $pending)))->toBe($expected);
})->with([
    'a guest' => [null, null, [
        'public-read' => [120, '192.0.2.30'],
        'search' => [60, '192.0.2.30'],
        'login' => [5, 'person@example.com|192.0.2.30'],
        'two-factor' => [5, 'login:'],
        'register' => [5, '192.0.2.30'],
        'account-guest' => [60, '192.0.2.30'],
        'password-confirm' => [5, 'ip:192.0.2.30'],
        'account' => [120, 'ip:192.0.2.30'],
        'account-demo' => [30, 'demo:192.0.2.30'],
        'demo-session' => [10, '192.0.2.30'],
        'webhook-test' => [5, 'ip:192.0.2.30'],
    ]],
    'a user' => [fn (): User => rtUser(7, false), null, [
        'public-read' => [120, '192.0.2.30'],
        'search' => [60, '192.0.2.30'],
        'login' => [5, 'person@example.com|192.0.2.30'],
        'two-factor' => [5, 'login:'],
        'register' => [5, '192.0.2.30'],
        'account-guest' => [60, '192.0.2.30'],
        'password-confirm' => [5, 'user:7'],
        'account' => [120, 'user:7'],
        'account-demo' => [30, 'demo:192.0.2.30'],
        'demo-session' => [10, '192.0.2.30'],
        'webhook-test' => [5, 'user:7'],
    ]],
    'the demo user' => [fn (): User => rtUser(9, true), null, [
        'public-read' => [120, '192.0.2.30'],
        'search' => [60, '192.0.2.30'],
        'login' => [5, 'person@example.com|192.0.2.30'],
        'two-factor' => [5, 'login:'],
        'register' => [5, '192.0.2.30'],
        'account-guest' => [60, '192.0.2.30'],
        'password-confirm' => [5, 'user:9'],
        'account' => [30, 'demo:192.0.2.30'],
        'account-demo' => [30, 'demo:192.0.2.30'],
        'demo-session' => [10, '192.0.2.30'],
        'webhook-test' => [5, 'user:9'],
    ]],
    'a pending two-factor sign-in' => [null, 42, [
        'public-read' => [120, '192.0.2.30'],
        'search' => [60, '192.0.2.30'],
        'login' => [5, 'person@example.com|192.0.2.30'],
        'two-factor' => [5, 'login:42'],
        'register' => [5, '192.0.2.30'],
        'account-guest' => [60, '192.0.2.30'],
        'password-confirm' => [5, 'ip:192.0.2.30'],
        'account' => [120, 'ip:192.0.2.30'],
        'account-demo' => [30, 'demo:192.0.2.30'],
        'demo-session' => [10, '192.0.2.30'],
        'webhook-test' => [5, 'ip:192.0.2.30'],
    ]],
]);

it('names the limiters on a RateLimiter the container already holds as well as on one it builds later', function (): void {
    $bare = new RateLimiter(app('cache')->driver('array'));
    app()->instance(RateLimiter::class, $bare);
    $before = $bare->limiter('search');

    RateLimits::register();
    AccountRateLimits::register();
    AlertRateLimits::register();
    $request = rtRequest(rtUser(7, false));
    $held = rtLimits($request);
    app()->forgetInstance(RateLimiter::class);
    $later = rtLimits($request);

    expect($before)->toBeNull()
        ->and(app(RateLimiter::class))->not->toBe($bare)
        ->and($held)->toBe($later)
        ->and($held['search'])->toBe([60, '192.0.2.30'])
        ->and($held['account'])->toBe([120, 'user:7'])
        ->and($held['webhook-test'])->toBe([5, 'user:7']);
});

arch('the support classes never reach into the HTTP layer')
    ->expect('App\Support')
    ->not->toUse('App\Http');
