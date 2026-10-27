<?php

declare(strict_types=1);

use App\Domain\Catalog\CircuitBreaker;
use App\Domain\Catalog\PokeSearchClient;
use App\Http\Problems\UpstreamUnavailable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\CatalogCache;

// Edge cases for the request-path circuit breaker (plan T35, F-P1-024, R129): every endpoint shares one open
// state, the window boundaries hold to the second, a 404 is an answer, a failed or crashed probe never wedges
// the breaker, in-flight failures all count, and the HTTP surface reports the remaining open seconds.

const T35E_BASE = 'http://192.0.2.10:8081';

/** Runs $call and returns the UpstreamUnavailable it threw, or null when it returned. */
function t35eCatch(Closure $call): ?UpstreamUnavailable
{
    try {
        $call();
    } catch (UpstreamUnavailable $e) {
        return $e;
    }

    return null;
}

function t35eRetryAfter(?UpstreamUnavailable $e): ?string
{
    return $e?->getHeaders()['Retry-After'] ?? null;
}

function t35eCard(): ?UpstreamUnavailable
{
    return t35eCatch(fn () => app(PokeSearchClient::class)->card('base1-4'));
}

beforeEach(function (): void {
    config(['services.pokesearch.url' => T35E_BASE, 'services.pokesearch.api_key' => null]);
    $this->prefix = CatalogCache::isolate();

    // $this->status answers every request; $this->during, when set, runs inside the next request.
    $this->status = 500;
    $this->during = null;
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) {
        if ($this->during !== null) {
            $during = $this->during;
            $this->during = null;
            $during();
        }
        if ($this->status === 200) {
            return Http::response(['data' => ['id' => 'base1-4', 'name' => 'Charizard', 'supertype' => 'Pokémon', 'number' => '4', 'set_id' => 'base1', 'set_name' => 'Base', 'set_series' => 'Base', 'set_total' => 102, 'release_date' => '1999-01-09', 'image_small' => 'https://images.example.com/base1/4.png', 'image_large' => 'https://images.example.com/base1/4_hires.png'], 'seed' => ['ref' => null, 'seeded_at' => null]]);
        }

        return Http::response(['error' => 'down'], $this->status);
    });
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('refuses card, search, meta and batch reads alike while open, with zero upstream calls and the same Retry-After', function (): void {
    for ($i = 0; $i < 5; $i++) {
        t35eCard();
    }
    $this->travel(7)->seconds();
    $client = app(PokeSearchClient::class);

    $refusals = [
        t35eCatch(fn () => $client->card('base1-5')),
        t35eCatch(fn () => $client->search('pikachu')),
        t35eCatch(fn () => $client->meta()),
        t35eCatch(fn () => $client->cards(['base1-4', 'ex10-?'])),
    ];

    Http::assertSentCount(5);
    expect(array_map('t35eRetryAfter', $refusals))->toBe(['23', '23', '23', '23']);
});

it('holds the breaker for the whole 30 s: refused at 29 s with Retry-After 1, one probe at exactly 30 s', function (): void {
    for ($i = 0; $i < 5; $i++) {
        t35eCard();
    }

    $this->travel(29)->seconds();
    $at29 = t35eCard();
    $this->travel(1)->seconds();
    $this->status = 200;
    $at30 = t35eCard();

    Http::assertSentCount(6);
    expect(t35eRetryAfter($at29))->toBe('1')
        ->and($at30)->toBeNull();
});

it('treats a 404 as an answer: it never opens the breaker and it clears the failure count', function (): void {
    for ($i = 0; $i < 4; $i++) {
        t35eCard();
    }
    $this->status = 404;
    $answer = app(PokeSearchClient::class)->card('base1-4');
    $this->status = 500;
    for ($i = 0; $i < 4; $i++) {
        t35eCard();
    }
    $fifthSinceTheAnswer = t35eCard();

    Http::assertSentCount(4 + 1 + 4 + 1);
    expect($answer)->toBeNull()
        ->and(t35eRetryAfter($fifthSinceTheAnswer))->toBe('30');
});

it('never opens on 404s alone, however many arrive', function (): void {
    $this->status = 404;
    $answers = [];
    for ($i = 0; $i < 9; $i++) {
        $answers[] = app(PokeSearchClient::class)->card('base1-4');
    }

    Http::assertSentCount(9);
    expect($answers)->each->toBeNull();
});

it('counts failures that overlap in flight: five nested failed reads open the breaker', function (): void {
    // Each failing request starts the next read while it is still in flight, so the five failures finish
    // innermost first and every one of them must reach the counter.
    $nest = function (int $depth) use (&$nest): void {
        if ($depth > 0) {
            $this->during = fn () => $nest($depth - 1);
        }
        t35eCard();
    };
    $nest(4);
    $sixth = t35eCard();

    Http::assertSentCount(5);
    expect(t35eRetryAfter($sixth))->toBe('30');
});

it('re-opens for a full 30 s after a failed probe: refused at +29 s with Retry-After 1, probed again at +30 s', function (): void {
    for ($i = 0; $i < 5; $i++) {
        t35eCard();
    }
    $this->travel(31)->seconds();
    $failedProbe = t35eCard();

    $this->travel(29)->seconds();
    $beforeEnd = t35eCard();
    $this->travel(1)->seconds();
    $secondProbe = t35eCard();

    Http::assertSentCount(5 + 1 + 1);
    expect(t35eRetryAfter($failedProbe))->toBe('30')
        ->and(t35eRetryAfter($beforeEnd))->toBe('1')
        ->and(t35eRetryAfter($secondProbe))->toBe('30');
});

it('does not wedge when the probe crashes with something other than an upstream failure', function (): void {
    $breaker = new CircuitBreaker;
    $down = fn () => throw new UpstreamUnavailable(5);
    for ($i = 0; $i < 5; $i++) {
        t35eCatch(fn () => $breaker->call($down));
    }
    $this->travel(31)->seconds();

    $crash = null;
    try {
        $breaker->call(fn () => throw new RuntimeException('local bug'));
    } catch (RuntimeException $e) {
        $crash = $e;
    }
    $next = $breaker->call(fn (): string => 'answered');
    $afterwards = $breaker->call(fn (): string => 'still closed');

    expect($crash)->toBeInstanceOf(RuntimeException::class)
        ->and($next)->toBe('answered')
        ->and($afterwards)->toBe('still closed');
});

it('answers a cold GET /v1/cards/{id} with 503 problem+json whose Retry-After counts down the open time', function (): void {
    CatalogCache::primeSeedRef(T35E_BASE);
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(['error' => 'down'], 500));
    for ($i = 0; $i < 5; $i++) {
        t35eCard();
    }
    $sent = count(Http::recorded());
    $this->travel(12)->seconds();

    $response = $this->getJson('/v1/cards/base1-4');
    $search = $this->getJson('/v1/cards?q=pikachu');

    Http::assertSentCount($sent);
    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Content-Type'))->toStartWith('application/problem+json')
        ->and($response->headers->get('Retry-After'))->toBe('18')
        ->and($search->getStatusCode())->toBe(503)
        ->and($search->headers->get('Retry-After'))->toBe('18');
});
