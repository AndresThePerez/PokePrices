<?php

declare(strict_types=1);

use App\Domain\Mapping\NameAliases;
use App\Domain\Mapping\ProductName;

// Edges of the -EX/-GX hyphen fold, the Prism Star diamond and the one-way Unown letter rule. A fold may only make
// two spellings of one card agree; names where the hyphen belongs to the Pokémon, a Mega against its plain form, a
// tag team against one of its halves and every pair of different Unown letters must keep disagreeing.

/** Agreement as the builder computes it: the product name goes through the tag grammar first. */
function qfeAgree(string $card, string $productName): bool
{
    return NameAliases::equal($card, ProductName::parse($productName)->compareName());
}

test('a hyphen that belongs to the name survives beside the folded -EX or -GX suffix', function (string $card, string $product): void {
    expect(qfeAgree($card, $product))->toBeTrue();
})->with([
    'Ho-Oh-EX' => ['Ho-Oh-EX', 'Ho-Oh EX'],
    'Ho-Oh-EX against the feed\'s mixed case' => ['Ho-Oh-EX', 'Ho-Oh Ex (119 Full Art)'],
    'Ho-Oh-GX' => ['Ho-Oh-GX', 'Ho-Oh GX - SM57'],
    'Kommo-o-GX' => ['Kommo-o-GX', 'Kommo-o GX (Secret)'],
    'Porygon-Z-GX' => ['Porygon-Z-GX', 'Porygon-Z GX - SM216'],
    'Porygon-Z unchanged' => ['Porygon-Z', 'Porygon-Z (Team Plasma)'],
    'Jangmo-o unchanged' => ['Jangmo-o', 'Jangmo-o'],
    'Hakamo-o unchanged' => ['Hakamo-o', 'Hakamo-o - 145/236'],
    'Type: Null unchanged' => ['Type: Null', 'Type: Null'],
    'a tag team keeps both halves' => ['Pikachu & Zekrom-GX', 'Pikachu & Zekrom GX'],
    'an inner -EX before a space folds too' => ['Pikachu-EX & Zekrom-GX', 'Pikachu EX & Zekrom GX'],
]);

test('the hyphen fold never makes two different cards agree', function (string $card, string $product): void {
    expect(qfeAgree($card, $product))->toBeFalse()
        ->and(qfeAgree($product, $card))->toBeFalse();
})->with([
    'Ho-Oh-EX is not Ho-Oh-GX' => ['Ho-Oh-EX', 'Ho-Oh GX'],
    'Ho-Oh-EX is not plain Ho-Oh' => ['Ho-Oh-EX', 'Ho-Oh'],
    'the inner hyphen of Ho-Oh is not dropped' => ['Ho-Oh-GX', 'Ho Oh GX'],
    'the inner hyphen of Kommo-o is not dropped' => ['Kommo-o-GX', 'Kommoo GX'],
    'Kommo-o is not Kommo o' => ['Kommo-o', 'Kommo o'],
    'Jangmo-o is not Jangmo o' => ['Jangmo-o', 'Jangmo o'],
    'Porygon-Z is not Porygon Z' => ['Porygon-Z', 'Porygon Z'],
    'Type: Null is not Type Null' => ['Type: Null', 'Type Null'],
    'a Mega is not its plain form' => ['M Charizard-EX', 'Charizard EX'],
    'a plain -EX is not the Mega' => ['Charizard-EX', 'M Charizard EX'],
    'a tag team is not its first half' => ['Pikachu & Zekrom-GX', 'Pikachu GX'],
    'a tag team is not its last half' => ['Pikachu & Zekrom-GX', 'Zekrom GX'],
    'a tag team is not the pair without the suffix' => ['Pikachu & Zekrom-GX', 'Pikachu & Zekrom'],
    '-EXTRA is not -EX' => ['Mewtwo-EXTRA', 'Mewtwo EX'],
    '-GXY is not -GX' => ['Mewtwo-GXY', 'Mewtwo GX'],
    'a suffix followed by punctuation is not folded' => ['Charizard-EX.', 'Charizard EX'],
    'the suffix glued to the next word is not folded' => ['Charizard-EXCard', 'Charizard EX Card'],
    'a suffix spelled with a space inside is another name' => ['Charizard-GX', 'Charizard G X'],
]);

