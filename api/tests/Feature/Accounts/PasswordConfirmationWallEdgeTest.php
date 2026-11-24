<?php

declare(strict_types=1);

use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Problems\ProblemDetails;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Routes;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// Edge cases of the wall in front of the routes that need a recently confirmed password: which routes stand behind
// it (read from the route list, never from a list kept here alone), a timeout other than the default, and who is
// answered before the wall (a signed-out request, the read-only demo's writes).

beforeEach(function (): void {
    Accounts::setUp();
});

it('keeps every route that had the wall behind the app\'s own middleware, and each one answers the 423 problem', function (): void {
    $walled = collect(Routes::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('password.confirm', $route->gatherMiddleware(), true))
        ->values();
    $resolved = $walled->map(fn (Route $route): array => Routes::gatherRouteMiddleware($route));
    $user = Accounts::user(['email' => 'andres@example.com']);
    $as = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY);

    $answers = $walled->map(function (Route $route) use ($as): array {
        $response = $as->json($route->methods()[0], '/'.$route->uri());

        return [$response->getStatusCode(), $response->headers->get('Content-Type'), $response->json('type'), $response->json('instance')];
    })->all();

    expect($walled->map(fn (Route $route): string => $route->methods()[0].' /'.$route->uri())->sort()->values()->all())->toBe([
        'DELETE /user/two-factor-authentication',
        'GET /user/two-factor-qr-code',
        'GET /user/two-factor-recovery-codes',
        'GET /user/two-factor-secret-key',
        'POST /user/confirmed-two-factor-authentication',
        'POST /user/two-factor-authentication',
        'POST /user/two-factor-recovery-codes',
    ])
        ->and($resolved->every(fn (array $middleware): bool => in_array(RequirePasswordConfirmation::class, $middleware, true)))->toBeTrue()
        ->and($resolved->contains(fn (array $middleware): bool => in_array(RequirePassword::class, $middleware, true)))->toBeFalse()
        ->and($answers)->toBe($walled->map(fn (Route $route): array => [
            423, 'application/problem+json', ProblemDetails::BASE.'password-confirmation-required', '/'.$route->uri(),
        ])->all())
        ->and($user->refresh()->two_factor_secret)->toBeNull();
});

it('honours a timeout other than the default: a confirmation exactly as old as it passes, one second older is asked again', function (): void {
    config(['auth.password_timeout' => 60]);
    $user = Accounts::user(['email' => 'andres@example.com']);
    $as = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY);
    $as->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $as->postJson('/user/two-factor-authentication')->assertOk();

    $this->travel(60)->seconds();
    $atTheLimit = $as->getJson('/user/two-factor-secret-key');
    $this->travel(1)->seconds();
    $pastIt = $as->getJson('/user/two-factor-secret-key');
    $as->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $confirmedAgain = $as->getJson('/user/two-factor-secret-key');

    expect($atTheLimit->getStatusCode())->toBe(200)
        ->and($pastIt->getStatusCode())->toBe(423)
        ->and($pastIt->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($pastIt->json('type'))->toBe(ProblemDetails::BASE.'password-confirmation-required')
        ->and((string) $pastIt->getContent())->not->toContain('secretKey')
        ->and($confirmedAgain->getStatusCode())->toBe(200);
});

it('answers a signed-out request 401 and the read-only demo\'s write 403 before the wall, never 423', function (): void {
    $signedOut = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/user/two-factor-authentication');
    $signedOutRead = $this->withHeaders(Accounts::FIRST_PARTY)->getJson('/user/two-factor-recovery-codes');

    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $asDemo = AccountRows::signIn($this, $demo)->withHeaders(Accounts::FIRST_PARTY);
    $demoWrite = $asDemo->postJson('/user/two-factor-authentication');
    $demoConfirm = $asDemo->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD]);

    expect([$signedOut->getStatusCode(), $signedOutRead->getStatusCode()])->toBe([401, 401])
        ->and($signedOut->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and($signedOutRead->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and([$demoWrite->getStatusCode(), $demoConfirm->getStatusCode()])->toBe([403, 403])
        ->and($demoWrite->json('type'))->toBe(ProblemDetails::BASE.'demo-read-only')
        ->and($demoWrite->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and($demo->refresh()->two_factor_secret)->toBeNull();
});
