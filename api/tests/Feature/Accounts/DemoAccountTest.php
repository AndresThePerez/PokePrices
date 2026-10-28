<?php

declare(strict_types=1);

use App\Domain\Accounts\DemoAccount;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan P4-i1 T06 criteria 1 to 4 (D7, D8, R209): the idempotent demo seeder, one-click entry through
// POST /v1/demo/session, the read-only demo enforced on the server for every registered write route (enumerated
// from the router), and the demo's own rate tiers. Every /v1 response is also validated against openapi/api.json by
// Tests\TestCase (C3).

const DEMO_KEYS = ['base1-4:holo:unlimited:-', 'base1-4:holo:1st:-', 'ex5-17:reverse:-:-'];

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    // Runtime values only: whatever api/.env holds never reaches a test.
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** Runs the seeder as the dev database runs it and returns its console output. */
function demoSeed(): string
{
    $code = Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]);
    expect($code)->toBe(0);

    return Artisan::output();
}

function demoUser(): User
{
    return User::query()->where('is_demo', true)->firstOrFail();
}

/** Drops the guards and the in-memory session, so the next request authenticates from its session cookie alone. */
function demoForgetMemory(): void
{
    Accounts::forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

/** @return array<string, mixed> every row count a demo write could change, and the demo's own account fields */
function demoSnapshot(): array
{
    $counts = [];
    foreach (['users', 'watchlist_items', 'portfolio_holdings', 'password_reset_tokens', 'sessions'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts + [
        'demo' => (array) DB::table('users')->where('is_demo', true)->first(['email', 'password', 'email_verified_at', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'updated_at']),
        'watchlist' => DB::table('watchlist_items')->orderBy('id')->get(['user_id', 'variant_key'])->map(fn (object $r): array => (array) $r)->all(),
        'portfolio' => DB::table('portfolio_holdings')->orderBy('id')->get(['user_id', 'variant_key', 'quantity'])->map(fn (object $r): array => (array) $r)->all(),
    ];
}

it('seeds one verified demo user without two-factor, with exactly the configured rows that exist, idempotently', function (): void {
    AccountRows::variant('base1-4:holo:unlimited:-');
    AccountRows::variant('ex5-17:reverse:-:-');

    $first = demoSeed();
    $hash = demoUser()->password;
    $second = demoSeed();

    $demo = demoUser();
    expect(User::query()->where('is_demo', true)->count())->toBe(1)
        ->and($demo->email)->toBe('demo@example.com')
        ->and($demo->email_verified_at)->not->toBeNull()
        ->and($demo->two_factor_secret)->toBeNull()
        ->and($demo->two_factor_confirmed_at)->toBeNull()
        ->and($demo->password)->toBe($hash)
        ->and(AccountRows::watched($demo))->toBe(['base1-4:holo:unlimited:-', 'ex5-17:reverse:-:-'])
        ->and(AccountRows::held($demo))->toBe(['base1-4:holo:unlimited:-' => 1, 'ex5-17:reverse:-:-' => 3]);
    foreach ([$first, $second] as $output) {
        expect(substr_count($output, 'base1-4:holo:1st:-'))->toBe(1)
            ->and($output)->toContain('Skipped base1-4:holo:1st:-');
    }
});

it('runs on an empty database: the demo user, no rows, one console line per missing key', function (): void {
    $output = demoSeed();

    $demo = demoUser();
    $skipped = array_values(array_filter(explode("\n", $output), fn (string $line): bool => str_contains($line, 'Skipped ')));
    expect(AccountRows::watched($demo))->toBe([])
        ->and(AccountRows::held($demo))->toBe([])
        ->and($skipped)->toHaveCount(5);
    foreach (DEMO_KEYS as $key) {
        expect($output)->toContain($key);
    }
});

it('puts the demo back to the configured rows and state on a second run', function (): void {
    foreach (DEMO_KEYS as $key) {
        AccountRows::variant($key);
    }
    demoSeed();
    $demo = demoUser();
    AccountRows::watch($demo, 'zz1-1:holo:-:-');
    DB::table('watchlist_items')->where('user_id', $demo->id)->where('variant_key', 'ex5-17:reverse:-:-')->delete();
    DB::table('portfolio_holdings')->where('user_id', $demo->id)->update(['quantity' => 50]);
    DB::table('users')->where('id', $demo->id)->update(['email_verified_at' => null, 'two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()]);

    demoSeed();

    $demo = demoUser();
    expect(AccountRows::watched($demo))->toBe(['base1-4:holo:1st:-', 'base1-4:holo:unlimited:-', 'ex5-17:reverse:-:-'])
        ->and(AccountRows::held($demo))->toBe(['base1-4:holo:unlimited:-' => 1, 'ex5-17:reverse:-:-' => 3])
        ->and($demo->email_verified_at)->not->toBeNull()
        ->and($demo->two_factor_secret)->toBeNull()
        ->and($demo->two_factor_confirmed_at)->toBeNull();
});

it('stores a random 40-character password when DEMO_PASSWORD is unset: two fresh databases get different ones', function (): void {
    $one = DemoAccount::password();
    $two = DemoAccount::password();
    demoSeed();
    $firstHash = demoUser()->password;
    DB::table('users')->where('is_demo', true)->delete();
    demoSeed();
    $secondHash = demoUser()->password;

    expect(strlen($one))->toBe(40)
        ->and(strlen($two))->toBe(40)
        ->and($one)->not->toBe($two)
        ->and($secondHash)->not->toBe($firstHash)
        ->and(Hash::check('', $secondHash))->toBeFalse()
        ->and(Hash::isHashed($secondHash))->toBeTrue();
});

it('signs the demo in through POST /login when accounts.demo.password is set at runtime, and never prints it', function (): void {
    config(['accounts.demo.password' => 'fake-demo-password-0000']);

    $output = demoSeed();
    $login = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => 'demo@example.com', 'password' => 'fake-demo-password-0000']);

    $login->assertOk();
    expect($output)->not->toContain('fake-demo-password-0000');
    $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertOk()->assertJsonPath('data.read_only', true);
});

it('enters the demo with POST /v1/demo/session: 204, a new session id, and GET /v1/me says read_only', function (): void {
    config(['session.driver' => 'database']);
    demoForgetMemory();
    demoSeed();
    $cookie = (string) config('session.cookie');
    $csrf = $this->withHeaders(Accounts::FIRST_PARTY)->get('/sanctum/csrf-cookie');
    $before = (string) Accounts::sessionId($csrf);
    expect($before)->not->toBe('');

    demoForgetMemory();
    $enter = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $before)->postJson('/v1/demo/session');
    $after = (string) Accounts::sessionId($enter);

    expect($enter->getStatusCode())->toBe(204)
        ->and($enter->getContent())->toBe('')
        ->and($after)->not->toBe('')
        ->and($after)->not->toBe($before);

    demoForgetMemory();
    $me = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $after)->getJson('/v1/me');
    demoForgetMemory();
    $old = $this->withHeaders(Accounts::FIRST_PARTY)->withCredentials()->withCookie($cookie, $before)->getJson('/v1/me');

    expect($me->getStatusCode())->toBe(200)
        ->and($me->json('data'))->toBe(['email' => 'demo@example.com', 'email_verified' => true, 'two_factor' => false, 'read_only' => true])
        ->and($old->getStatusCode())->toBe(401);
});

it('answers 404 demo-unavailable when the demo is disabled or no demo user exists', function (bool $enabled, bool $seeded): void {
    config(['accounts.demo.enabled' => $enabled]);
    if ($seeded) {
        demoSeed();
    }

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session');

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'demo-unavailable')
        ->and($response->json('title'))->toBe('Demo Account Unavailable');
    $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertStatus(401);
})->with([
    'disabled, seeded' => [false, true],
    'enabled, no demo user' => [true, false],
]);

