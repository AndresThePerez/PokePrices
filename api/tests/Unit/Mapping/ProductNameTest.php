<?php

declare(strict_types=1);

use App\Domain\Mapping\NameAliases;
use App\Domain\Mapping\ProductName;

// The TCGplayer product-name tag grammar and the card-name aliases (plan T38 items 1–3 and 5, adversary recs 2–3).
// Product names are verbatim from groups of the collected day 2026-10-11 where the plan names a case.

mutates(ProductName::class, NameAliases::class);

const T38_SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

/** @return list<array{string, string|null}> each tag of $name as [class, value] */
function t38Tags(string $name): array
{
    return array_map(fn (array $t): array => [$t['class'], $t['value']], ProductName::parse($name)->tags);
}

test('the trailing number is stripped after the tags are removed', function (string $name, string $base): void {
    expect(ProductName::parse($name)->base)->toBe($base);
})->with([
    'Starmie - 015/113 (Delta Species)' => ['Starmie - 015/113 (Delta Species)', 'Starmie'],
    'Eevee - 068/113' => ['Eevee - 068/113', 'Eevee'],
    "Erika's Tangela - 007/217 (Poke Ball)" => ["Erika's Tangela - 007/217 (Poke Ball)", "Erika's Tangela"],
    'Tinkaton - 020 (Prerelease) [Staff]' => ['Tinkaton - 020 (Prerelease) [Staff]', 'Tinkaton'],
    'Dark Ivysaur - 6 [Winner]' => ['Dark Ivysaur - 6 [Winner]', 'Dark Ivysaur'],
    'Mew - 8 (Glossy Finish)' => ['Mew - 8 (Glossy Finish)', 'Mew'],
    'Charizard - RC5/RC32' => ['Charizard - RC5/RC32', 'Charizard'],
    'Ultra Ball - 213/217' => ['Ultra Ball - 213/217', 'Ultra Ball'],
    'Leafeon' => ['Leafeon', 'Leafeon'],
    'Porygon-Z keeps its hyphen' => ['Porygon-Z', 'Porygon-Z'],
]);

test('Eevee - 068/113 has no tag and treatment -', function (): void {
    $p = ProductName::parse('Eevee - 068/113');
    expect($p->tags)->toBe([]);
    expect($p->treatment())->toBe('-');
    expect($p->isDelta())->toBeFalse();
    expect($p->hasUnknownTag())->toBeFalse();
});

test('the trainer-kit deck tags are class deck', function (string $name, string $deck): void {
    $p = ProductName::parse($name);
    expect(t38Tags($name))->toBe([['deck', $deck]]);
    expect($p->deck())->toBe($deck);
    expect($p->treatment())->toBe('-');
})->with([
    'Energy Search (Latias)' => ['Energy Search (Latias)', 'Latias'],
    'Energy Search (Latios)' => ['Energy Search (Latios)', 'Latios'],
    'Potion (Plusle)' => ['Potion (Plusle)', 'Plusle'],
    'Potion (Minun)' => ['Potion (Minun)', 'Minun'],
]);

test('Delta Species tags are class delta, including the Forme form', function (string $name, string $base): void {
    $p = ProductName::parse($name);
    expect($p->base)->toBe($base);
    expect(array_column($p->tags, 'class'))->toBe(['delta']);
    expect($p->isDelta())->toBeTrue();
    expect($p->treatment())->toBe('-');
})->with([
    'Starmie - 015/113 (Delta Species)' => ['Starmie - 015/113 (Delta Species)', 'Starmie'],
    'Deoxys (Delta Species - Attack Forme)' => ['Deoxys (Delta Species - Attack Forme)', 'Deoxys'],
    'Charizard Star (Delta Species)' => ['Charizard Star (Delta Species)', 'Charizard Star'],
]);

