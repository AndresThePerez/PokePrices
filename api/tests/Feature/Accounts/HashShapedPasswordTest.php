<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// A password typed in the shape of a bcrypt hash is a password like any other: sign-up, reset and the demo account's
// configured password store a hash of it, so the person signs in with exactly what they typed. The cost-4 shape is
// one the model's hashed cast would keep as given (the test hasher's own cost); the cost-10 shape is one it would
// refuse with an exception. An argon2 shape is longer than 72 characters and is refused by validation first.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

/** A real 60-character bcrypt string of a throwaway word, made at run time. */
function hashShapedPassword(int $cost): string
{
    $shape = password_hash('fake-throwaway-0000', PASSWORD_BCRYPT, ['cost' => $cost]);
    expect(strlen($shape))->toBe(60)->and(Hash::isHashed($shape))->toBeTrue();

    return $shape;
}

/** Signs out and drops the guards, then signs in through POST /login. */
function hashShapedSignIn(string $email, string $password): TestResponse
{
    Auth::guard('web')->logout();
    Accounts::forgetGuards();

    return test()->withHeaders(Accounts::FIRST_PARTY)->postJson('/login', ['email' => $email, 'password' => $password]);
}

dataset('hash shapes', ['bcrypt at the test cost (4)' => [4], 'bcrypt at cost 10' => [10]]);

it('signs up with a hash-shaped password: stored hashed, verifies, and signs in with it', function (int $cost): void {
    $input = hashShapedPassword($cost);

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => 'shape@example.com',
        'password' => $input, 'password_confirmation' => $input,
    ]);

    expect($response->getStatusCode())->toBe(201);
    $stored = User::query()->sole()->password;
    expect($stored)->not->toBe($input)
        ->and(Hash::check($input, $stored))->toBeTrue()
        ->and(hashShapedSignIn('shape@example.com', $input)->getStatusCode())->toBe(200);
})->with('hash shapes');

it('resets to a hash-shaped password: stored hashed, verifies, and signs in with it', function (int $cost): void {
    $user = Accounts::user(['email' => 'shape@example.com']);
    $token = Password::broker()->createToken($user);
    $input = hashShapedPassword($cost);

    $response = $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'shape@example.com', 'password' => $input, 'password_confirmation' => $input,
    ]);

    expect($response->getStatusCode())->toBe(200);
    $stored = $user->refresh()->password;
    expect($stored)->not->toBe($input)
        ->and(Hash::check($input, $stored))->toBeTrue()
        ->and(hashShapedSignIn('shape@example.com', $input)->getStatusCode())->toBe(200);
})->with('hash shapes');

it('seeds the demo with a hash-shaped configured password, on creation and on a re-seed, without an exception', function (int $cost): void {
    $input = hashShapedPassword($cost);
    config(['accounts.demo.password' => $input]);

    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    $first = User::query()->where('is_demo', true)->sole()->password;
    expect($first)->not->toBe($input)->and(Hash::check($input, $first))->toBeTrue();

    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    expect(User::query()->where('is_demo', true)->sole()->password)->toBe($first)
        ->and(hashShapedSignIn('demo@example.com', $input)->getStatusCode())->toBe(200);
})->with('hash shapes');

it('repairs an existing demo to a hash-shaped configured password on a re-seed', function (int $cost): void {
    config(['accounts.demo.password' => 'fake-demo-password-0000']);
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);

    $input = hashShapedPassword($cost);
    config(['accounts.demo.password' => $input]);
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);

    $stored = User::query()->where('is_demo', true)->sole()->password;
    expect($stored)->not->toBe($input)
        ->and(Hash::check($input, $stored))->toBeTrue()
        ->and(Hash::check('fake-demo-password-0000', $stored))->toBeFalse()
        ->and(hashShapedSignIn('demo@example.com', $input)->getStatusCode())->toBe(200);
})->with('hash shapes');
