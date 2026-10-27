<?php

declare(strict_types=1);

use App\Domain\Catalog\CardId;
use App\Domain\Catalog\CatalogRegistrar;
use App\Domain\Catalog\PokeSearchClient;
use App\Http\Problems\UpstreamContract;
use App\Http\Problems\UpstreamUnavailable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Reviewer edge cases for T34: the R130 retry predicate, request headers, id rule, URL encoding, real sockets.

uses(TestCase::class);

const T34E_BASE = 'http://192.0.2.10:8081';

/** A PokéSearch card answer inside the contract: every required field, no optional one, an unseeded index. */
const T34E_CARD = ['data' => [
    'id' => 'base1-4', 'name' => 'Charizard', 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'base1',
    'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102, 'release_date' => '1999-01-09',
    'image_small' => 'https://images.example.com/base1/4.png', 'image_large' => 'https://images.example.com/base1/4_hires.png',
], 'seed' => ['ref' => null, 'seeded_at' => null]];

beforeEach(function (): void {
    config(['services.pokesearch.url' => T34E_BASE, 'services.pokesearch.api_key' => null]);
});

function t34eStatusException(int $status): RequestException
{
    return new RequestException(new Response(new PsrResponse($status)));
}

it('retries a gateway failure only strictly under 0.9 s: 0.9 s exactly is too slow', function (float $elapsed, bool $retry): void {
    expect(CatalogRegistrar::failedFast(t34eStatusException(502), $elapsed))->toBe($retry);
})->with([
    'instant' => [0.0, true],
    'just under' => [0.8999, true],
    'exactly 0.9' => [0.9, false],
    'just over' => [0.9001, false],
    'a second' => [1.0, false],
]);

it('retries only 502, 503 and 504 among HTTP failures, however fast', function (int $status, bool $retry): void {
    expect(CatalogRegistrar::failedFast(t34eStatusException($status), 0.01))->toBe($retry);
})->with([
    [400, false], [401, false], [404, false], [408, false], [429, false],
    [500, false], [501, false], [502, true], [503, true], [504, true], [505, false], [599, false],
]);

it('retries a connection failure only for cURL error 7, never for 28, 6, 70 or a message without the prefix', function (string $message, bool $retry): void {
    expect(CatalogRegistrar::failedFast(new ConnectionException($message), 0.01))->toBe($retry);
})->with([
    'refused' => ['cURL error 7: Failed to connect to 192.0.2.10 port 8081: Connection refused', true],
    'timeout' => ['cURL error 28: Operation timed out', false],
    'dns' => ['cURL error 6: Could not resolve host: example.invalid', false],
    'errno 70 is not errno 7' => ['cURL error 70: something else', false],
    'no prefix' => ['Connection refused', false],
    'prefix not at start' => ['wrapped: cURL error 7: refused', false],
]);

it('never retries a connection refusal that already took 0.9 s', function (): void {
    expect(CatalogRegistrar::failedFast(new ConnectionException('cURL error 7: refused'), 0.9))->toBeFalse();
});

it('never retries a throwable that is neither a request nor a connection failure', function (): void {
    expect(CatalogRegistrar::failedFast(new RuntimeException('boom'), 0.0))->toBeFalse();
});

it('makes at most two attempts: 503, 503, 200 sends 2 and still reports unavailable', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()->push('', 503)->push('', 503)->push(T34E_CARD, 200)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    Http::assertSentCount(2);
});

it('does not retry a second time when the retry itself times out: 503 then timeout sends 2', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()
        ->push('', 503)
        ->pushFailedConnection('cURL error 28: Operation timed out after 2001 milliseconds')]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    Http::assertSentCount(2);
});

it('answers a 404 after a refused first attempt: refused then 404 sends 2 and returns null', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection('cURL error 7: Failed to connect: Connection refused')
        ->push(['code' => 'not_found'], 404)]);

    expect((new PokeSearchClient)->card('base1-4'))->toBeNull();
    Http::assertSentCount(2);
});

it('reports a 4xx that follows a refused first attempt as a contract break', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection('cURL error 7: Failed to connect: Connection refused')
        ->push(['code' => 'bad'], 400)]);

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamContract::class);
    Http::assertSentCount(2);
});

it('only ever sends GET, including on the retry', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()->push('', 503)->push(T34E_CARD, 200)]);

    (new PokeSearchClient)->card('base1-4');

    $methods = Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->all();
    expect($methods)->toBe(['GET', 'GET']);
});

