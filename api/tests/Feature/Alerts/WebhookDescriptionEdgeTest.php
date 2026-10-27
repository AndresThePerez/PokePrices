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

// Plan Q1-i2 T03 (Q1-i1 T04 review Low): bytes that are not UTF-8 answer 422, not a database error.
it('refuses a form-encoded description that is not UTF-8 with 422 and writes nothing', function (): void {
    AccountRows::signIn($this, Accounts::user());

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->post('/v1/webhooks', [
        'kind' => 'standard', 'url' => T4E_URL, 'description' => "bad\n\xC3\x28bytes",
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['description'])
        ->and($response->json('errors.description'))->toBe(['A description must be UTF-8 text.'])
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});

// Reviewer edge cases for plan Q1-i2 T03: every kind of broken UTF-8 answers 422 before any other description rule.
const T3E_UTF8 = 'A description must be UTF-8 text.';

it('refuses every shape of broken UTF-8 with only the UTF-8 message, ahead of the length and control rules', function (string $bytes): void {
    AccountRows::signIn($this, Accounts::user());

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->post('/v1/webhooks', [
        'kind' => 'standard', 'url' => T4E_URL, 'description' => $bytes,
    ]);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['description'])
        ->and($response->json('errors.description'))->toBe([T3E_UTF8])
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
})->with([
    'overlong NUL' => ["a\xC0\x80b"],
    'surrogate half' => ["a\xED\xA0\x80b"],
    'past U+10FFFF' => ["a\xF4\x90\x80\x80"],
    'truncated three-byte' => ["ab\xE2\x82"],
    'lone continuation' => ["\x80"],
    'bare 0xFF' => ["\xFF"],
    'too long and broken' => [str_repeat('a', 150)."\xC3\x28"],
    'broken with a bidi override' => ["a\u{202E}\xC3\x28"],
]);

it('answers a raw JSON body that is not UTF-8 with 422 and writes nothing', function (): void {
    AccountRows::signIn($this, Accounts::user());

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->call('POST', '/v1/webhooks', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], '{"kind":"standard","url":"'.T4E_URL."\",\"description\":\"bad\xC3\x28bytes\"}");

    expect($response->getStatusCode())->toBe(422)
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});

it('answers an escaped lone surrogate in a JSON description with 422 and writes nothing', function (): void {
    AccountRows::signIn($this, Accounts::user());

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->call('POST', '/v1/webhooks', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], '{"kind":"standard","url":"'.T4E_URL.'","description":"a\\ud800b"}');

    expect($response->getStatusCode())->toBe(422)
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});

it('stores valid multibyte descriptions byte for byte and leaves a NUL byte to the control-character rule', function (): void {
    foreach (['é', '日本語のメモ', "e\u{301}", '👍🏽 ok'] as $text) {
        $response = t4eStore($this, ['description' => $text]);
        $response->assertCreated();
        expect($response->json('data.description'))->toBe($text);
    }

    $nul = t4eStore($this, ['description' => "a\0b"]);
    expect($nul->getStatusCode())->toBe(422)
        ->and($nul->json('errors.description'))->toBe([T4E_MESSAGE]);
});

it('answers broken UTF-8 in the kind or url field with 422 on that field, never a 500', function (string $field): void {
    AccountRows::signIn($this, Accounts::user());
    $body = ['kind' => 'standard', 'url' => T4E_URL];
    $body[$field] .= "\xC3\x28";

    $response = $this->withHeaders(Accounts::FIRST_PARTY)->post('/v1/webhooks', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe([$field])
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
})->with(['kind', 'url']);
