<?php

declare(strict_types=1);

use App\Http\Problems\ProblemDetails;
use App\Http\Requests\StoreWebhookRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\AlertRows;
use Tests\Support\FakeHostResolver;

// Plan Q1-i4 T05 review: the form requests and #[CurrentUser] at the HTTP edge keep every old answer, order and
// bound. The refusal of a malformed route key comes first and alone; the middleware (401, 403) still answers before
// any form request; the bounds are read at request time; a refused body never reaches DNS; and a form request does
// not change what the framework's own Request methods mean.

const Q1T5R_MESSAGE = 'PokéPrices has no variant with this key.';
const Q1T5R_KEY = 'base1-4:holo:unlimited:-';

beforeEach(function (): void {
    Accounts::setUp();
    Notification::fake();
    $this->prefix = AccountRows::fakePokeSearch();
    FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10']]);
    AlertRows::captureLogs();
});

function q1t5rAs(object $test, User $user): object
{
    return AccountRows::signIn($test, $user)->withHeaders(Accounts::FIRST_PARTY);
}

/** @return list<string> the SQL the callback ran, minus the signed-in user's own read */
function q1t5rQueries(callable $request): array
{
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (! preg_match('/\bfrom\s+"?users"?\b/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });
    $request();

    return $queries;
}

it('answers a malformed key alone, with the same 422 and no query, whatever the body says, on all three PUT routes', function (string $path): void {
    $user = Accounts::user();
    AccountRows::signIn($this, $user);
    $bad = ['quantity' => 'abc', 'pct' => 'ten', 'kind' => 'slack'];

    $answers = [];
    $queries = q1t5rQueries(function () use ($path, $bad, $user, &$answers): void {
        foreach (['portfolio', 'alerts', 'watchlist'] as $route) {
            $response = q1t5rAs($this, $user)->putJson("/v1/{$route}/{$path}", $bad);
            $answers[$route] = [$response->getStatusCode(), $response->json('type'), $response->json('errors')];
        }
    });

    $refused = [422, ProblemDetails::BASE.'validation-failed', ['variant_key' => [Q1T5R_MESSAGE]]];
    expect($answers)->toBe(['portfolio' => $refused, 'alerts' => $refused, 'watchlist' => $refused])
        ->and($queries)->toBe([]);
})->with([
    'an encoded slash' => ['base1%2F4:holo:-:-'],
    'a trailing encoded newline' => ['base1-4:holo:-:-%0A'],
    'an encoded space' => ['%20'],
    'unicode in the card id' => ['b%C3%A9-1:holo:-:-'],
    'an astral character' => ['base1-4:holo:-:%F0%9F%98%80'],
    'over 300 bytes' => [str_repeat('a', 400).':holo:-:-'],
    '5000 characters' => [str_repeat('b', 5000)],
]);

it('lets the middleware answer before any form request: guest 401, demo 403, unverified 403, even with a malformed key and a bad body', function (): void {
    $bad = ['quantity' => 'abc', 'pct' => 'ten', 'kind' => 'slack', 'url' => 5];
    $demo = Accounts::user(['email' => 'demo@example.com', 'is_demo' => true]);
    $unverified = Accounts::user(['email' => 'new@example.com', 'email_verified_at' => null]);

    $guest = [];
    foreach (['portfolio/NOT-A-KEY', 'alerts/NOT-A-KEY', 'watchlist/NOT-A-KEY'] as $path) {
        $response = $this->withHeaders(Accounts::FIRST_PARTY)->putJson("/v1/{$path}", $bad);
        $guest[$path] = [$response->getStatusCode(), $response->json('type')];
    }
    $guest['POST webhooks'] = (function () use ($bad): array {
        $response = $this->withHeaders(Accounts::FIRST_PARTY)->postJson('/v1/webhooks', $bad);

        return [$response->getStatusCode(), $response->json('type')];
    })();
    $demoAnswers = [];
    foreach (['portfolio/NOT-A-KEY', 'alerts/NOT-A-KEY', 'watchlist/NOT-A-KEY'] as $path) {
        $response = q1t5rAs($this, $demo)->putJson("/v1/{$path}", $bad);
        $demoAnswers[$path] = [$response->getStatusCode(), $response->json('type')];
    }
    $demoPost = q1t5rAs($this, $demo)->postJson('/v1/webhooks', $bad);
    $unverifiedAlert = q1t5rAs($this, $unverified)->putJson('/v1/alerts/NOT-A-KEY', $bad);
    $unverifiedPost = q1t5rAs($this, $unverified)->postJson('/v1/webhooks', $bad);

    $unauthenticated = [401, ProblemDetails::BASE.'unauthenticated'];
    $demoRead = [403, ProblemDetails::BASE.'demo-read-only'];
    expect($guest)->toBe([
        'portfolio/NOT-A-KEY' => $unauthenticated,
        'alerts/NOT-A-KEY' => $unauthenticated,
        'watchlist/NOT-A-KEY' => $unauthenticated,
        'POST webhooks' => $unauthenticated,
    ])
        ->and($demoAnswers)->toBe(['portfolio/NOT-A-KEY' => $demoRead, 'alerts/NOT-A-KEY' => $demoRead, 'watchlist/NOT-A-KEY' => $demoRead])
        ->and([$demoPost->getStatusCode(), $demoPost->json('type')])->toBe($demoRead)
        ->and([$unverifiedAlert->getStatusCode(), $unverifiedAlert->json('type')])->toBe([403, ProblemDetails::BASE.'email-not-verified'])
        ->and([$unverifiedPost->getStatusCode(), $unverifiedPost->json('type')])->toBe([403, ProblemDetails::BASE.'email-not-verified']);
});

it('answers a signed-out request to every account route with the 401 problem, never a 500, and a signed-in /v1/me with its body', function (): void {
    $routes = [
        ['GET', '/v1/me'], ['GET', '/v1/watchlist'], ['PUT', '/v1/watchlist/'.Q1T5R_KEY], ['DELETE', '/v1/watchlist/'.Q1T5R_KEY],
        ['GET', '/v1/portfolio'], ['PUT', '/v1/portfolio/'.Q1T5R_KEY], ['DELETE', '/v1/portfolio/'.Q1T5R_KEY],
        ['GET', '/v1/webhooks'], ['POST', '/v1/webhooks'], ['DELETE', '/v1/webhooks/1'], ['POST', '/v1/webhooks/1/test'],
        ['PUT', '/v1/alerts/'.Q1T5R_KEY], ['DELETE', '/v1/alerts/'.Q1T5R_KEY],
        ['GET', '/v1/me/notifications'], ['PUT', '/v1/me/notifications'],
    ];

    $statuses = [];
    foreach ($routes as [$method, $path]) {
        $response = $this->withHeaders(Accounts::FIRST_PARTY)->json($method, $path, ['quantity' => 1, 'pct' => 10]);
        $statuses["{$method} {$path}"] = [$response->getStatusCode(), $response->json('type'), $response->json('title')];
    }

    expect(array_values(array_unique($statuses, SORT_REGULAR)))->toBe([array_values($statuses)[0]])
        ->and(array_values($statuses)[0])->toBe([401, ProblemDetails::BASE.'unauthenticated', 'Unauthenticated']);

    $user = Accounts::user();
    $me = q1t5rAs($this, $user)->getJson('/v1/me');
    expect($me->getStatusCode())->toBe(200)
        ->and($me->json('data.email'))->toBe('person@example.com');
});

it('reads the quantity cap, the alert floor and the movers cap at request time: a changed knob changes the next answer', function (): void {
    $user = Accounts::user();
    AccountRows::variant('ex5-17:reverse:-:-');
    AccountRows::watch($user, Q1T5R_KEY);

    config(['accounts.limits.quantity_max' => 5]);
    $five = q1t5rAs($this, $user)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 6]);
    config(['accounts.limits.quantity_max' => 8]);
    $eight = q1t5rAs($this, $user)->putJson('/v1/portfolio/ex5-17:reverse:-:-', ['quantity' => 6]);

    config(['pricing.movement.pct' => '5.00']);
    $floor5 = q1t5rAs($this, $user)->putJson('/v1/alerts/'.Q1T5R_KEY, ['pct' => 6]);
    config(['pricing.movement.pct' => '7.5']);
    $floor8 = q1t5rAs($this, $user)->putJson('/v1/alerts/'.Q1T5R_KEY, ['pct' => 6]);

    config(['pricing.movers.limit' => 3]);
    $three = $this->getJson('/v1/movers?limit=4');
    config(['pricing.movers.limit' => 4]);
    $four = $this->getJson('/v1/movers?limit=4');

    expect($five->json('errors'))->toBe(['quantity' => ['The quantity field must not be greater than 5.']])
        ->and($eight->getStatusCode())->toBe(200)
        ->and($floor5->getStatusCode())->toBe(200)
        ->and($floor8->json('errors'))->toBe(['pct' => ['Choose a whole percentage from 8 to 100.']])
        ->and($three->json('errors'))->toBe(['limit' => ['The limit field must not be greater than 3.']])
        ->and($four->getStatusCode())->toBe(200)
        ->and($four->json('meta.limit'))->toBe(4);
});

