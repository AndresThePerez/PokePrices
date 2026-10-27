<?php

declare(strict_types=1);

use App\Domain\Catalog\CardId;
use App\Domain\Catalog\PokeSearchClient;
use App\Http\Problems\UpstreamContract;
use App\Http\Problems\UpstreamUnavailable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// The pokesearch Http macro and the client on top of it (plan T34, R130). Every request is faked;
// the real-socket timeout lives in tests/Feature/Catalog/PokeSearchTimeoutTest.php.

uses(TestCase::class);

const T34_BASE = 'http://192.0.2.10:8081';

/** A PokéSearch card answer inside the contract: every required field, no optional one, an unseeded index. */
const T34_CARD = ['data' => [
    'id' => 'base1-4', 'name' => 'Charizard', 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'base1',
    'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102, 'release_date' => '1999-01-09',
    'image_small' => 'https://images.example.com/base1/4.png', 'image_large' => 'https://images.example.com/base1/4_hires.png',
], 'seed' => ['ref' => null, 'seeded_at' => null]];

/** T34_CARD for another id, with the card fields in $overrides replaced. */
function t34Answer(string $id = 'base1-4', array $overrides = []): array
{
    return ['data' => array_merge(T34_CARD['data'], ['id' => $id], $overrides), 'seed' => T34_CARD['seed']];
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T34_BASE, 'services.pokesearch.api_key' => null]);
    Http::preventStrayRequests();
});

/** The only request the fake recorded; fails the test when there is not exactly one. */
function t34OnlyRequest(): Request
{
    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    return $recorded->first()[0];
}

it('registers the pokesearch macro, which returns a pending request', function (): void {
    expect(Http::hasMacro('pokesearch'))->toBeTrue()
        ->and(Http::pokesearch())->toBeInstanceOf(PendingRequest::class);
});

it('pins connect timeout 1 s and timeout 2 s on the pending request', function (): void {
    $options = Http::pokesearch()->getOptions();

    expect($options['connect_timeout'])->toBe(1)
        ->and($options['timeout'])->toBe(2);
});

