<?php

declare(strict_types=1);

use App\Domain\Mapping\ProductName;

// Rarity and art descriptors ("(Secret)", "(Alternate Art Secret)", "(Prime)", "(Team Plasma)", "(98 Full Art)")
// describe the card the number already names, so they are neutral. Tags that stand for a different print or
// product (a pattern, a stamp, a promo source, a half of a LEGEND, an error print) stay unknown. The names below are
// real TCGplayer product names from the 2026-10-11 day, except the case-and-spacing row and the two "Pikachu"
// shapes made up to probe the edges of the grammar.

test('a rarity or art descriptor is a neutral tag', function (string $name, string $base): void {
    $p = ProductName::parse($name);

    expect($p->base)->toBe($base)
        ->and(array_column($p->tags, 'class'))->toBe(['neutral'])
        ->and($p->hasUnknownTag())->toBeFalse()
        ->and($p->treatment())->toBe('-')
        ->and($p->finish('Holofoil'))->toBe('holo')
        ->and($p->deck())->toBeNull();
})->with([
    'Secret' => ['Virizion (Secret)', 'Virizion'],
    'Secret Rare' => ["Farfetch'd (Secret Rare)", "Farfetch'd"],
    'Alternate Art Secret (swsh7-215)' => ['Umbreon VMAX (Alternate Art Secret)', 'Umbreon VMAX'],
    'Alternate Full Art' => ['Gengar & Mimikyu GX (Alternate Full Art)', 'Gengar & Mimikyu GX'],
    'Prime (hgss4-91)' => ['Absol (Prime)', 'Absol'],
    'Team Plasma' => ['Accelgor (Team Plasma)', 'Accelgor'],
    'Shiny' => ['Drifloon (Shiny)', 'Drifloon'],
    'Shiny Full Art' => ['Primal Kyogre EX (Shiny Full Art)', 'Primal Kyogre EX'],
    'Texture Full Art' => ['Metal Energy (Texture Full Art)', 'Metal Energy'],
    'Full Art Promo' => ['Reshiram (Full Art Promo)', 'Reshiram'],
    'Holo Common' => ['Bulbasaur (Holo Common)', 'Bulbasaur'],
    'Gold' => ['Pokemon Catcher (Gold)', 'Pokemon Catcher'],
    'Secret Full Art' => ['Mewtwo EX (163 Secret Full Art)', 'Mewtwo EX'],
    'Secret Shining' => ['Mewtwo GX (Secret Shining)', 'Mewtwo GX'],
    'a number before Full Art' => ['Jirachi EX (98 Full Art)', 'Jirachi EX'],
    'a number before Secret Rare' => ['Garchomp (120 Secret Rare)', 'Garchomp'],
    'a number before Full Art Secret Rare' => ['Reshiram (114 Full Art Secret Rare)', 'Reshiram'],
    'case and spacing do not matter' => ['Virizion (  SECRET )', 'Virizion'],
]);

test('descriptors combine with echoes, other neutral tags and treatments as before', function (): void {
    $xy2108 = ProductName::parse('M Charizard EX (X) (Secret)');
    expect($xy2108->base)->toBe('M Charizard EX')
        ->and(array_column($xy2108->tags, 'class'))->toBe(['number_echo', 'neutral'])
        ->and($xy2108->treatment())->toBe('-');

    $plasmaFullArt = ProductName::parse('Deoxys EX (Team Plasma) (Full Art)');
    expect(array_column($plasmaFullArt->tags, 'class'))->toBe(['neutral', 'neutral'])
        ->and($plasmaFullArt->treatment())->toBe('-');

    $secretEcho = ProductName::parse('Arceus VSTAR (Secret) (176)');
    expect(array_column($secretEcho->tags, 'class'))->toBe(['neutral', 'number_echo'])
        ->and($secretEcho->base)->toBe('Arceus VSTAR');

    // A neutral descriptor never hides a treatment beside it.
    expect(ProductName::parse('Druddigon (Team Plasma) - BW80 (Cosmos Holo)')->treatment())->toBe('cosmos')
        ->and(ProductName::parse('Crobat - BW51 (Team Plasma) (Prerelease) [Staff]')->treatment())->toBe('stamp-prerelease-staff');
});

test('tags that name another print, product or card stay unknown', function (string $name): void {
    $p = ProductName::parse($name);

    expect($p->hasUnknownTag())->toBeTrue()
        ->and($p->treatment())->toBeNull()
        ->and($p->finish('Holofoil'))->toBeNull();
})->with([
    'the Team Rocket reverse pattern of Ascended Heroes' => ["Team Rocket's Tarountula (Team Rocket)"],
    'an error print' => ['Pikachu (Red Cheeks)'],
    'a holo pattern' => ['Keldeo - BW60 (Tinsel Holo)'],
    'one half of a LEGEND' => ['Darkrai & Cresselia Legend (Top)'],
    'the other half' => ['Darkrai & Cresselia Legend (Bottom)'],
    'a promo source' => ['Koraidon - 014 (Pokemon Center Exclusive)'],
    'a movie promo' => ['Dragonite (Movie Promo)'],
    'a tin' => ['Mudkip - 018 (EX Deck Tin)'],
    'a stamp' => ['Kabuto [W Stamped]'],
    'a card-type word' => ['N (Supporter)'],
    'a basic energy word' => ['Darkness Energy (Basic)'],
    'an Ancient Trait' => ['Sceptile (Omega)'],
    'a colour' => ['Gyarados (Red)'],
    'Secret inside a longer tag' => ["Digger (Rocket's Secret Machine)"],
    'a number before another word' => ['Pikachu (98 Jumbo)'],
    'Secret with a word after it' => ['Pikachu (Secret Edition)'],
    'a metal card' => ['Mew ex - 205/165 (151 Metal Card)'],
]);
