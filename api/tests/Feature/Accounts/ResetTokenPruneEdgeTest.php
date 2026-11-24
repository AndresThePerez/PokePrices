<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Support\Accounts;

// Edge case of the daily reset-token prune: a token the broker really issued, a minute short of its 60, still resets
// the password after the prune has run, and one a minute past is gone and resets nothing. Passwords here are fakes.

beforeEach(function (): void {
    Accounts::setUp();
});

it('leaves a reset token that is still good usable after the prune, and removes one that is past its hour', function (): void {
    $late = Accounts::user(['email' => 'late@example.com']);
    $live = Accounts::user(['email' => 'live@example.com']);
    $new = 'another-fake-password-1';

    $lateToken = Password::broker()->createToken($late);
    $this->travel(2)->minutes();
    $liveToken = Password::broker()->createToken($live);
    $this->travel(59)->minutes();
    $code = Artisan::call('auth:clear-resets');
    $rows = DB::table('password_reset_tokens')->orderBy('email')->pluck('email')->all();

    $lateAnswer = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/reset-password', ['token' => $lateToken, 'email' => 'late@example.com', 'password' => $new, 'password_confirmation' => $new]);
    $liveAnswer = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/reset-password', ['token' => $liveToken, 'email' => 'live@example.com', 'password' => $new, 'password_confirmation' => $new]);

    expect($code)->toBe(0)
        ->and($rows)->toBe(['live@example.com'])
        ->and($lateAnswer->getStatusCode())->toBe(422)
        ->and(Hash::check(Accounts::PASSWORD, $late->refresh()->password))->toBeTrue()
        ->and($liveAnswer->getStatusCode())->toBe(200)
        ->and(Hash::check($new, $live->refresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);
});