it('sends to the configured base url with the json accept header and the versioned user agent', function (): void {
    config(['app.version' => '9.8.7-test']);
    Http::fake(['*' => Http::response(T34_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    $request = t34OnlyRequest();
    expect($request->url())->toBe(T34_BASE.'/api/v1/cards/base1-4')
        ->and($request->method())->toBe('GET')
        ->and($request->header('Accept'))->toBe(['application/json'])
        ->and($request->header('User-Agent'))->toBe(['pokeprices-api/9.8.7-test']);
});

it('forwards a 64-character request id from the context unchanged', function (): void {
    $id = str_repeat('a1B2', 16);
    expect(strlen($id))->toBe(64);
    Context::add('request_id', $id);
    Http::fake(['*' => Http::response(T34_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    expect(t34OnlyRequest()->header('X-Request-Id'))->toBe([$id]);
});

it('sends no request id header when the context holds none', function (): void {
    Http::fake(['*' => Http::response(T34_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    expect(t34OnlyRequest()->hasHeader('X-Request-Id'))->toBeFalse();
});

it('sends X-Api-Key only when the configured key is non-empty', function (?string $key, bool $sent): void {
    config(['services.pokesearch.api_key' => $key]);
    Http::fake(['*' => Http::response(T34_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    $request = t34OnlyRequest();
    expect($request->hasHeader('X-Api-Key'))->toBe($sent);
    if ($sent) {
        expect($request->header('X-Api-Key'))->toBe([$key]);
    }
})->with([
    'unset' => [null, false],
    'empty' => ['', false],
    'set' => ['test-key-'.str_repeat('0', 24), true],
]);

it('returns the decoded card on a 200', function (): void {
    Http::fake(['*' => Http::response(T34_CARD)]);

    expect((new PokeSearchClient)->card('base1-4'))->toBe(T34_CARD);
});

it('retries a fast 503 once and returns the 200 that follows: two sends', function (): void {
    Http::fake(['*' => Http::sequence()->push(['code' => 'es_unavailable'], 503)->push(T34_CARD, 200)]);

    expect((new PokeSearchClient)->card('base1-4'))->toBe(T34_CARD);
    Http::assertSentCount(2);
});

it('sends twice on 503 then 503 and throws UpstreamUnavailable with a Retry-After of at least 1', function (): void {
    Http::fake(['*' => Http::sequence()->push(['code' => 'es_unavailable'], 503)->push(['code' => 'es_unavailable'], 503)]);

    $thrown = null;
    try {
        (new PokeSearchClient)->card('base1-4');
    } catch (UpstreamUnavailable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and($thrown?->getStatusCode())->toBe(503)
        ->and((int) ($thrown?->getHeaders()['Retry-After'] ?? 0))->toBeGreaterThanOrEqual(1);
    Http::assertSentCount(2);
});

it('retries a fast 502 and a fast 504 once each', function (int $status): void {
    Http::fake(['*' => Http::sequence()->push('', $status)->push(T34_CARD, 200)]);

    expect((new PokeSearchClient)->card('base1-4'))->toBe(T34_CARD);
    Http::assertSentCount(2);
})->with([502, 504]);

it('sends once on a 404 and answers null', function (): void {
    Http::fake(['*' => Http::response(['code' => 'not_found'], 404)]);

    expect((new PokeSearchClient)->card('base1-4'))->toBeNull();
    Http::assertSentCount(1);
});

it('never retries a 4xx other than 404 and reports it as an upstream contract break', function (int $status): void {
    Http::fake(['*' => Http::response(['code' => 'x'], $status)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamContract::class);
    Http::assertSentCount(1);
})->with([400, 401, 429]);

it('does not retry a 500, which is not a fast gateway failure', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    Http::assertSentCount(1);
});

it('sends once when the first attempt timed out', function (string $message): void {
    Http::fake(['*' => Http::failedConnection($message)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    Http::assertSentCount(1);
})->with([
    'read timeout' => ['cURL error 28: Operation timed out after 2001 milliseconds with 0 bytes received'],
    'connect timeout' => ['cURL error 28: Connection timed out after 1001 milliseconds'],
]);

it('retries a refused connection once: two sends, then UpstreamUnavailable', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 7: Failed to connect to 192.0.2.10 port 8081: Connection refused')]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    Http::assertSentCount(2);
});

it('does not retry a 502 that arrived after 1.0 s: one send', function (): void {
    // The fake answers in-process after a real 1.0 s wait; the client measures elapsed time per attempt.
    Http::fake(function (): mixed {
        usleep(1_000_000);

        return Http::response('', 502);
    });

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    Http::assertSentCount(1);
});

it('rejects a 200 whose body is not a json object as an upstream contract break', function (): void {
    Http::fake(['*' => Http::response('not json', 200)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamContract::class);
});

it('percent-encodes reserved characters in the card id path segment', function (string $id, string $path): void {
    Http::fake(['*' => Http::response(t34Answer($id))]);

    (new PokeSearchClient)->card($id);

    expect(t34OnlyRequest()->url())->toBe(T34_BASE.$path);
})->with([
    'question mark' => ['ex10-?', '/api/v1/cards/ex10-%3F'],
    'exclamation mark' => ['ex10-!', '/api/v1/cards/ex10-%21'],
]);

it('rejects an id carrying a slash before anything is sent', function (string $id): void {
    Http::fake(['*' => Http::response(T34_CARD)]);

    expect(fn () => (new PokeSearchClient)->card($id))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['ex10/4', 'ex10-%2F4', '../meta']);

it('encodes a batch as rawurlencode of the comma-joined id list', function (): void {
    Http::fake(['*' => Http::response(['data' => [], 'missing' => ['ex10-?', 'ex10-!', 'base1-4'], 'seed' => ['ref' => null, 'seeded_at' => null]])]);

    $answer = (new PokeSearchClient)->cards(['ex10-?', 'ex10-!', 'base1-4']);

    expect(t34OnlyRequest()->url())->toBe(T34_BASE.'/api/v1/cards?ids=ex10-%3F%2Cex10-%21%2Cbase1-4')
        ->and($answer['missing'])->toBe(['ex10-?', 'ex10-!', 'base1-4']);
});

it('rejects a batch holding one invalid id, or none at all, before anything is sent', function (array $ids): void {
    Http::fake(['*' => Http::response([])]);

    expect(fn () => (new PokeSearchClient)->cards($ids))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'slash' => [['base1-4', 'ex10/4']],
    'comma inside an id' => [['base1-4,base1-5']],
    'empty list' => [[]],
]);

it('accepts exactly the ids the PokéSearch pattern accepts', function (string $id, bool $valid): void {
    expect(CardId::isValid($id))->toBe($valid);
    if ($valid) {
        expect(fn () => CardId::assertValid($id))->not->toThrow(InvalidArgumentException::class);
    } else {
        expect(fn () => CardId::assertValid($id))->toThrow(InvalidArgumentException::class);
    }
})->with([
    'plain' => ['base1-4', true],
    'question and bang' => ['ex10-?!', true],
    'dot and underscore' => ['sv3pt5_1.a', true],
    'single character' => ['a', true],
    '128 characters' => [str_repeat('a', 128), true],
    '129 characters' => [str_repeat('a', 129), false],
    'empty' => ['', false],
    'leading dash' => ['-base1', false],
    'leading question mark' => ['?x', false],
    'slash' => ['a/b', false],
    'space' => ['base 1', false],
    'trailing newline' => ["base1-4\n", false],
    'percent' => ['a%3F', false],
    'non-ascii' => ['pokémon', false],
]);

it('returns a card answer inside the contract unchanged, extra fields and a full seed included', function (): void {
    $answer = t34Answer(overrides: ['hp' => 120, 'rarity' => 'Rare Holo', 'artist' => 'Mitsuhiro Arita', 'subtypes' => ['Stage 2'], 'types' => [], 'attacks' => [['name' => 'Fire Spin']]])
        + ['request_id' => 'upstream-1'];
    $answer['seed'] = ['ref' => str_repeat('a', 40), 'seeded_at' => '2026-10-11T20:05:45Z'];
    Http::fake(['*' => Http::response($answer)]);

    expect((new PokeSearchClient)->card('base1-4'))->toBe($answer);
});

it('refuses a card answer outside the contract before returning it, with the exact reason', function (mixed $body, string $message): void {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamContract::class, $message);
    Http::assertSentCount(1);
})->with([
    'no data' => [['seed' => T34_CARD['seed']], 'PokéSearch card answer has no card object.'],
    'data a list' => [['data' => [1, 2], 'seed' => T34_CARD['seed']], 'PokéSearch card answer has no card object.'],
    'a null name' => [t34Answer(overrides: ['name' => null]), 'PokéSearch card has no string name.'],
    'no image_large' => [['data' => array_diff_key(T34_CARD['data'], ['image_large' => true]), 'seed' => T34_CARD['seed']], 'PokéSearch card has no string image_large.'],
    'another card' => [t34Answer('base1-5'), 'PokéSearch answered with another card.'],
    'a string set_total' => [t34Answer(overrides: ['set_total' => '102']), 'PokéSearch card has no integer set_total.'],
    'a null hp' => [t34Answer(overrides: ['hp' => null]), 'PokéSearch card has an hp that is not an integer.'],
    'a numeric artist' => [t34Answer(overrides: ['artist' => 3]), 'PokéSearch card has a artist that is not a string.'],
    'a types list holding a number' => [t34Answer(overrides: ['types' => ['Fire', 1]]), 'PokéSearch card has a types that is not a list of strings.'],
    'no seed' => [['data' => T34_CARD['data']], 'PokéSearch card answer has no seed.'],
    'a seed without seeded_at' => [['data' => T34_CARD['data'], 'seed' => ['ref' => null]], 'PokéSearch card answer has no seed.'],
    'a numeric seed ref' => [['data' => T34_CARD['data'], 'seed' => ['ref' => 7, 'seeded_at' => null]], 'PokéSearch card answer has a seed that is not strings or null.'],
]);