it('answers a request that is not first-party with 419 and signs nobody in', function (): void {
    demoSeed();

    $response = $this->withHeaders(['Accept' => 'application/json'])->postJson('/v1/demo/session');

    expect($response->getStatusCode())->toBe(419)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'csrf-token-mismatch');
});

it('answers the eleventh demo entry from one IP within a minute with 429; another IP is not throttled', function (): void {
    demoSeed();
    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.31'])->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session')->assertNoContent();
        Accounts::forgetGuards();
    }

    $eleventh = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.31'])->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.32'])->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session');

    expect($eleventh->getStatusCode())->toBe(429)
        ->and($eleventh->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($eleventh->headers->has('Retry-After'))->toBeTrue()
        ->and($eleventh->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($eleventh->headers->has('ETag'))->toBeFalse()
        ->and($other->getStatusCode())->toBe(204);
});

it('refuses every registered write route to the demo with 403 demo-read-only, except logout and demo.session, changing no row', function (): void {
    foreach (DEMO_KEYS as $key) {
        AccountRows::price($key, '10.00');
    }
    demoSeed();
    $demo = demoUser();
    Accounts::user(['email' => 'person@example.com']);
    $before = demoSnapshot();
    $body = [
        'email' => 'demo@example.com', 'password' => Accounts::PASSWORD, 'password_confirmation' => Accounts::PASSWORD,
        'current_password' => Accounts::PASSWORD, 'invite_code' => Accounts::INVITE, 'quantity' => 2, 'code' => '123456',
        'recovery_code' => 'example-recovery', 'token' => 'example-token',
    ];

    $answers = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'v1/__')) {
            continue;
        }
        foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn (array $m): string => match ($m[1]) {
                'variantKey' => 'base1-4:holo:unlimited:-',
                'id' => (string) $demo->id,
                default => 'example',
            }, $route->uri());
            Accounts::forgetGuards();
            $response = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->json($method, $uri, $body);
            $type = str_contains((string) $response->headers->get('Content-Type'), 'json') && $response->getContent() !== '' ? $response->json('type') : null;
            $answers[$method.' '.$route->uri()] = [$route->getName(), $response->getStatusCode(), $type];
        }
    }

    $refused = array_filter($answers, fn (array $a): bool => ! in_array($a[0], ['logout', 'demo.session'], true));
    $readOnly = [403, ProblemDetails::BASE.'demo-read-only'];
    expect(array_map(fn (array $a): array => [$a[1], $a[2]], $refused))->toBe(array_fill_keys(array_keys($refused), $readOnly))
        ->and($answers['POST logout'])->toBe(['logout', 204, null])
        ->and($answers['POST v1/demo/session'])->toBe(['demo.session', 204, null])
        ->and(array_keys($answers))->toContain(
            'PUT v1/watchlist/{variantKey}', 'DELETE v1/watchlist/{variantKey}', 'PUT v1/portfolio/{variantKey}',
            'DELETE v1/portfolio/{variantKey}', 'POST login', 'POST register', 'POST user/two-factor-authentication',
            'DELETE user/two-factor-authentication', 'POST user/confirm-password', 'POST email/verification-notification',
            'POST forgot-password', 'POST reset-password', 'POST two-factor-challenge',
        )
        ->and(demoSnapshot())->toBe($before);
});

