<?php

declare(strict_types=1);

use App\Domain\Mapping\ProductName;

// Edges of the neutral rarity and art descriptors. A descriptor is neutral only as a whole tag: it never hides a
// stamp, a staff or prerelease print, a jumbo or a pattern beside it, a longer tag or a set name that merely holds a
// descriptor word stays unknown, and a malformed bracket around a descriptor is still malformed.

test('a descriptor beside a tag that is still unknown leaves the product rejected', function (string $name): void {
    $p = ProductName::parse($name);

    expect($p->hasUnknownTag())->toBeTrue()
        ->and($p->treatment())->toBeNull()
        ->and($p->finish('Holofoil'))->toBeNull();
})->with([
    'Secret and Jumbo' => ['Vaporeon - 008/131 (Secret) (Jumbo)'],
    'Jumbo before Secret' => ['Vaporeon - 008/131 (Jumbo) (Secret)'],
    'Prime and a promo source' => ['Absol (Prime) (Pokemon Center Exclusive)'],
    'Team Plasma and a tin' => ['Kyurem (Team Plasma) (EX Deck Tin)'],
    'Alternate Art Secret and an error print' => ['Umbreon VMAX (Alternate Art Secret) (Misaligned)'],
    'Gold and a metal card' => ['Pikachu (Gold) (Metal Card)'],
]);

test('a descriptor beside a stamp, a staff print or a pattern keeps that treatment', function (string $name, string $treatment, string $finish): void {
    $p = ProductName::parse($name);

    expect($p->hasUnknownTag())->toBeFalse()
        ->and($p->treatment())->toBe($treatment)
        ->and($p->treatment())->not->toBe('-')
        ->and($p->finish('Holofoil'))->toBe($finish);
})->with([
    'Secret and Staff' => ['Vaporeon - 008/131 (Secret) [Staff]', 'stamp-staff', 'holo'],
    'Prime and Prerelease' => ['Absol (Prime) (Prerelease)', 'stamp-prerelease', 'holo'],
    'Shiny, Prerelease and Staff' => ['Drifloon (Shiny) (Prerelease) [Staff]', 'stamp-prerelease-staff', 'holo'],
    'Gold and Cosmos' => ['Pikachu (Gold) (Cosmos Holo)', 'cosmos', 'holo'],
    'Secret Rare and a Poke Ball pattern' => ['Eevee (Secret Rare) (Poke Ball Pattern)', 'pokeball', 'reverse'],
    'a numbered Full Art and Winner' => ['Jirachi EX (98 Full Art) [Winner]', 'stamp-winner', 'holo'],
]);

test('a tag that merely holds a descriptor word is not a descriptor', function (string $name): void {
    expect(ProductName::parse($name)->hasUnknownTag())->toBeTrue();
})->with([
    'a set name with Secret' => ['Pikachu (Secret Wonders)'],
    'a set name with Shiny' => ['Pikachu (Shiny Vault)'],
    'a Gold Star' => ['Pikachu (Gold Star)'],
    'Golden is not Gold' => ['Pikachu (Golden)'],
    'Prime with a word after it' => ['Pikachu (Prime Time)'],
    'Team Plasma with a word after it' => ['Pikachu (Team Plasma Deck)'],
    'Holo alone is not Holo Common' => ['Pikachu (Holo)'],
    'Full Art Promo with a source after it' => ['Pikachu (Full Art Promo Staff)'],
    'a number glued to Full Art' => ['Pikachu (98Full Art)'],
    'a signed number' => ['Pikachu (-98 Full Art)'],
    'a number before Full Art Promo' => ['Pikachu (98 Full Art Promo)'],
    'a number before Alternate Art Secret' => ['Pikachu (98 Alternate Art Secret)'],
    'a number after the descriptor' => ['Pikachu (Full Art 98)'],
    'Secret glued to a number' => ['Pikachu (Secret98)'],
]);

test('a set or card name holding a descriptor word stays in the base and is not read as a tag', function (string $name, string $base): void {
    $p = ProductName::parse($name);

    expect($p->base)->toBe($base)
        ->and($p->tags)->toBe([])
        ->and($p->treatment())->toBe('-');
})->with([
    'Secret Wonders' => ['Secret Wonders Booster Pack', 'Secret Wonders Booster Pack'],
    'Shiny Vault' => ['Shiny Vault Charizard', 'Shiny Vault Charizard'],
    'Gold Star in a name' => ['Rayquaza Gold Star - 107/110', 'Rayquaza Gold Star'],
    'Team Plasma in a name' => ["Team Plasma's Pokemon - 1/100", "Team Plasma's Pokemon"],
]);

test('odd case and spacing inside a descriptor tag do not matter, either bracket style', function (string $name): void {
    $p = ProductName::parse($name);

    expect($p->base)->toBe('Pikachu')
        ->and($p->hasUnknownTag())->toBeFalse()
        ->and($p->treatment())->toBe('-')
        ->and(array_unique(array_column($p->tags, 'class')))->toBe(['neutral']);
})->with([
    'upper case' => ['Pikachu (ALTERNATE ART SECRET)'],
    'mixed case' => ['Pikachu (sEcReT rArE)'],
    'doubled inner spaces' => ['Pikachu (Team   Plasma)'],
    'a tab inside' => ["Pikachu (Full\tArt Promo)"],
    'square brackets' => ['Pikachu [Prime]'],
    'a numbered descriptor with odd spacing' => ['Pikachu (  120   Secret   Rare )'],
    'the same descriptor twice' => ['Pikachu (Secret) (Secret)'],
    'two different descriptors' => ['Pikachu (Shiny) (Secret)'],
]);

test('a malformed bracket around a descriptor is still malformed and rejected', function (string $name): void {
    $p = ProductName::parse($name);

    expect($p->hasUnknownTag())->toBeTrue()
        ->and($p->treatment())->toBeNull()
        ->and($p->base)->not->toContain('(')
        ->and($p->base)->not->toContain(')')
        ->and($p->base)->not->toContain('Secret');
})->with([
    'doubled brackets' => ['Pikachu ((Secret))'],
    'mixed doubled brackets' => ['Pikachu [(Secret)]'],
    'an opener never closed' => ['Pikachu (Secret'],
    'a stray closer after the descriptor' => ['Pikachu (Secret))'],
    'a mismatched closer' => ['Pikachu (Secret]'],
    'an empty bracket beside the descriptor' => ['Pikachu (Secret) ()'],
]);

test('an oversized name with a descriptor parses to the same base and stays neutral', function (): void {
    $base = str_repeat('Charizard ', 2000).'GX';
    $p = ProductName::parse($base.' - 9/68 (Secret) (120 Secret Rare)');

    expect(strlen($base))->toBeGreaterThan(20000)
        ->and($p->base)->toBe($base)
        ->and(array_column($p->tags, 'class'))->toBe(['neutral', 'neutral'])
        ->and($p->treatment())->toBe('-');

    $huge = ProductName::parse('Pikachu ('.str_repeat('9', 5000).' Full Art)');
    expect($huge->base)->toBe('Pikachu')
        ->and($huge->hasUnknownTag())->toBeFalse();
});