test('the Prism Star fold reads only the diamond, never a near miss', function (string $card, string $product, bool $agree): void {
    expect(qfeAgree($card, $product))->toBe($agree);
})->with([
    'the diamond with the product words' => ['Lunala ◇', 'Lunala Prism Star', true],
    'the diamond against the words in any case' => ['Lunala ◇', 'lunala PRISM star', true],
    'the diamond with no space before it' => ['Lunala◇', 'Lunala Prism Star', true],
    'a white diamond suit is not the Prism Star' => ['Lunala ♢', 'Lunala Prism Star', false],
    'a black diamond is not the Prism Star' => ['Lunala ◆', 'Lunala Prism Star', false],
    'the diamond is not the word Prism alone' => ['Lunala ◇', 'Lunala Prism', false],
    'the diamond is not the word Star alone' => ['Lunala ◇', 'Lunala Star', false],
    'two diamonds are not one Prism Star' => ['Lunala ◇◇', 'Lunala Prism Star', false],
    'the diamond is not the gold star of the same Pokémon' => ['Lunala ◇', 'Lunala ★', false],
]);

test('a lettered Unown card agrees with its own letter only', function (string $card, string $productName, bool $agree): void {
    expect(qfeAgree($card, $productName))->toBe($agree);
})->with([
    'A is A' => ['Unown [A]', 'Unown [A]', true],
    'A is not B' => ['Unown [A]', 'Unown [B]', false],
    'A is not !' => ['Unown [A]', 'Unown (!)', false],
    'A is not ?' => ['Unown [A]', 'Unown (?)', false],
    '! is not ?' => ['Unown [!]', 'Unown [?]', false],
    'a lower-case letter is no letter' => ['Unown [A]', 'Unown [a]', false],
    'two different letters name no single letter' => ['Unown [A]', 'Unown [A] (B)', false],
    'the same letter twice is not one clear letter' => ['Unown [A]', 'Unown [A] (A)', false],
    'an empty bracket is no letter' => ['Unown [A]', 'Unown []', false],
    'a two-letter bracket is no letter' => ['Unown [A]', 'Unown [AB]', false],
    'a number echo is no letter' => ['Unown [A]', 'Unown (14)', false],
    'Unown V is not the lettered Unown [V]' => ['Unown [V]', 'Unown V', false],
    'Unown VSTAR is not a lettered Unown' => ['Unown [V]', 'Unown VSTAR', false],
    'a delta product is never the plain lettered card' => ['Unown [A]', 'Unown [A] (Delta Species)', false],
]);

test('a plain Unown card meets one letter, and nothing else that merely starts with Unown', function (string $productName, bool $agree): void {
    expect(qfeAgree('Unown', $productName))->toBe($agree);
})->with([
    'a letter in round brackets' => ['Unown (A)', true],
    'a letter in square brackets' => ['Unown [Z]', true],
    'the punctuation letters' => ['Unown (?)', true],
    'a number echo' => ['Unown (54)', true],
    'Unown V is another card' => ['Unown V', false],
    'Unown VSTAR is another card' => ['Unown VSTAR', false],
    'a delta Unown is never the plain card' => ['Unown (A) (Delta Species)', false],
    'another Pokémon with a letter echo' => ['Pikachu (A)', false],
]);

test('the one-way Unown rule never runs the other way', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeFalse();
})->with([
    'a lettered card against a plain product' => ['Unown [B]', 'Unown'],
    'a lettered card in round brackets against a plain product' => ['Unown (B)', 'Unown'],
    'a plain card against an empty bracket' => ['Unown', 'Unown []'],
    'a plain card against two letters in one bracket' => ['Unown', 'Unown [AB]'],
    'a plain card against a lettered Unown V' => ['Unown', 'Unown V [A]'],
    'a plain card against a lettered other Pokémon' => ['Unown', 'Pikachu [A]'],
]);

test('compareName adds a letter only for a single upper-case Unown letter echo', function (string $productName, string $compareAs): void {
    expect(ProductName::parse($productName)->compareName())->toBe($compareAs);
})->with([
    'two different letters' => ['Unown [A] (B)', 'Unown'],
    'the same letter twice' => ['Unown (A) [A]', 'Unown'],
    'a lower-case letter' => ['Unown (a)', 'Unown'],
    'an empty bracket' => ['Unown ()', 'Unown'],
    'a letter beside a world-deck name and year' => ['Unown [G] - 2009 (Tsubasa Nakamura)', 'Unown [G]'],
    'a letter on Unown V' => ['Unown V (A)', 'Unown V'],
    'a letter and the delta marker' => ['Unown (A) (Delta Species)', 'Unown [A] (Delta Species)'],
    'extra spaces around the base' => ['  Unown   (Q)  ', 'Unown [Q]'],
]);