it('gives every client-side UpstreamUnavailable a Retry-After of at least 1 whatever the cause', function (string $kind): void {
    Http::preventStrayRequests();
    Http::fake(['*' => match ($kind) {
        'refused' => Http::failedConnection('cURL error 7: refused'),
        'timeout' => Http::failedConnection('cURL error 28: timed out'),
        'http 500' => Http::response('', 500),
        default => Http::response('', 504),
    }]);

    $thrown = null;
    try {
        (new PokeSearchClient)->card('base1-4');
    } catch (UpstreamUnavailable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and((int) $thrown?->getHeaders()['Retry-After'])->toBeGreaterThanOrEqual(1);
})->with(['refused', 'timeout', 'http 500', 'http 504']);

it('joins a base url with a trailing slash to the path without a double slash', function (string $base): void {
    config(['services.pokesearch.url' => $base]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(T34E_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1)
        ->and($recorded->first()[0]->url())->toBe(T34E_BASE.'/api/v1/cards/base1-4');
})->with([T34E_BASE, T34E_BASE.'/']);

it('sends X-Api-Key with exactly the configured value and never when the key is empty', function (?string $key): void {
    config(['services.pokesearch.api_key' => $key]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(T34E_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    /** @var Request $request */
    $request = Http::recorded()->first()[0];
    if ($key === null || $key === '') {
        expect($request->hasHeader('X-Api-Key'))->toBeFalse();
    } else {
        expect($request->header('X-Api-Key'))->toBe([$key]);
    }
})->with([null, '', 'fake-key-0000000000000000']);

it('forwards a 128-character request id without altering it and without failing the call', function (): void {
    $id = str_repeat('a', 128);
    Context::add('request_id', $id);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(T34E_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    expect(Http::recorded()->first()[0]->header('X-Request-Id'))->toBe([$id]);
});

it('sends no X-Request-Id for an empty or non-string context value', function (mixed $value): void {
    Context::add('request_id', $value);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(T34E_CARD)]);

    (new PokeSearchClient)->card('base1-4');

    expect(Http::recorded()->first()[0]->hasHeader('X-Request-Id'))->toBeFalse();
})->with(['empty string' => [''], 'integer' => [12345678], 'array' => [['x']]]);

it('accepts and rejects card ids exactly at the pattern edges', function (string $id, bool $valid): void {
    expect(CardId::isValid($id))->toBe($valid);
})->with([
    'ex10-?' => ['ex10-?', true],
    'ex10-!' => ['ex10-!', true],
    'base1-4' => ['base1-4', true],
    '128 chars' => ['a'.str_repeat('-', 127), true],
    '129 chars' => ['a'.str_repeat('-', 128), false],
    'empty' => ['', false],
    'leading dot' => ['.base1', false],
    'leading dash' => ['-base1', false],
    'leading underscore' => ['_base1', false],
    'leading bang' => ['!x', false],
    'double dot only' => ['..', false],
    'double dot inside is a legal character run' => ['a..b', true],
    'slash' => ['base1/4', false],
    'encoded slash' => ['base1%2F4', false],
    'space inside' => ['base 1', false],
    'leading space' => [' base1', false],
    'trailing space' => ['base1 ', false],
    'tab' => ["base1\t4", false],
    'nul byte' => ["base1\0", false],
    'nul byte leading' => ["\0base1", false],
    'e acute' => ['bas'."\u{e9}".'1', false],
    'fullwidth digit' => ["\u{ff11}x", false],
    'comma' => ['a,b', false],
    'backslash' => ['a\\b', false],
    'trailing newline' => ["a\n", false],
    'leading newline' => ["\na", false],
]);

it('sends a batch with commas encoded as %2C and parses back to the same ids', function (): void {
    $ids = ['ex10-?', 'ex10-!', 'base1-4', 'a'.str_repeat('b', 127)];
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => [], 'missing' => $ids])]);

    (new PokeSearchClient)->cards($ids);

    $url = Http::recorded()->first()[0]->url();
    expect($url)->toContain('/api/v1/cards?ids=')
        ->and($url)->not->toContain(',')
        ->and(substr_count($url, '%2C'))->toBe(3);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect(explode(',', (string) $query['ids']))->toBe($ids);
});

it('sends a single-id batch and an associative-keyed list as the plain joined ids', function (): void {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => [], 'missing' => []])]);

    (new PokeSearchClient)->cards(['base1-4']);
    /** @var list<string> $keyed */
    $keyed = ['x' => 'base1-4', 'y' => 'base1-5'];
    (new PokeSearchClient)->cards($keyed);

    $urls = Http::recorded()->map(fn (array $pair): string => $pair[0]->url())->all();
    expect($urls)->toBe([
        T34E_BASE.'/api/v1/cards?ids=base1-4',
        T34E_BASE.'/api/v1/cards?ids=base1-4%2Cbase1-5',
    ]);
});

it('does not retry and does not leak the api key into the thrown exception message', function (): void {
    $key = 'sentinel-key-'.str_repeat('7', 16);
    config(['services.pokesearch.api_key' => $key]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('', 401)]);

    $thrown = null;
    try {
        (new PokeSearchClient)->card('base1-4');
    } catch (UpstreamContract $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UpstreamContract::class)
        ->and($thrown?->getMessage())->not->toContain($key);
    Http::assertSentCount(1);
});

it('retries a real refused connection once: cURL error 7 from a live socket is recognised', function (): void {
    config(['services.pokesearch.url' => 'http://localhost:1']);
    $failures = 0;
    Event::listen(ConnectionFailed::class, function () use (&$failures): void {
        $failures++;
    });

    $started = hrtime(true);
    $thrown = null;
    try {
        (new PokeSearchClient)->card('base1-4');
    } catch (UpstreamUnavailable $e) {
        $thrown = $e;
    }
    $elapsed = (hrtime(true) - $started) / 1e9;

    expect($thrown)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and($failures)->toBe(2)
        ->and($elapsed)->toBeLessThan(1.0);
});

it('does not retry a real DNS failure (cURL error 6)', function (): void {
    config(['services.pokesearch.url' => 'http://pokesearch-edge.invalid:8081']);
    $failures = 0;
    Event::listen(ConnectionFailed::class, function () use (&$failures): void {
        $failures++;
    });

    expect(fn () => (new PokeSearchClient)->card('base1-4'))->toThrow(UpstreamUnavailable::class);
    expect($failures)->toBe(1);
});
