<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan P5-i1 T05 criteria 2 and 4 (D9, System Design §2 "thresholds per watchlist item"): PUT and DELETE
// /v1/alerts/{variantKey} set and clear a whole-percent threshold on one of the caller's own watchlist rows, never
// below the movement rule; GET /v1/watchlist shows it as alert_pct. Only a verified, non-demo account may set one.
// Every /v1 response is also validated against openapi/api.json by Tests\TestCase (C3).

const T5_KEY = 'base1-4:holo:unlimited:-';
const T5_OTHER_KEY = 'ex5-17:reverse:-:-';
const T5_PCT = 'Choose a whole percentage from 5 to 100.';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

function t5As(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

function t5Put(object $test, User $user, string $path, mixed $body): TestResponse
{
    return t5As($test, $user)->putJson('/v1/alerts/'.$path, is_array($body) ? $body : []);
}

/** @return array<string, int|null> variant key => alert_pct of the user's watchlist rows */
function t5Thresholds(User $user): array
{
    /** @var array<string, int|null> */
    return DB::table('watchlist_items')->where('user_id', $user->id)->orderBy('variant_key')->pluck('alert_pct', 'variant_key')->all();
}

it('sets a threshold on one watchlist row: 200 with the key and pct, and GET /v1/watchlist shows it on that row only', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);
    AccountRows::watch($user, T5_OTHER_KEY);

    $put = t5Put($this, $user, T5_KEY, ['pct' => 10]);
    $list = t5As($this, $user)->getJson('/v1/watchlist');

    expect($put->getStatusCode())->toBe(200)
        ->and($put->json())->toBe(['data' => ['variant_key' => T5_KEY, 'alert_pct' => 10]])
        ->and(array_column($list->json('data'), 'alert_pct', 'variant_key'))->toBe([T5_KEY => 10, T5_OTHER_KEY => null])
        ->and(array_key_last($list->json('data.0')))->toBe('alert_pct');
});

it('changes a threshold that is already set, and accepts 5 and 100', function (int $pct): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);
    t5Put($this, $user, T5_KEY, ['pct' => 50])->assertOk();

    $put = t5Put($this, $user, T5_KEY, ['pct' => $pct]);

    expect($put->json('data.alert_pct'))->toBe($pct)
        ->and(t5Thresholds($user))->toBe([T5_KEY => $pct]);
})->with([5, 20, 100]);

it('refuses a pct that is not a whole number from 5 to 100 with 422 errors.pct and changes nothing', function (mixed $body): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);

    $response = t5Put($this, $user, T5_KEY, $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('type'))->toBe(ProblemDetails::BASE.'validation-failed')
        ->and($response->json('errors'))->toBe(['pct' => [T5_PCT]])
        ->and(t5Thresholds($user))->toBe([T5_KEY => null]);
})->with([
    '4' => [['pct' => 4]],
    '0' => [['pct' => 0]],
    '-10' => [['pct' => -10]],
    '101' => [['pct' => 101]],
    '7.5' => [['pct' => 7.5]],
    'string 10' => [['pct' => '10']],
    'null' => [['pct' => null]],
    'true' => [['pct' => true]],
    'array' => [['pct' => [10]]],
    'missing' => [[]],
]);

it('renders the floor from config: a movement rule of 7.00 % gives "from 7 to 100" and refuses 6', function (): void {
    config(['pricing.movement.pct' => '7.00']);
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);

    $six = t5Put($this, $user, T5_KEY, ['pct' => 6]);
    $seven = t5Put($this, $user, T5_KEY, ['pct' => 7]);

    expect($six->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 7 to 100.']])
        ->and($seven->getStatusCode())->toBe(200);
});

it('rounds a fractional movement rule up: 5.50 % gives a floor of 6', function (): void {
    config(['pricing.movement.pct' => '5.50']);
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);

    expect(t5Put($this, $user, T5_KEY, ['pct' => 5])->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 6 to 100.']])
        ->and(t5Put($this, $user, T5_KEY, ['pct' => 6])->getStatusCode())->toBe(200);
});