test('every ball tag, with or without Pattern, is its own treatment', function (string $name, string $treatment): void {
    $p = ProductName::parse($name);
    expect(t38Tags($name))->toBe([['treatment', $treatment]]);
    expect($p->treatment())->toBe($treatment);
})->with([
    "me2pt5 Erika's Oddish (Poke Ball)" => ["Erika's Oddish (Poke Ball)", 'pokeball'],
    'me2pt5 Chikorita (Friend Ball)' => ['Chikorita (Friend Ball)', 'friendball'],
    'me2pt5 Wurmple (Love Ball)' => ['Wurmple (Love Ball)', 'loveball'],
    'me2pt5 Grubbin (Quick Ball)' => ['Grubbin (Quick Ball)', 'quickball'],
    'me2pt5 Banette - 091/217 (Dusk Ball)' => ['Banette - 091/217 (Dusk Ball)', 'duskball'],
    'sv8pt5 Leafeon (Poke Ball Pattern)' => ['Leafeon (Poke Ball Pattern)', 'pokeball'],
    'sv8pt5 Leafeon (Master Ball Pattern)' => ['Leafeon (Master Ball Pattern)', 'masterball'],
    'Master Ball without Pattern' => ['Leafeon (Master Ball)', 'masterball'],
    'Friend Ball Pattern' => ['Chikorita (Friend Ball Pattern)', 'friendball'],
    'Love Ball Pattern' => ['Wurmple (Love Ball Pattern)', 'loveball'],
    'Quick Ball Pattern' => ['Grubbin (Quick Ball Pattern)', 'quickball'],
    'Dusk Ball Pattern' => ['Banette (Dusk Ball Pattern)', 'duskball'],
    'Energy Symbol Pattern' => ['Banette - 091/217 (Energy Symbol Pattern)', 'energy'],
]);

test("me2pt5's five ball variants and the energy pattern of one card are five distinct treatments plus energy", function (): void {
    $treatments = array_map(fn (string $n): ?string => ProductName::parse($n)->treatment(), [
        'Banette - 091/217',
        'Banette - 091/217 (Poke Ball)',
        'Banette - 091/217 (Friend Ball)',
        'Banette - 091/217 (Love Ball)',
        'Banette - 091/217 (Quick Ball)',
        'Banette - 091/217 (Dusk Ball)',
        'Banette - 091/217 (Energy Symbol Pattern)',
    ]);
    expect($treatments)->toBe(['-', 'pokeball', 'friendball', 'loveball', 'quickball', 'duskball', 'energy']);
});

test("svp's four Cosmos spellings are all treatment cosmos", function (string $name): void {
    expect(t38Tags($name))->toBe([['treatment', 'cosmos']]);
    expect(ProductName::parse($name)->treatment())->toBe('cosmos');
})->with([
    'Houndstone - 042 (Cosmos Holo)',
    'Tinkaton - 140 (Cosmo Holo)',
    'Tinkatink - 025 (Cosmo Holofoil)',
    'Dondozo - 012 (Cosmos Holofoil)',
]);

test('the other named treatments get their planned slug', function (string $name, string $treatment): void {
    expect(ProductName::parse($name)->treatment())->toBe($treatment);
})->with([
    'Cracked Ice' => ['Accelgor - 12/101 (Cracked Ice)', 'cracked-ice'],
    'Cracked Ice Holo, the 1840 spelling' => ['Accelgor - 12/101 (Cracked Ice Holo)', 'cracked-ice'],
    'Black Dot Error' => ['Pikachu (Black Dot Error)', 'error-black-dot'],
    'Misprint' => ['Machamp (Misprint)', 'misprint'],
    'Glossy' => ['Mew (Glossy)', 'glossy'],
    'Glossy Finish' => ['Mew - 8 (Glossy Finish)', 'glossy'],
]);

test('a stamp treatment is the normalised full tag set joined with -', function (string $name, string $treatment): void {
    expect(ProductName::parse($name)->treatment())->toBe($treatment);
})->with([
    '(Prerelease)' => ['Tinkaton - 020 (Prerelease)', 'stamp-prerelease'],
    '(Prerelease) [Staff]' => ['Tinkaton - 020 (Prerelease) [Staff]', 'stamp-prerelease-staff'],
    '[Winner]' => ['Dark Ivysaur - 6 [Winner]', 'stamp-winner'],
    '(e-League) [Winner]' => ['Combusken - 009 (e-League) [Winner]', 'stamp-e-league-winner'],
    '[Pokemon Center NY]' => ['Rapidash [Pokemon Center NY]', 'stamp-pokemon-center-ny'],
    'JR East Stamp Rally' => ['Eevee (JR East Stamp Rally)', 'stamp-jr-east-stamp-rally'],
]);

