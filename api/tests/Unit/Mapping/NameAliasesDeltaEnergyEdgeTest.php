<?php

declare(strict_types=1);

use App\Domain\Mapping\NameAliases;
use App\Domain\Mapping\ProductName;

// Reviewer edge cases for the `Delta <name> Energy` rule: the marker must survive the spellings a price feed can
// produce, must not leak outside energy names, and must stay one-way when it meets a (Delta Species) tag, a second
// Delta word or the type-letter fold. The callers hand NameAliases `base . ' (Delta Species)'` when the product has the tag.

mutates(NameAliases::class);

test('a Delta energy product is the δ card whatever its case, spacing or width', function (string $product): void {
    expect(NameAliases::equal('δ Rainbow Energy', $product))->toBeTrue();
})->with([
    'upper case' => ['DELTA RAINBOW ENERGY'],
    'mixed case' => ['dElTa rAiNbOw EnErGy'],
    'double spaces' => ['Delta  Rainbow   Energy'],
    'no-break spaces' => ["Delta\u{00A0}Rainbow\u{00A0}Energy"],
    'tab and newline' => ["Delta\tRainbow\nEnergy"],
    'padded' => ['  Delta Rainbow Energy  '],
    'full-width letters' => ['Ｄｅｌｔａ Ｒａｉｎｂｏｗ Ｅｎｅｒｇｙ'],
    'accented letters' => ['Délta Rainbow Énergy'],
]);

test('the Delta marker is read once and on the product side only: it never makes a plain card agree', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeFalse();
})->with([
    'a second Delta word is part of the name' => ['δ Rainbow Energy', 'Delta Delta Rainbow Energy'],
    'a doubled Delta against a plain card' => ['Rainbow Energy', 'Delta Delta Rainbow Energy'],
    'the tag plus the leading word against a plain card' => ['Rainbow Energy', 'Delta Rainbow Energy (Delta Species)'],
    'the letter fold keeps it one-way' => ['Fire Energy', 'Delta R Energy'],
    'a Delta product never stands for a plain card with the type letter spelled out' => ['Fire Energy', 'Delta Fire Energy'],
]);

test('the tag and the leading word together still agree with the δ card', function (): void {
    expect(NameAliases::equal('δ Rainbow Energy', 'Delta Rainbow Energy (Delta Species)'))->toBeTrue();
});

test('Delta stays a plain word unless the whole name reads Delta <name> Energy', function (string $product): void {
    expect(NameAliases::equal('δ Rainbow Energy', $product))->toBeFalse()
        ->and(NameAliases::equal('Rainbow Energy', $product))->toBeFalse()
        ->and(NameAliases::equal('δ Rainbow', $product))->toBeFalse();
})->with([
    'energy as a prefix of a longer word' => ['Delta Rainbow Energyx'],
    'energy as the plural' => ['Delta Rainbow Energies'],
    'a suffix after energy' => ['Delta Rainbow Energy Pack'],
    'energy only inside a word' => ['Delta Rainbow Superenergy'],
    'energy glued to the name' => ['Delta RainbowEnergy'],
    'a hyphen instead of the space' => ['Delta-Rainbow Energy'],
]);

test('the base name the builder compares keeps the marker readable through a number echo and neutral tags', function (string $name): void {
    $parsed = ProductName::parse($name);
    $compareAs = $parsed->base.($parsed->isDelta() ? ' (Delta Species)' : '');

    expect(NameAliases::equal('δ Rainbow Energy', $compareAs))->toBeTrue()
        ->and(NameAliases::equal('Rainbow Energy', $compareAs))->toBeFalse();
})->with([
    'trailing card number' => ['Delta Rainbow Energy - 098/110'],
    'a neutral tag' => ['Delta Rainbow Energy (Special)'],
    'a bracket tag' => ['Delta Rainbow Energy [Staff]'],
    'a stray tag and number' => ['Delta Rainbow Energy (Non-Holo) - 098/110'],
]);

test('an oversized name resolves the same way and never throws', function (): void {
    $stem = str_repeat('Rainbow ', 60000);

    expect(NameAliases::equal('δ '.$stem.'Energy', 'Delta '.$stem.'Energy'))->toBeTrue()
        ->and(NameAliases::equal($stem.'Energy', 'Delta '.$stem.'Energy'))->toBeFalse();
});
