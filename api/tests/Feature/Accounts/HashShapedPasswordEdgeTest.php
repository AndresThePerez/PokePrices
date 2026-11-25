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

use function Pest\Laravel\postJson;

// Edge cases for explicit hashing at sign-up, at reset and for the demo account: other bcrypt costs, a string that
// only has the shape of a bcrypt hash, the 72-byte limit, multi-byte characters and idempotent demo re-seeds.

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    config(['accounts.demo.enabled' => true, 'accounts.demo.email' => 'demo@example.com', 'accounts.demo.password' => null]);
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function edgeSignIn(string $email, string $password): TestResponse
{
    Auth::guard('web')->logout();
    Accounts::forgetGuards();

    return postJson('/login', ['email' => $email, 'password' => $password], Accounts::FIRST_PARTY);
}

function edgeRegister(string $email, string $password): TestResponse
{
    Auth::guard('web')->logout();
    Accounts::forgetGuards();

    return postJson('/register', [
        'invite_code' => Accounts::INVITE, 'email' => $email,
        'password' => $password, 'password_confirmation' => $password,
    ], Accounts::FIRST_PARTY);
}

it('signs up with bcrypt shapes at costs 6 and 12 and a made-up 60-character $2y$12$ string, and signs in with each', function (): void {
    $shapes = [
        password_hash('fake-throwaway-0000', PASSWORD_BCRYPT, ['cost' => 6]),
        password_hash('fake-throwaway-0000', PASSWORD_BCRYPT, ['cost' => 12]),
        '$2y$12$'.str_repeat('a', 53),
    ];

    foreach ($shapes as $i => $input) {
        expect(strlen($input))->toBe(60);
        $email = "shape{$i}@example.com";
        expect(edgeRegister($email, $input)->getStatusCode())->toBe(201);
        $stored = User::query()->where('email', $email)->sole()->password;
        expect($stored)->not->toBe($input)
            ->and(Hash::check($input, $stored))->toBeTrue()
            ->and(edgeSignIn($email, $input)->getStatusCode())->toBe(200);
    }
});

it('does not store a hash-shaped password as given: the plain word behind a typed hash cannot sign in', function (): void {
    $input = password_hash('fake-throwaway-0000', PASSWORD_BCRYPT, ['cost' => 4]);

    expect(edgeRegister('behind@example.com', $input)->getStatusCode())->toBe(201);

    expect(edgeSignIn('behind@example.com', 'fake-throwaway-0000')->getStatusCode())->not->toBe(200)
        ->and(edgeSignIn('behind@example.com', $input)->getStatusCode())->toBe(200);
});

it('resets to a made-up $2y$12$ shape: the old password stops working and the typed shape signs in', function (): void {
    $user = Accounts::user(['email' => 'reset@example.com']);
    $token = Password::broker()->createToken($user);
    $input = '$2y$12$'.str_repeat('b', 53);

    $response = $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'reset@example.com', 'password' => $input, 'password_confirmation' => $input,
    ]);

    expect($response->getStatusCode())->toBe(200);
    $stored = $user->refresh()->password;
    expect($stored)->not->toBe($input)
        ->and(Hash::check($input, $stored))->toBeTrue()
        ->and(edgeSignIn('reset@example.com', Accounts::PASSWORD)->getStatusCode())->not->toBe(200)
        ->and(edgeSignIn('reset@example.com', $input)->getStatusCode())->toBe(200);
});

it('accepts exactly 72 bytes and 72 bytes of multi-byte characters at sign-up and at reset, and refuses 73 bytes', function (): void {
    $ascii = str_repeat('a', 72);
    $multi = str_repeat("\u{00e9}", 36);
    expect(strlen($multi))->toBe(72);

    expect(edgeRegister('ascii@example.com', $ascii)->getStatusCode())->toBe(201);
    expect(edgeSignIn('ascii@example.com', $ascii)->getStatusCode())->toBe(200);
    expect(edgeRegister('multi@example.com', $multi)->getStatusCode())->toBe(201);
    expect(edgeSignIn('multi@example.com', $multi)->getStatusCode())->toBe(200);
    expect(edgeRegister('over@example.com', $ascii.'a')->getStatusCode())->toBe(422);

    $user = Accounts::user(['email' => 'resetmb@example.com']);
    $token = Password::broker()->createToken($user);
    $reset = $this->postJson('/reset-password', [
        'token' => $token, 'email' => 'resetmb@example.com', 'password' => $multi, 'password_confirmation' => $multi,
    ]);
    expect($reset->getStatusCode())->toBe(200);
    expect(Hash::check($multi, $user->refresh()->password))->toBeTrue()
        ->and(edgeSignIn('resetmb@example.com', $multi)->getStatusCode())->toBe(200);
});

it('stores one bcrypt hash of the input that needs no rehash and is not a hash of a hash', function (): void {
    $input = Accounts::PASSWORD;
    expect(edgeRegister('once@example.com', $input)->getStatusCode())->toBe(201);
    $stored = User::query()->sole()->password;

    expect(Hash::info($stored)['algoName'])->toBe('bcrypt')
        ->and(Hash::needsRehash($stored))->toBeFalse()
        ->and(password_verify($input, $stored))->toBeTrue()
        ->and(Hash::check($stored, $stored))->toBeFalse();
});

it('keeps sign-in to the demo working across repeated seeds, with a random and with a configured password', function (): void {
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    $random = User::query()->where('is_demo', true)->sole()->password;
    expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    expect(User::query()->where('is_demo', true)->sole()->password)->toBe($random);

    config(['accounts.demo.password' => 'fake-demo-password-0000']);
    foreach ([1, 2] as $_) {
        expect(Artisan::call('db:seed', ['--class' => DemoAccountSeeder::class, '--no-interaction' => true]))->toBe(0);
    }
    $stored = User::query()->where('is_demo', true)->sole()->password;
    expect(Hash::check('fake-demo-password-0000', $stored))->toBeTrue()
        ->and(Hash::needsRehash($stored))->toBeFalse()
        ->and(edgeSignIn('demo@example.com', 'fake-demo-password-0000')->getStatusCode())->toBe(200);
});
