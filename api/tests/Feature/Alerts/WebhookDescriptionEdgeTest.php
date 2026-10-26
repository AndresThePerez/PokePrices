<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\FakeHostResolver;

// Reviewer edge cases for plan Q1-i1 T04 (review a1, sec B2): the webhook description rule at its boundaries, with
// the nullable and type rules still ahead of it.

const T4E_URL = 'https://hooks.example.com/pokeprices';
const T4E_MESSAGE = 'A description cannot hold control or text-direction characters.';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    Http::preventStrayRequests();
    FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10']]);
});

/** @param array<string, mixed> $body */
function t4eStore(object $test, array $body): TestResponse
{
    $user = User::query()->first() ?? Accounts::user();

    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)
        ->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => T4E_URL] + $body);
}

it('still accepts a missing, null and blank description (nullable stays ahead of the new rule)', function (array $body): void {
    $response = t4eStore($this, $body);

    $response->assertCreated();
    expect($response->json('data.description'))->toBeNull();
})->with([
    'missing' => [[]],
    'null' => [['description' => null]],
    'an empty string' => [['description' => '']],
]);

it('measures the 100-character limit in characters and still refuses a control character at the limit', function (): void {
    $emoji = str_repeat("\u{1F600}", 100);
    $ok = t4eStore($this, ['description' => $emoji]);
    $ok->assertCreated();
    expect($ok->json('data.description'))->toBe($emoji);

    $control = t4eStore($this, ['description' => str_repeat('a', 99)."\u{202E}"]);
    expect($control->getStatusCode())->toBe(422)
        ->and($control->json('errors.description'))->toBe([T4E_MESSAGE]);

    $tooLong = t4eStore($this, ['description' => str_repeat('a', 101)]);
    expect($tooLong->getStatusCode())->toBe(422)
        ->and($tooLong->json('errors.description'))->not->toBe([T4E_MESSAGE]);
});

it('answers a non-string description with the type error, not the character message and not a 500', function (mixed $value): void {
    $response = t4eStore($this, ['description' => $value]);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['description'])
        ->and($response->json('errors.description'))->not->toBe([T4E_MESSAGE]);
})->with([
    'an array holding a newline' => [["a\nb"]],
    'an integer' => [123],
    'an object' => [['k' => "a\u{202E}"]],
]);

it('refuses a description whose control character is a form-encoded value too', function (): void {
    AccountRows::signIn($this, Accounts::user());

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->post('/v1/webhooks', [
        'kind' => 'standard', 'url' => T4E_URL, 'description' => "line1\nline2",
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});