it('answers the demo\'s GETs with 200', function (): void {
    foreach (DEMO_KEYS as $key) {
        AccountRows::price($key, '10.00');
    }
    demoSeed();
    $demo = demoUser();

    $me = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    $watchlist = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/watchlist');
    $portfolio = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/portfolio');

    expect([$me->getStatusCode(), $watchlist->getStatusCode(), $portfolio->getStatusCode()])->toBe([200, 200, 200])
        ->and($me->json('data.read_only'))->toBeTrue()
        ->and($watchlist->json('meta.count'))->toBe(3)
        ->and($portfolio->json('meta.total_value'))->toBe('40.00');
});

it('never lets a reset change the demo\'s password: 422 errors.email', function (): void {
    demoSeed();
    $demo = demoUser();
    $hash = $demo->password;
    $token = Password::broker()->createToken($demo);

    $response = $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'demo@example.com',
        'password' => 'brand-new-password-0', 'password_confirmation' => 'brand-new-password-0',
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['email'])
        ->and(demoUser()->password)->toBe($hash);
});

it('gives the demo its own tier, 30 a minute keyed by IP: two IPs each get their own 30', function (): void {
    demoSeed();
    $demo = demoUser();
    foreach (['192.0.2.41', '192.0.2.42'] as $ip) {
        for ($i = 0; $i < 30; $i++) {
            AccountRows::signIn($this, $demo)->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me')->assertOk();
        }
    }

    $spent = AccountRows::signIn($this, $demo)->withServerVariables(['REMOTE_ADDR' => '192.0.2.41'])->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');
    $fresh = AccountRows::signIn($this, $demo)->withServerVariables(['REMOTE_ADDR' => '192.0.2.43'])->withHeaders(Accounts::FIRST_PARTY)->getJson('/v1/me');

    expect($spent->getStatusCode())->toBe(429)
        ->and($spent->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($fresh->getStatusCode())->toBe(200);
});

it('answers POST /v1/demo/session with Cache-Control no-store, like every other account answer (Q1-i1 T04, A10)', function (): void {
    demoSeed();

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/demo/session');

    expect($response->getStatusCode())->toBe(204)
        ->and($response->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($response->headers->has('ETag'))->toBeFalse();
});