it('refuses a bad webhook body before any DNS lookup or insert, and checks the address before the per-user limit', function (): void {
    $resolver = FakeHostResolver::install(['hooks.example.com' => ['203.0.113.10'], 'private.example.com' => ['169.254.1.5']]);
    $user = Accounts::user();
    for ($i = 0; $i < 5; $i++) {
        AlertRows::endpoint($user);
    }

    $badBody = q1t5rAs($this, $user)->postJson('/v1/webhooks', ['kind' => 'slack', 'url' => 'https://private.example.com/hook', 'description' => "a\tb"]);
    $asked = $resolver->asked;
    $blockedAtLimit = q1t5rAs($this, $user)->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => 'https://private.example.com/hook']);
    $okAtLimit = q1t5rAs($this, $user)->postJson('/v1/webhooks', ['kind' => 'standard', 'url' => 'https://hooks.example.com/hook']);

    expect($badBody->json('errors'))->toBe([
        'kind' => ['Choose a signed webhook or Discord.'],
        'description' => ['A description cannot hold control or text-direction characters.'],
    ])
        ->and($asked)->toBe([])
        ->and($blockedAtLimit->json('errors'))->toBe(['url' => ['PokéPrices does not send to private, local or reserved network addresses.']])
        ->and($okAtLimit->json('errors'))->toBe(['url' => ['You can add at most 5 signed webhooks.']])
        ->and(DB::table('webhook_endpoints')->where('user_id', $user->id)->count())->toBe(5);
});

