<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;

// A route that needs a recently confirmed password (the two-factor routes) answers 423 while the session has no
// confirmation. The answer is the app's problem document, password-confirmation-required, as every other error is:
// never the framework's plain {"message": "Password confirmation required."}. The confirmation lives in the session
// that made it, so another account's session is asked for its own.

beforeEach(function (): void {
    Accounts::setUp();
});

/** The 423 problem a route answers while this session has not confirmed the password. */
function pcpExpectProblem(TestResponse $response, string $path): void
{
    $body = $response->json();

    expect($response->getStatusCode())->toBe(423)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json')
        ->and(array_keys($body))->toBe(['type', 'title', 'status', 'instance', 'request_id'])
        ->and($body['type'])->toBe(ProblemDetails::BASE.'password-confirmation-required')
        ->and($body['title'])->toBe('Password Confirmation Required')
        ->and($body['status'])->toBe(423)
        ->and($body['instance'])->toBe($path)
        ->and($body['request_id'])->toBeString()->not->toBe('')
        ->and((string) $response->getContent())->not->toContain('Password confirmation required.')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
}

it('answers a route that needs a confirmed password with the 423 problem and no stock body', function (string $method, string $path): void {
    $user = Accounts::user(['email' => 'andres@example.com']);

    $response = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->json($method, $path);

    pcpExpectProblem($response, $path);
    expect($user->refresh()->two_factor_secret)->toBeNull();
})->with([
    'turn two-factor on' => ['POST', '/user/two-factor-authentication'],
    'turn two-factor off' => ['DELETE', '/user/two-factor-authentication'],
    'the QR code' => ['GET', '/user/two-factor-qr-code'],
    'the setup key' => ['GET', '/user/two-factor-secret-key'],
    'the recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
    'new recovery codes' => ['POST', '/user/two-factor-recovery-codes'],
]);

it('lets the route through once the password is confirmed, and asks again when the confirmation is older than auth.password_timeout', function (): void {
    $user = Accounts::user(['email' => 'andres@example.com']);
    $as = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY);

    pcpExpectProblem($as->postJson('/user/two-factor-authentication'), '/user/two-factor-authentication');
    $as->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $as->postJson('/user/two-factor-authentication')->assertOk();
    $timeout = (int) config('auth.password_timeout');
    $this->travel($timeout)->seconds();
    $atTheLimit = $as->getJson('/user/two-factor-qr-code');
    $this->travel(1)->seconds();
    $pastIt = $as->getJson('/user/two-factor-qr-code');

    expect($timeout)->toBe(10800)
        ->and($atTheLimit->getStatusCode())->toBe(200);
    pcpExpectProblem($pastIt, '/user/two-factor-qr-code');
});

it('asks a second account for its own confirmation after the first confirmed, and gives it its own answer', function (): void {
    $first = Accounts::user(['email' => 'first@example.com']);
    $second = Accounts::user(['email' => 'second@example.com']);

    $asFirst = AccountRows::signIn($this, $first)->withHeaders(Accounts::FIRST_PARTY);
    $firstLocked = $asFirst->postJson('/user/two-factor-authentication');
    $asFirst->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $asFirst->postJson('/user/two-factor-authentication')->assertOk();

    $asSecond = AccountRows::signIn($this, $second)->withHeaders(Accounts::FIRST_PARTY);
    $secondLocked = $asSecond->postJson('/user/two-factor-authentication');
    $secondCodes = $asSecond->getJson('/user/two-factor-recovery-codes');

    pcpExpectProblem($firstLocked, '/user/two-factor-authentication');
    pcpExpectProblem($secondLocked, '/user/two-factor-authentication');
    pcpExpectProblem($secondCodes, '/user/two-factor-recovery-codes');
    expect($secondLocked->json('request_id'))->not->toBe($firstLocked->json('request_id'))
        ->and((string) $secondLocked->getContent())->not->toContain('first@example.com')
        ->and($first->refresh()->two_factor_secret)->not->toBeNull()
        ->and($second->refresh()->two_factor_secret)->toBeNull();

    $asSecond->postJson('/user/confirm-password', ['password' => Accounts::PASSWORD])->assertStatus(201);
    $asSecond->postJson('/user/two-factor-authentication')->assertOk();
    expect($second->refresh()->two_factor_secret)->not->toBeNull();
});