test('malformed brackets leave the base and make the product unknown, never a partial treatment', function (string $name, string $base): void {
    $p = ProductName::parse($name);
    expect($p->base)->toBe($base);
    expect($p->hasUnknownTag())->toBeTrue();
    expect($p->treatment())->toBeNull();
})->with([
    'unclosed opener, 108598' => ['Chesnaught - XY68 (Prerelease [Staff]', 'Chesnaught'],
    'unclosed bracket, 644359' => ['Mega Evolution Single Pack Blister [Drifblim', 'Mega Evolution Single Pack Blister'],
    'nested' => ['Vivillon (High Plains [Orange])', 'Vivillon'],
    'mismatched pair' => ['Pikachu (Prerelease]', 'Pikachu'],
    'stray closer' => ['Pikachu ) (Prerelease)', 'Pikachu'],
]);

test('a stamp pair of one card stays two distinct treatments', function (): void {
    expect(ProductName::parse('Quaquaval - 005 (Prerelease)')->treatment())
        ->not->toBe(ProductName::parse('Quaquaval - 005 (Prerelease) [Staff]')->treatment());
});

test('every treatment slug satisfies the card_variants treatment CHECK', function (string $name): void {
    $treatment = ProductName::parse($name)->treatment();
    expect($treatment)->toBeString()->not->toBe('-')->toMatch(T38_SLUG)->not->toContain(':');
})->with([
    'Leafeon (Poke Ball Pattern)', 'Leafeon (Master Ball Pattern)', 'Chikorita (Friend Ball)', 'Wurmple (Love Ball)',
    'Grubbin (Quick Ball)', 'Banette - 091/217 (Dusk Ball)', 'Banette - 091/217 (Energy Symbol Pattern)',
    'Houndstone - 042 (Cosmos Holo)', 'Accelgor - 12/101 (Cracked Ice)', 'Pikachu (Black Dot Error)',
    'Machamp (Misprint)', 'Mew (Glossy)', 'Tinkaton - 020 (Prerelease) [Staff]', 'Combusken - 009 (e-League) [Winner]',
    'Rapidash [Pokemon Center NY]', 'Eevee (JR East Stamp Rally)', 'Machamp - 8/102 (Base Set Shadowless)',
]);

test('number echoes are class number_echo', function (string $name, string $base): void {
    $p = ProductName::parse($name);
    expect($p->base)->toBe($base);
    expect(array_column($p->tags, 'class'))->toBe(['number_echo']);
    expect($p->treatment())->toBe('-');
})->with([
    '(22)' => ['Articuno (22)', 'Articuno'],
    '(1)' => ['Aerodactyl (1)', 'Aerodactyl'],
    'Unown (!) echoes number !/28 of product 90166' => ['Unown (!)', 'Unown'],
    'Unown (?) echoes number ?/28 of product 90167' => ['Unown (?)', 'Unown'],
    'Unown (A)' => ['Unown (A)', 'Unown'],
]);

test('Pikachu (1) (Misprint) keeps both tags in order', function (): void {
    expect(t38Tags('Pikachu (1) (Misprint)'))->toBe([['number_echo', '1'], ['treatment', 'misprint']]);
});

test('neutral tags change nothing', function (string $name, string $base): void {
    $p = ProductName::parse($name);
    expect($p->base)->toBe($base);
    expect(array_column($p->tags, 'class'))->toBe(['neutral']);
    expect($p->treatment())->toBe('-');
    expect($p->deck())->toBeNull();
})->with([
    'Special' => ['Darkness Energy (Special)', 'Darkness Energy'],
    'Full Art' => ['Acerola (Full Art)', 'Acerola'],
    'Non-Holo' => ['Aegislash - 134/182 (Non-Holo)', 'Aegislash'],
    'Ditto (Bulbasaur) is a look-alike, not a deck' => ['Ditto (Bulbasaur)', 'Ditto'],
    'Ditto - 037/113 (Charmander)' => ['Ditto - 037/113 (Charmander)', 'Ditto'],
    'Deoxys (Attack Forme)' => ['Deoxys (Attack Forme)', 'Deoxys'],
]);

test('Base Set Shadowless on 1840 Machamp 107004 is treatment shadowless (OQ6 default)', function (): void {
    expect(ProductName::parse('Machamp - 8/102 (Base Set Shadowless)')->treatment())->toBe('shadowless');
    expect(ProductName::parse('Machamp - 8/102')->treatment())->toBe('-');
});

