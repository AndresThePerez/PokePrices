<?php

declare(strict_types=1);

use App\Domain\Alerts\UrlGuard;
use Tests\Support\FakeHostResolver;

// An IPv4-compatible IPv6 address (::a.b.c.d) is outside global unicast, so it is refused whatever IPv4 it carries,
// public or documentation addresses included. The mapped form and the private compatible forms stay refused.

beforeEach(function (): void {
    config(['alerts.webhooks.private_allow' => []]);
});

it('refuses an IPv4-compatible address that carries a public or documentation IPv4, as a bare address and as a resolved one', function (string $address): void {
    FakeHostResolver::install(['hooks.example.com' => [$address]]);

    expect(UrlGuard::blocked($address))->toBeTrue()
        ->and(app(UrlGuard::class)->check('https://hooks.example.com/hook')->error?->value)->toBe('blocked_address');
})->with([
    'compatible documentation' => ['::203.0.113.10'],
    'compatible public' => ['::8.8.8.8'],
]);

it('keeps refusing the compatible and mapped forms of private IPv4 addresses', function (string $address): void {
    expect(UrlGuard::blocked($address))->toBeTrue();
})->with([
    'compatible benchmarking' => ['::198.18.0.1'],
    'compatible loopback' => ['::127.0.0.1'],
    'mapped loopback' => ['::ffff:127.0.0.1'],
]);

it('still allows the plain IPv4 the compatible form carried', function (): void {
    expect(UrlGuard::blocked('203.0.113.10'))->toBeFalse()
        ->and(UrlGuard::blocked('8.8.8.8'))->toBeFalse();
});
