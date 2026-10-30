<?php

declare(strict_types=1);

use App\Domain\Alerts\UrlGuard;
use Tests\Support\FakeHostResolver;

// Review tests for dropping the IPv4-compatible branch: every compatible spelling is refused, the IPv6 specials and
// every BLOCKS entry stay refused, and the global-unicast allowance is unchanged.

beforeEach(function (): void {
    config(['alerts.webhooks.private_allow' => []]);
});

it('refuses ::a.b.c.d spelled in dotted and hex forms, for public, private and zero IPv4s', function (string $address): void {
    expect(UrlGuard::blocked($address))->toBeTrue();
})->with([
    'dotted documentation' => ['::203.0.113.10'],
    'hex documentation' => ['::cb00:710a'],
    'dotted public' => ['::8.8.8.8'],
    'hex public' => ['::808:808'],
    'dotted zero' => ['::0.0.0.0'],
    'dotted one' => ['::0.0.0.1'],
    'expanded compatible' => ['0:0:0:0:0:0:cb00:710a'],
    'zero-padded compatible' => ['0000:0000:0000:0000:0000:0000:0808:0808'],
    'unspecified' => ['::'],
    'loopback' => ['::1'],
    'mapped zero' => ['::ffff:0.0.0.0'],
    'mapped public' => ['::ffff:8.8.8.8'],
    'mapped hex' => ['::ffff:808:808'],
]);

it('refuses a bracketed IPv4-compatible literal in a URL and a host that resolves to one among public addresses', function (): void {
    FakeHostResolver::install(['mixed.example.com' => ['203.0.113.10', '::203.0.113.10'], 'v6.example.com' => ['::8.8.8.8']]);
    $guard = app(UrlGuard::class);

    expect($guard->check('https://[::203.0.113.10]/hook')->error?->value)->toBe('blocked_address')
        ->and($guard->check('https://[::8.8.8.8]:8443/hook')->error?->value)->toBe('blocked_address')
        ->and($guard->check('https://mixed.example.com/hook')->error?->value)->toBe('blocked_address')
        ->and($guard->check('https://v6.example.com/hook')->error?->value)->toBe('blocked_address');
});

it('refuses every BLOCKS entry at its first and last address, bare, IPv4-compatible and IPv4-mapped', function (): void {
    $seen = 0;
    foreach (UrlGuard::BLOCKS as $block) {
        [$network, $prefix] = explode('/', $block);
        if (str_contains($network, ':')) {
            $first = (string) inet_pton($network);
            $last = $first;
            for ($bit = (int) $prefix; $bit < 128; $bit++) {
                $last[intdiv($bit, 8)] = chr(ord($last[intdiv($bit, 8)]) | (0x80 >> ($bit % 8)));
            }
            foreach ([$first, $last] as $bytes) {
                expect(UrlGuard::blocked((string) inet_ntop($bytes)))->toBeTrue("v6 {$block}");
                $seen++;
            }

            continue;
        }
        $first = (string) inet_pton(implode('.', array_pad(explode('.', $network), 4, '0')));
        $last = $first;
        for ($bit = (int) $prefix; $bit < 32; $bit++) {
            $last[intdiv($bit, 8)] = chr(ord($last[intdiv($bit, 8)]) | (0x80 >> ($bit % 8)));
        }
        foreach ([$first, $last] as $bytes) {
            $v4 = (string) inet_ntop($bytes);
            expect(UrlGuard::blocked($v4))->toBeTrue("v4 {$block} {$v4}")
                ->and(UrlGuard::blocked("::{$v4}"))->toBeTrue("compatible {$block} {$v4}")
                ->and(UrlGuard::blocked("::ffff:{$v4}"))->toBeTrue("mapped {$block} {$v4}");
            $seen++;
        }
    }

    expect($seen)->toBe(2 * count(UrlGuard::BLOCKS));
});

it('still lets global unicast IPv6 through while the compatible range stays refused', function (): void {
    expect(UrlGuard::blocked('2606:4700:4700::1111'))->toBeFalse()
        ->and(UrlGuard::blocked('2001:db8::1'))->toBeFalse()
        ->and(UrlGuard::blocked('3fff::1'))->toBeFalse()
        ->and(UrlGuard::blocked('2001::1'))->toBeTrue()
        ->and(UrlGuard::blocked('::2000:0:0:1'))->toBeTrue()
        ->and(UrlGuard::blocked('1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'))->toBeTrue();
});