it('refuses a key that is not on the caller\'s watchlist with 422 "Add this variant to your watchlist first."', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    AccountRows::watch($other, T5_KEY);
    AccountRows::hold($user, T5_KEY, 1);

    $response = t5Put($this, $user, T5_KEY, ['pct' => 10]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['variant_key' => ['Add this variant to your watchlist first.']])
        ->and(t5Thresholds($other))->toBe([T5_KEY => null]);
});

it('refuses a malformed key with 422 errors.variant_key before any query', function (string $path): void {
    $user = Accounts::user();
    t5As($this, $user)->getJson('/v1/me')->assertOk();
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        if (preg_match('/watchlist_items|card_variants|notification_settings/', $q->sql) === 1) {
            $queries[] = $q->sql;
        }
    });

    $response = t5Put($this, $user, $path, ['pct' => 10]);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('errors'))->toBe(['variant_key' => ['PokéPrices has no variant with this key.']])
        ->and($queries)->toBe([]);
})->with([
    'no colons' => ['base1-4'],
    'bad finish' => ['base1-4:foil:-:-'],
    'encoded slash' => ['base1-4%2Fx:holo:-:-'],
]);

it('touches only the caller\'s row: another user\'s same key keeps its threshold', function (): void {
    $user = Accounts::user();
    $other = Accounts::user(['email' => 'other@example.com']);
    AccountRows::watch($user, T5_KEY);
    AccountRows::watch($other, T5_KEY);
    t5Put($this, $other, T5_KEY, ['pct' => 20])->assertOk();

    t5Put($this, $user, T5_KEY, ['pct' => 10])->assertOk();
    t5As($this, $user)->deleteJson('/v1/alerts/'.T5_KEY)->assertNoContent();

    expect(t5Thresholds($other))->toBe([T5_KEY => 20])
        ->and(t5Thresholds($user))->toBe([T5_KEY => null]);
});

it('clears a threshold with DELETE: 204, null, idempotent, also for a key not on the list or malformed', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);
    t5Put($this, $user, T5_KEY, ['pct' => 10])->assertOk();

    $first = t5As($this, $user)->deleteJson('/v1/alerts/'.T5_KEY);
    $second = t5As($this, $user)->deleteJson('/v1/alerts/'.T5_KEY);
    $absent = t5As($this, $user)->deleteJson('/v1/alerts/'.T5_OTHER_KEY);
    $malformed = t5As($this, $user)->deleteJson('/v1/alerts/not-a-key');

    expect([$first->getStatusCode(), $second->getStatusCode(), $absent->getStatusCode(), $malformed->getStatusCode()])->toBe([204, 204, 204, 204])
        ->and($first->getContent())->toBe('')
        ->and(t5Thresholds($user))->toBe([T5_KEY => null]);
});

it('encodes ? in a key once: %3F reaches the right row', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, 'ex10-?:holo:-:-');

    $put = t5Put($this, $user, 'ex10-%3F:holo:-:-', ['pct' => 10]);

    expect($put->json('data'))->toBe(['variant_key' => 'ex10-?:holo:-:-', 'alert_pct' => 10])
        ->and(t5Thresholds($user))->toBe(['ex10-?:holo:-:-' => 10]);
});

it('drops the threshold with the watchlist row: removed and added again, the item has no alert', function (): void {
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);
    t5Put($this, $user, T5_KEY, ['pct' => 10])->assertOk();

    t5As($this, $user)->deleteJson('/v1/watchlist/'.T5_KEY)->assertNoContent();
    $gone = t5Thresholds($user);
    t5As($this, $user)->putJson('/v1/watchlist/'.T5_KEY)->assertOk();

    expect($gone)->toBe([])
        ->and(t5Thresholds($user))->toBe([T5_KEY => null]);
});

