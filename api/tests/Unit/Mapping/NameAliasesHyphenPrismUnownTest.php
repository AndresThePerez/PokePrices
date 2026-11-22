<?php

declare(strict_types=1);

use App\Domain\Mapping\NameAliases;
use App\Domain\Mapping\ProductName;

// The catalogue writes "Charizard-GX", "M Charizard-EX", "Arceus ◇" and "Unown [J]"; TCGplayer writes "Charizard GX",
// "M Charizard EX", "Arceus Prism Star" and "Unown (J)" or "Unown [J]". Each pair below is the same card and must agree;
// the pairs after them are different cards and must keep disagreeing. The spellings are the ones the 2026-10-11
// build left as name_disagreement.

test('a hyphenated -EX or -GX card name agrees with the spaced product name', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeTrue();
})->with([
    'sm115-9' => ['Charizard-GX', 'Charizard GX'],
    'xy2-108' => ['M Charizard-EX', 'M Charizard EX'],
    'a hyphen inside the name stays' => ['Ash-Greninja-EX', 'Ash-Greninja EX'],
    'a tag team' => ['Mewtwo & Mew-GX', 'Mewtwo & Mew GX'],
    'a product spelled with the hyphen too' => ['Charizard-GX', 'Charizard-GX'],
]);

test('the -EX/-GX fold does not make different cards agree', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeFalse();
})->with([
    'GX is not EX' => ['Charizard-GX', 'Charizard EX'],
    'the suffix is part of the name' => ['Charizard-GX', 'Charizard'],
    'a plain card is not the GX' => ['Charizard', 'Charizard GX'],
    'only the EX/GX hyphen folds, not Porygon-Z' => ['Porygon-Z', 'Porygon Z'],
    'only the EX/GX hyphen folds, not Ho-Oh' => ['Ho-Oh', 'Ho Oh'],
    'the hyphen is not dropped into one word' => ['Charizard-EX', 'CharizardEX'],
    'a longer word after the hyphen is not a suffix' => ['Mewtwo-EXTRA', 'Mewtwo EXTRA'],
    'the lower-case catalogue spelling is not the one folded' => ['Kyogre-ex', 'Kyogre ex'],
]);

test('the Prism Star diamond agrees with the product words Prism Star', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeTrue();
})->with([
    'sm6-96' => ['Arceus ◇', 'Arceus Prism Star'],
    'sm5-136' => ['Super Boost Energy ◇', 'Super Boost Energy Prism Star'],
]);

test('the Prism Star fold keeps the plain card, the star card and other names apart', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeFalse();
})->with([
    'a Prism Star card is not the plain card' => ['Arceus ◇', 'Arceus'],
    'a plain card is not the Prism Star product' => ['Arceus', 'Arceus Prism Star'],
    'the diamond is not the gold star' => ['Umbreon ★', 'Umbreon Prism Star'],
    'the star product is not the diamond card' => ['Umbreon ◇', 'Umbreon Star'],
    'half the words is not the fold' => ['Arceus ◇', 'Arceus Prism'],
]);

test('an Unown card in square brackets agrees with its letter in either bracket style', function (string $card, string $productName): void {
    expect(NameAliases::equal($card, ProductName::parse($productName)->compareName()))->toBeTrue();
})->with([
    'dp1-65 square' => ['Unown [A]', 'Unown [A]'],
    'dp6-76 round' => ['Unown [J]', 'Unown (J)'],
    'dp6-42 punctuation' => ['Unown [!]', 'Unown (!)'],
    'dp6-82 punctuation' => ['Unown [?]', 'Unown (?)'],
    'neo2-14 with a number echo after the letter' => ['Unown [A]', 'Unown [A] (14)'],
    'ex10-A, whose letter is its number, meets the lettered product' => ['Unown', 'Unown (A)'],
    'hgss1-54 with a number echo' => ['Unown', 'Unown (54)'],
]);

test('Unown letters stay apart', function (string $card, string $productName): void {
    expect(NameAliases::equal($card, ProductName::parse($productName)->compareName()))->toBeFalse();
})->with([
    'A is not B' => ['Unown [A]', 'Unown [B]'],
    'A is not B across bracket styles' => ['Unown [A]', 'Unown (B)'],
    '! is not ?' => ['Unown [!]', 'Unown (?)'],
    'a lettered card does not meet a product that names no letter' => ['Unown [A]', 'Unown'],
    'Unown V is a different card from Unown [V]' => ['Unown V', 'Unown [V]'],
    'a plain Unown is not Unown V' => ['Unown', 'Unown V'],
    'a letter echo on another Pokémon is not an Unown letter' => ['Unown [A]', 'Pikachu (A)'],
]);

test('compareName keeps the Unown letter, the delta marker and nothing else', function (string $productName, string $compareAs): void {
    expect(ProductName::parse($productName)->compareName())->toBe($compareAs);
})->with([
    'square letter' => ['Unown [A]', 'Unown [A]'],
    'round letter' => ['Unown (J)', 'Unown [J]'],
    'letter and number echo' => ['Unown [A] (33)', 'Unown [A]'],
    'number echo only' => ['Unown (90)', 'Unown'],
    'another Pokémon drops the echo' => ['Articuno (22)', 'Articuno'],
    'delta species' => ['Starmie - 015/113 (Delta Species)', 'Starmie (Delta Species)'],
    'plain' => ['Charizard GX', 'Charizard GX'],
]);