test('an unknown tag is class unknown and never treatment -', function (string $name): void {
    $p = ProductName::parse($name);
    expect(array_column($p->tags, 'class'))->toContain('unknown');
    expect($p->hasUnknownTag())->toBeTrue();
    expect($p->treatment())->toBeNull();
    expect($p->finish('Normal'))->toBeNull();
})->with([
    'Latias - 014 (Pokemon Heroes: Latios & Latias DVD Release)',
    'Shellos East Sea - 106/132 (Origins Game Fair 2008) [Staff]',
    'Leafeon - 11/116 (BW Plasma Freeze)',
    'Pikachu (Bulbasaur)',
    'Bulbasaur (Latias Deck)',
]);

test('a ball or energy treatment makes the finish reverse whatever subTypeName says', function (string $name, string $subType): void {
    expect(ProductName::parse($name)->finish($subType))->toBe('reverse');
})->with([
    'Leafeon (Poke Ball Pattern) 610540 under Holofoil' => ['Leafeon (Poke Ball Pattern)', 'Holofoil'],
    'Leafeon (Master Ball Pattern) 610641 under Holofoil' => ['Leafeon (Master Ball Pattern)', 'Holofoil'],
    'me2pt5 Poke Ball under Reverse Holofoil' => ["Erika's Oddish (Poke Ball)", 'Reverse Holofoil'],
    'me2pt5 Friend Ball under Reverse Holofoil' => ['Chikorita (Friend Ball)', 'Reverse Holofoil'],
    'me2pt5 Love Ball under Reverse Holofoil' => ['Wurmple (Love Ball)', 'Reverse Holofoil'],
    'me2pt5 Quick Ball under Reverse Holofoil' => ['Grubbin (Quick Ball)', 'Reverse Holofoil'],
    'me2pt5 Dusk Ball under Reverse Holofoil' => ['Banette - 091/217 (Dusk Ball)', 'Reverse Holofoil'],
    'Energy Symbol Pattern under Normal' => ['Banette - 091/217 (Energy Symbol Pattern)', 'Normal'],
]);

test('without a pattern treatment the finish follows subTypeName', function (string $name, string $subType, ?string $finish): void {
    expect(ProductName::parse($name)->finish($subType))->toBe($finish);
})->with([
    'Leafeon 610360 Holofoil' => ['Leafeon', 'Holofoil', 'holo'],
    'Leafeon 610360 Reverse Holofoil' => ['Leafeon', 'Reverse Holofoil', 'reverse'],
    'Normal' => ['Eevee - 068/113', 'Normal', 'normal'],
    '1st Edition' => ['Charmander', '1st Edition', 'normal'],
    'Unlimited' => ['Charmander', 'Unlimited', 'normal'],
    '1st Edition Holofoil' => ['Machamp - 8/102', '1st Edition Holofoil', 'holo'],
    'Unlimited Holofoil' => ['Charizard', 'Unlimited Holofoil', 'holo'],
    'Cosmos keeps Holofoil' => ['Houndstone - 042 (Cosmos Holo)', 'Holofoil', 'holo'],
    'an unknown subtype' => ['Leafeon', 'Foil Etched', null],
]);

test('NameAliases folds accents', function (): void {
    expect(NameAliases::equal('Pokémon Center', 'Pokemon Center'))->toBeTrue();
    expect(NameAliases::equal('Flabébé', 'Flabebe'))->toBeTrue();
});

test('NameAliases matches δ with (Delta Species)', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeTrue();
})->with([
    'Starmie δ' => ['Starmie δ', 'Starmie (Delta Species)'],
    'Deoxys δ' => ['Deoxys δ', 'Deoxys (Delta Species - Attack Forme)'],
    'Charizard ★ δ against Charizard Star (Delta Species)' => ['Charizard ★ δ', 'Charizard Star (Delta Species)'],
]);

test('Eevee - 068/113 has no delta tag and still equals the card Eevee δ (card-side delta rule)', function (): void {
    $base = ProductName::parse('Eevee - 068/113')->base;
    expect($base)->toBe('Eevee');
    expect(NameAliases::equal('Eevee δ', $base))->toBeTrue();
    expect(NameAliases::equal('Eevee', $base))->toBeTrue();
});