it('refuses an unverified account 403 email-not-verified on PUT, while DELETE (204) and GET /v1/watchlist (200) work', function (): void {
    $user = Accounts::user(['email_verified_at' => null]);
    AccountRows::watch($user, T5_KEY);

    $put = t5Put($this, $user, T5_KEY, ['pct' => 10]);
    $delete = t5As($this, $user)->deleteJson('/v1/alerts/'.T5_KEY);
    $list = t5As($this, $user)->getJson('/v1/watchlist');

    expect($put->getStatusCode())->toBe(403)
        ->and($put->json('type'))->toBe(ProblemDetails::BASE.'email-not-verified')
        ->and($delete->getStatusCode())->toBe(204)
        ->and($list->getStatusCode())->toBe(200)
        ->and($list->json('data.0.alert_pct'))->toBeNull()
        ->and(t5Thresholds($user))->toBe([T5_KEY => null]);
});

it('refuses the demo 403 demo-read-only on PUT and DELETE and changes nothing', function (): void {
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    AccountRows::watch($demo, T5_KEY);
    DB::table('watchlist_items')->where('user_id', $demo->id)->update(['alert_pct' => 20]);

    $put = t5Put($this, $demo, T5_KEY, ['pct' => 10]);
    $delete = t5As($this, $demo)->deleteJson('/v1/alerts/'.T5_KEY);

    expect([$put->getStatusCode(), $put->json('type')])->toBe([403, ProblemDetails::BASE.'demo-read-only'])
        ->and([$delete->getStatusCode(), $delete->json('type')])->toBe([403, ProblemDetails::BASE.'demo-read-only'])
        ->and(t5Thresholds($demo))->toBe([T5_KEY => 20]);
});

it('answers a signed-out PUT and DELETE with the 401 problem', function (): void {
    $put = $this->withHeaders(Accounts::FIRST_PARTY)->putJson('/v1/alerts/'.T5_KEY, ['pct' => 10]);
    $delete = $this->withHeaders(Accounts::FIRST_PARTY)->deleteJson('/v1/alerts/'.T5_KEY);

    expect([$put->getStatusCode(), $delete->getStatusCode()])->toBe([401, 401])
        ->and($put->json('type'))->toBe(ProblemDetails::BASE.'unauthenticated');
});

it('leaves portfolio holdings without alert_pct', function (): void {
    $user = Accounts::user();
    AccountRows::hold($user, T5_KEY, 1);

    $holding = t5As($this, $user)->getJson('/v1/portfolio')->json('data.0');

    expect($holding)->not->toHaveKey('alert_pct')
        ->and(array_key_last($holding))->toBe('value');
});

// Plan Q1-i4 T01 (A2, D4 server half): the floor and the 100 ceiling are validation rules (one message for every
// refusal), and the contract states them as the putAlert pct schema's minimum and maximum.

it('rounds a 7.50 % movement rule up to a floor of 8: 7 is refused with "from 8 to 100", 8 is set', function (): void {
    config(['pricing.movement.pct' => '7.50']);
    $user = Accounts::user();
    AccountRows::watch($user, T5_KEY);

    $seven = t5Put($this, $user, T5_KEY, ['pct' => 7]);
    $eight = t5Put($this, $user, T5_KEY, ['pct' => 8]);

    expect($seven->getStatusCode())->toBe(422)
        ->and($seven->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 8 to 100.']])
        ->and($eight->getStatusCode())->toBe(200)
        ->and(t5Thresholds($user))->toBe([T5_KEY => 8]);
});

it('documents the putAlert pct as a whole number from 5 to 100 in the committed contract', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('../openapi/api.json')), true, 512, JSON_THROW_ON_ERROR);
    $body = $spec['paths']['/alerts/{variantKey}']['put']['requestBody']['content']['application/json']['schema'];

    expect($spec['paths']['/alerts/{variantKey}']['put']['operationId'])->toBe('putAlert')
        ->and($body['properties'])->toBe(['pct' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 100]])
        ->and($body['required'])->toBe(['pct']);
});