it('refuses a bad movers query with no SQL statement and no cookie, as a public read must', function (string $query): void {
    $response = null;
    $queries = q1t5rQueries(function () use ($query, &$response): void {
        $response = $this->getJson("/v1/movers?{$query}");
    });

    expect($response->getStatusCode())->toBe(422)
        ->and($queries)->toBe([])
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->json('errors'))->not->toBe([]);
})->with([
    'a direction' => ['direction=sideways'],
    'a list limit' => ['limit[]=5'],
    'a negative limit' => ['limit=-1'],
    'a huge limit' => ['limit=99999999999999999999'],
    'a limit with a plus' => ['limit=%2B5x'],
]);

it('leaves the inherited Request methods meaning what they meant: the webhook form request does not turn fullUrl() into the webhook URL', function (): void {
    $secretUrl = 'https://hooks.example.com/pokeprices-sentinel-0000';
    Route::post('/q1t5r-probe', fn (StoreWebhookRequest $request) => response()->json([
        'full' => $request->fullUrl(),
        'url' => $request->url(),
        'path' => $request->path(),
    ]));

    $response = $this->postJson('/q1t5r-probe', ['kind' => 'standard', 'url' => $secretUrl]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->json('path'))->toBe('q1t5r-probe')
        ->and($response->json('url'))->not->toContain('pokeprices-sentinel')
        ->and($response->json('full'))->not->toContain('pokeprices-sentinel');
});

it('keeps the description UTF-8 pin through the form request: a raw JSON body that is not UTF-8 answers kind and url only, and writes nothing', function (): void {
    $user = Accounts::user();
    CarbonImmutable::setTestNow('2026-10-15 12:00:00');

    $response = q1t5rAs($this, $user)->call('POST', '/v1/webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], "{\"kind\":\"standard\",\"url\":\"https://hooks.example.com/x\",\"description\":\"\xFF\xFE\"}");

    expect($response->getStatusCode())->toBe(422)
        ->and(array_keys($response->json('errors')))->toBe(['kind', 'url'])
        ->and(DB::table('webhook_endpoints')->count())->toBe(0);
});
