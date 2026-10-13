<?php

declare(strict_types=1);

use App\Http\Middleware\ThrottleBeforeSession;
use App\Http\Problems\ProblemDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 2 (D1, D2), ruling R213 (a) and the D4 429 fix.

beforeEach(function (): void {
    Accounts::setUp();
});

it('configures Fortify headless with exactly the four D2 features', function (): void {
    expect(config('fortify.views'))->toBeFalse()
        ->and(config('fortify.features'))->toBe([
            Features::registration(),
            Features::resetPasswords(),
            Features::emailVerification(),
            Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
        ])
        ->and(config('fortify.home'))->toBe('/account')
        ->and(config('fortify.guard'))->toBe('web')
        ->and(config('fortify.passwords'))->toBe('users')
        ->and(config('fortify.username'))->toBe('email')
        ->and(config('fortify.email'))->toBe('email')
        ->and(config('fortify.prefix'))->toBe('')
        ->and(config('fortify.domain'))->toBeNull()
        ->and(config('fortify.middleware'))->toBe(['web'])
        ->and(config('fortify.limiters'))->toBe(['login' => 'login', 'two-factor' => 'two-factor', 'verification' => '6,1'])
        ->and(config('fortify.paths'))->toBe([])
        ->and(config('fortify.redirects'))->toBe([]);
});

it('configures Sanctum for cookie sessions only, stateful from SANCTUM_STATEFUL_DOMAINS', function (): void {
    $config = require config_path('sanctum.php');

    expect($config['stateful'])->toBe(explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', 'pokeprices.localhost:8095')))
        ->and($config['guard'])->toBe(['web'])
        ->and($config['expiration'])->toBeNull()
        ->and(file_get_contents(config_path('sanctum.php')))->toContain("env('SANCTUM_STATEFUL_DOMAINS', 'pokeprices.localhost:8095')")
        ->and(file_get_contents(base_path('bootstrap/app.php')))->not->toContain('statefulApi(')
        ->and(file_get_contents(base_path('bootstrap/app.php')))->not->toContain('redirectUsersTo(');
});

it('never reads an API token from the request', function (): void {
    $request = Request::create('/v1/me', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer 0000|example']);

    expect(Sanctum::$accessTokenRetrievalCallback)->not->toBeNull()
        ->and((Sanctum::$accessTokenRetrievalCallback)($request))->toBeNull();
});

it('registers no passkey, profile-update or password-update route and no passkey table (R213 a)', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r): string => $r->uri())->all();
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r): ?string => $r->getName())->filter()->all();

    expect(array_filter($uris, fn (string $u): bool => str_contains($u, 'passkey')))->toBe([])
        ->and($uris)->not->toContain('user/profile-information')
        ->and($uris)->not->toContain('user/password')
        ->and(array_filter($names, fn (string $n): bool => str_starts_with($n, 'passkey')))->toBe([])
        ->and($names)->not->toContain('user-profile-information.update')
        ->and($names)->not->toContain('user-password.update')
        ->and(glob(database_path('migrations/*passkey*')))->toBe([])
        ->and(Schema::hasTable('passkeys'))->toBeFalse();
    $this->putJson('/user/password', [])->assertNotFound();
    $this->putJson('/user/profile-information', [])->assertNotFound();
    $this->getJson('/passkeys/login/options')->assertNotFound();
});

it('registers the Fortify routes the SPA uses and no view routes', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r): ?string => $r->getName())->filter()->values()->all();

    expect($names)->toContain('login.store', 'logout', 'register.store', 'password.email', 'password.update',
        'verification.verify', 'verification.send', 'password.confirm.store', 'two-factor.login.store',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes', 'sanctum.csrf-cookie')
        ->and($names)->not->toContain('login', 'register', 'password.request', 'password.reset', 'verification.notice', 'two-factor.login');
});

it('answers GET /login with a problem, never a 500 (views off)', function (): void {
    $response = $this->getJson('/login');

    expect($response->getStatusCode())->toBe(405)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'method-not-allowed');
});

it('maps a ValidationException with status 429 to the 429 too-many-requests problem, never 422 (D4)', function (): void {
    Route::post('/v1/__lockout', function (): never {
        throw ValidationException::withMessages(['email' => ['Too many login attempts.']])->status(429);
    });

    $response = $this->postJson('/v1/__lockout');

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'too-many-requests')
        ->and($response->json('title'))->toBe('Too Many Requests')
        ->and($response->json('status'))->toBe(429);
});

it('keeps the account group middleware in order, account-guest first (D6)', function (): void {
    $route = Route::getRoutes()->getByName('me.show');

    expect($route)->not->toBeNull();
    $middleware = Route::gatherRouteMiddleware($route);
    $classes = array_map(fn (mixed $m): string => is_string($m) ? explode(':', $m, 2)[0] : 'closure', $middleware);
    $first = array_search(ThrottleBeforeSession::class, $classes, true);
    $stateful = array_search(EnsureFrontendRequestsAreStateful::class, $classes, true);

    expect($first)->toBe(0)->and($stateful)->toBeGreaterThan($first);
});
