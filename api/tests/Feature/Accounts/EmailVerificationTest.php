<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Accounts;

// Plan P4-i1 T02 criterion 6 (D2 verification link).

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
});

/** The decoded `link` of the SPA verification URL the mail carries: /email/verify/<id>/<hash>?expires=…&signature=… */
function verificationLink(User $user): string
{
    $url = (new VerifyEmail)->toMail($user)->actionUrl;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return is_string($query['link'] ?? null) ? $query['link'] : '';
}

it('verifies the signed-in person from the decoded link: 204, email_verified_at set, /v1/me says verified', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);
    $link = verificationLink($user);

    expect($link)->toMatch('#^/email/verify/'.$user->id.'/[0-9a-f]{40}\?expires=\d+&signature=[0-9a-f]{64}$#');

    $response = $this->actingAs($user)->getJson($link);

    expect($response->getStatusCode())->toBe(204)
        ->and($user->refresh()->email_verified_at)->not->toBeNull();
    $this->actingAs($user)->getJson('/v1/me')->assertOk()->assertJsonPath('data.email_verified', true);
});

it('answers a tampered signature with a 403 problem and verifies nothing', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);
    $link = verificationLink($user);
    $tampered = preg_replace('/signature=([0-9a-f])/', 'signature=0', $link, 1);
    $tampered = $tampered === $link ? preg_replace('/signature=0/', 'signature=1', $link, 1) : $tampered;

    $response = $this->actingAs($user)->getJson((string) $tampered);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($user->refresh()->email_verified_at)->toBeNull();
});

it('answers the link signed out with the 401 unauthenticated problem', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);

    $response = $this->getJson(verificationLink($user));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated')
        ->and($user->refresh()->email_verified_at)->toBeNull();
});

it('sends one more verification mail on POST /email/verification-notification (202)', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);

    $response = $this->actingAs($user)->postJson('/email/verification-notification');

    expect($response->getStatusCode())->toBe(202);
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('sends no mail when the address is already verified (Fortify answers 204)', function (): void {
    $user = Accounts::user();

    $response = $this->actingAs($user)->postJson('/email/verification-notification');

    expect($response->getStatusCode())->toBe(204);
    Notification::assertNothingSent();
});
