<?php

declare(strict_types=1);

use App\Domain\Alerts\AlertThresholds;
use App\Domain\Alerts\CatalogName;
use App\Domain\Pricing\MovementRule;
use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan Q1-i4 T01 review round 1: edges of the alert threshold rules moved into Domain (request-time floor, ceiling,
// validation before the watchlist check, ownership), of MovementRule's text and floor, and of the Discord list marker
// after leading whitespace.

const Q1R_KEY = 'base1-4:holo:unlimited:-';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function q1rPut(object $test, User $user, mixed $pct): TestResponse
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/alerts/'.Q1R_KEY, ['pct' => $pct]);
}

/** PUT with the JSON text written by hand, so "100.0" and "1e2" reach the server as sent. */
function q1rPutRaw(object $test, User $user, string $json): TestResponse
{
    AccountRows::signIn($test, $user);

    return $test->call('PUT', '/v1/alerts/'.Q1R_KEY, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_ORIGIN' => Accounts::ORIGIN,
        'HTTP_REFERER' => Accounts::ORIGIN.'/',
    ], $json);
}

/** @return array<string, int|null> */
function q1rThresholds(User $user): array
{
    /** @var array<string, int|null> */
    return DB::table('watchlist_items')->where('user_id', $user->id)->orderBy('variant_key')->pluck('alert_pct', 'variant_key')->all();
}

it('checks pct before the watchlist: a bad pct on an unwatched key shows only the pct range, a good one only the watchlist text', function (): void {
    $user = Accounts::user();

    $bad = q1rPut($this, $user, 4);
    $good = q1rPut($this, $user, 50);

    expect($bad->getStatusCode())->toBe(422)
        ->and($bad->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 5 to 100.']])
        ->and($good->getStatusCode())->toBe(422)
        ->and($good->json('errors'))->toBe(['variant_key' => ['Add this variant to your watchlist first.']])
        ->and(DB::table('watchlist_items')->where('user_id', $user->id)->count())->toBe(0);
});

it('reads the floor at request time: the range follows config between two calls on one app, down to 1 and up to 100', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, Q1R_KEY);

    config(['pricing.movement.pct' => '0.40']);
    $zero = q1rPut($this, $user, 0);
    $one = q1rPut($this, $user, 1);
    config(['pricing.movement.pct' => '100.00']);
    $ninetyNine = q1rPut($this, $user, 99);
    $hundred = q1rPut($this, $user, 100);
    config(['pricing.movement.pct' => '5.00']);
    $overHundred = q1rPut($this, $user, 101);

    expect($zero->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 1 to 100.']])
        ->and($one->getStatusCode())->toBe(200)
        ->and($ninetyNine->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 100 to 100.']])
        ->and($hundred->getStatusCode())->toBe(200)
        ->and($overHundred->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 5 to 100.']])
        ->and(q1rThresholds($user))->toBe([Q1R_KEY => 100]);
});

it('refuses a number that only looks whole: 100.0, 1e2, an overflowing integer and a numeric string keep the same message and change nothing', function (string $json): void {
    $user = Accounts::user();
    AccountRows::watch($user, Q1R_KEY);
    q1rPut($this, $user, 40)->assertOk();

    $response = q1rPutRaw($this, $user, $json);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 5 to 100.']])
        ->and(q1rThresholds($user))->toBe([Q1R_KEY => 40]);
})->with([
    'float 100.0' => ['{"pct":100.0}'],
    'float 5.0' => ['{"pct":5.0}'],
    'exponent 1e2' => ['{"pct":1e2}'],
    'beyond int64' => ['{"pct":9223372036854775808}'],
    'string 100' => ['{"pct":"100"}'],
    'string with space' => ['{"pct":" 10"}'],
    'empty string' => ['{"pct":""}'],
]);

it('answers 403 before it looks at pct: an unverified or demo account sending a bad pct gets the account problem, not 422', function (): void {
    $unverified = Accounts::user(['email_verified_at' => null]);
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);

    $a = q1rPut($this, $unverified, 4);
    $b = q1rPut($this, $demo, 'ten');

    expect([$a->getStatusCode(), $a->json('type')])->toBe([403, ProblemDetails::BASE.'email-not-verified'])
        ->and([$b->getStatusCode(), $b->json('type')])->toBe([403, ProblemDetails::BASE.'demo-read-only']);
});

it('keeps another person\'s threshold when the caller does not watch the key: PUT is 422 and DELETE is 204, the owner still has 30', function (): void {
    $caller = Accounts::user();
    $owner = Accounts::user(['email' => 'owner@example.com']);
    AccountRows::watch($owner, Q1R_KEY);
    q1rPut($this, $owner, 30)->assertOk();

    $put = q1rPut($this, $caller, 30);
    $delete = AccountRows::signIn($this, $caller)->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/alerts/'.Q1R_KEY);

    expect($put->json('errors'))->toBe(['variant_key' => ['Add this variant to your watchlist first.']])
        ->and($delete->getStatusCode())->toBe(204)
        ->and(q1rThresholds($owner))->toBe([Q1R_KEY => 30])
        ->and(DB::table('watchlist_items')->where('user_id', $caller->id)->count())->toBe(0);
});

it('sets and clears one row through the domain service: another variant and another person are untouched, an unwatched key throws on the variant key', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    AccountRows::watch($user, Q1R_KEY);
    AccountRows::watch($user, 'ex5-17:reverse:-:-');
    AccountRows::watch($other, Q1R_KEY);
    $service = app(AlertThresholds::class);

    $service->set($user->id, Q1R_KEY, 12);
    $service->set($other->id, Q1R_KEY, 60);
    $service->clear($user->id, Q1R_KEY);
    $service->clear($user->id, Q1R_KEY);
    $service->clear($user->id, 'base1-2:holo:-:-');
    $service->set($user->id, 'ex5-17:reverse:-:-', 7);

    $thrown = null;
    try {
        $service->set($user->id, 'base1-2:holo:-:-', 10);
    } catch (ValidationException $e) {
        $thrown = $e->errors();
    }

    expect($thrown)->toBe(['variant_key' => ['Add this variant to your watchlist first.']])
        ->and(q1rThresholds($user))->toBe([Q1R_KEY => null, 'ex5-17:reverse:-:-' => 7])
        ->and(q1rThresholds($other))->toBe([Q1R_KEY => 60]);
});

