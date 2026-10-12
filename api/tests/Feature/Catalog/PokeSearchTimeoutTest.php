<?php

declare(strict_types=1);

use App\Domain\Catalog\PokeSearchClient;
use App\Http\Problems\UpstreamUnavailable;

// A real socket to a blackhole (RFC 5737 TEST-NET-1, discard port): connectTimeout(1) must end the call,
// and no retry may follow a timeout, so the whole call stays within 1.2 s (plan T34, R130).

it('gives up on an unreachable PokéSearch within 1.2 s and throws UpstreamUnavailable', function (): void {
    config(['services.pokesearch.url' => 'http://192.0.2.1:9']);

    $thrown = null;
    $started = hrtime(true);
    try {
        (new PokeSearchClient)->card('base1-4');
    } catch (UpstreamUnavailable $e) {
        $thrown = $e;
    }
    $elapsed = (hrtime(true) - $started) / 1e9;

    expect($thrown)->toBeInstanceOf(UpstreamUnavailable::class)
        ->and($elapsed)->toBeLessThanOrEqual(1.2);
});