test('NameAliases does not let a Delta Species product stand for a plain card', function (): void {
    expect(NameAliases::equal('Starmie', 'Starmie (Delta Species)'))->toBeFalse();
});

test('NameAliases reads a leading Delta on an energy product as the δ marker', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeTrue();
})->with([
    'δ Rainbow Energy ↔ Delta Rainbow Energy' => ['δ Rainbow Energy', 'Delta Rainbow Energy'],
    'the base ProductName gives the builder' => ['δ Rainbow Energy', ProductName::parse('Delta Rainbow Energy')->base],
    'the marker comes before the letter fold' => ['δ Fire Energy', 'Delta R Energy'],
]);

test('NameAliases keeps the energy Delta rule one-way and scoped to energy names', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeFalse();
})->with([
    'a plain card is not a Delta product' => ['Rainbow Energy', 'Delta Rainbow Energy'],
    'a sealed Delta Reign product' => ['Reign Booster Box', 'Delta Reign Booster Box'],
    'a sealed Delta Species product' => ['Species Booster Pack', 'Delta Species Booster Pack'],
    'a bare Delta Energy is not a marker' => ['δ Energy', 'Delta Energy'],
    'Deltas is not the marker' => ['δ Rainbow Energy', 'Deltas Rainbow Energy'],
    'Delta- is not the marker' => ['δ Rainbow Energy', 'Delta-Rainbow Energy'],
    'a non-energy Delta Pokémon name' => ['Delta Pikachu', 'Pikachu'],
    'a non-energy product with a Delta prefix' => ['Pikachu δ', 'Delta Pikachu'],
    'another energy name' => ['δ Rainbow Energy', 'Delta Holon Energy'],
]);

test('NameAliases keeps Delta inside sealed product names', function (): void {
    expect(NameAliases::equal('Delta Reign Booster Box', 'Delta Reign Booster Box'))->toBeTrue();
    expect(NameAliases::equal('δ Species Booster Pack', 'Delta Species Booster Pack'))->toBeFalse();
});

test('NameAliases matches the remaining planned aliases', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeTrue();
})->with([
    '★ ↔ Star' => ['Latias ★', 'Latias Star'],
    '♂ ↔ M' => ['Nidoran ♂', 'Nidoran M'],
    '♀ ↔ F' => ['Nidoran ♀', 'Nidoran F'],
    'Impostor ↔ Imposter' => ["Impostor Oak's Revenge", "Imposter Oak's Revenge"],
    'Drowzee ↔ Drowsee' => ['Drowzee', 'Drowsee'],
    'Bubbly W Energy ↔ Bubbly Water Energy' => ['Bubbly Water Energy', 'Bubbly W Energy'],
    'Nitro R Energy ↔ Nitro Fire Energy' => ['Nitro Fire Energy', 'Nitro R Energy'],
    'R Fire' => ['Fire Energy', 'R Energy'],
    'W Water' => ['Water Energy', 'W Energy'],
    'G Grass' => ['Grass Energy', 'G Energy'],
    'L Lightning' => ['Lightning Energy', 'L Energy'],
    'P Psychic' => ['Psychic Energy', 'P Energy'],
    'F Fighting' => ['Fighting Energy', 'F Energy'],
    'D Darkness' => ['Darkness Energy', 'D Energy'],
    'M Metal' => ['Metal Energy', 'M Energy'],
    'letter form on the card side' => ['Bubbly W Energy', 'Bubbly Water Energy'],
]);

test('NameAliases still tells different names apart', function (string $card, string $product): void {
    expect(NameAliases::equal($card, $product))->toBeFalse();
})->with([
    'Venusaur vs Moltres' => ['Venusaur', 'Moltres'],
    'Nidoran ♂ vs Nidoran F' => ['Nidoran ♂', 'Nidoran F'],
    'Fire vs W Energy' => ['Fire Energy', 'W Energy'],
    'M Gardevoir-EX is Mega, not Metal' => ['M Gardevoir-EX', 'Metal Gardevoir-EX'],
    'Charizard vs Charizard Star' => ['Charizard', 'Charizard Star'],
    'a prefix is not equality' => ['Venusaur', 'Venusaur ex'],
]);