it('writes the movement text without eating whole-number zeros and reads a non-numeric rule as a floor of 5', function (): void {
    $texts = [];
    foreach (['5', '5.0', '10.00', '100', '1000', '100.00', '0.50', '0.00', '12.340'] as $pct) {
        config(['pricing.movement.pct' => $pct]);
        $texts[$pct] = MovementRule::pctText();
    }
    $floors = [];
    foreach (['abc', '', '-3', '0', '0.00', '1e1'] as $pct) {
        config(['pricing.movement.pct' => $pct]);
        $floors[$pct] = MovementRule::alertFloor();
    }

    expect($texts)->toBe(['5' => '5', '5.0' => '5', '10.00' => '10', '100' => '100', '1000' => '1000', '100.00' => '100', '0.50' => '0.5', '0.00' => '0', '12.340' => '12.34'])
        ->and($floors)->toBe(['abc' => 5, '' => 5, '-3' => 1, '0' => 1, '0.00' => 1, '1e1' => 10]);
});

it('escapes a list marker after any run of leading horizontal whitespace, keeps the whitespace, and leaves a marker that is not leading alone', function (string $name, string $discord): void {
    expect(CatalogName::forDiscord($name))->toBe($discord);
})->with([
    'em space then dash' => ["\u{2003}- x", "\u{2003}\\- x"],
    'ideographic space then plus' => ["\u{3000}+ x", "\u{3000}\\+ x"],
    'no-break space then number' => ["\u{A0}12. x", "\u{A0}12\\. x"],
    'mixed spaces then number' => [" \u{A0} 3. x", " \u{A0} 3\\. x"],
    'marker only' => [' -', ' \-'],
    'number dot only' => ['  7.', '  7\.'],
    'whitespace only' => ['   ', '   '],
    'dash after a word' => ['Pikachu - V', 'Pikachu - V'],
    'dash after space and a word' => [' x - y', ' x - y'],
    'number after a word' => [' V 1. x', ' V 1. x'],
    'newline before the marker is dropped first, so it leads' => ["x\n- y", 'x- y'],
    'already escaped dash' => [' \\- x', ' \\\\- x'],
    'second marker is not rewritten' => [' - 1. x', ' \- 1. x'],
]);

it('does not fail on text that is not UTF-8: a marker after spaces is still escaped and the bytes are kept', function (): void {
    $out = CatalogName::forDiscord(" - \xFF");
    $number = CatalogName::forDiscord("  4. \xFE");

    expect($out)->toBe(" \\- \xFF")
        ->and($number)->toBe("  4\\. \xFE");
});
