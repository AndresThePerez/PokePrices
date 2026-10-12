<?php

declare(strict_types=1);

use App\Domain\Mapping\CardNumber;
use App\Domain\Mapping\NameAliases;
use App\Domain\Mapping\ProductName;

// Reviewer edge cases for T37 (set alias table) and T38 (tag grammar, number key, name aliases). The first three
// tests read the real 2026-10-11 snapshot and the dataset when `.data` is present (it is not in CI; they then
// only assert that the data root is a string and return).

mutates(ProductName::class, CardNumber::class, NameAliases::class);

/** The repository's .data directory, or null when it is absent (CI). */
function t37rData(): ?string
{
    $root = dirname(__DIR__, 4).'/.data';

    return is_dir($root.'/snapshots/2026-10-11') && glob($root.'/dataset/*/sets/en.json') !== [] ? $root : null;
}

/** @return list<array<string, string>> */
function t37rCsvRows(): array
{
    $lines = file(dirname(__DIR__, 3).'/database/data/set_aliases.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $header = str_getcsv((string) array_shift($lines), ',', '"', '');
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = array_combine(array_map('strval', $header), array_map('strval', str_getcsv($line, ',', '"', '')));
    }

    return $rows;
}

/** @return list<array{int, int, string, string|null}> [group, productId, name, Number] for every product of a group */
function t37rProducts(string $root, int $group): array
{
    $json = json_decode((string) zstd_uncompress((string) file_get_contents("{$root}/snapshots/2026-10-11/products/{$group}.json.zst")), true);
    $out = [];
    foreach ($json['results'] as $p) {
        $number = null;
        foreach ($p['extendedData'] ?? [] as $e) {
            if ($e['name'] === 'Number') {
                $number = (string) $e['value'];
            }
        }
        $out[] = [$group, (int) $p['productId'], (string) $p['name'], $number];
    }

    return $out;
}

test('the CSV agrees with the real groups.json and dataset: ids exist, vocabularies hold, xy9 is 1701, 2175 is nowhere', function (): void {
    $root = t37rData();
    if ($root === null) {
        expect($root)->toBeNull();

        return;
    }
    $setIds = array_column(json_decode((string) file_get_contents(glob($root.'/dataset/*/sets/en.json')[0]), true), 'id');
    $groupIds = array_column(json_decode((string) file_get_contents($root.'/snapshots/2026-10-11/groups.json'), true)['results'], 'groupId');
    $rows = t37rCsvRows();

    $pairs = [];
    foreach ($rows as $r) {
        expect($setIds)->toContain($r['pokesearch_set_id']);
        if ($r['source_group_id'] !== '') {
            expect($groupIds)->toContain((int) $r['source_group_id']);
            expect(in_array($r['role'], ['primary', 'edition-split', 'secondary'], true))->toBeTrue();
            expect(in_array($r['method'], ['name', 'ptcgo', 'manual'], true))->toBeTrue();
        }
        expect($r['unlimited_means'] === '' || in_array($r['unlimited_means'], ['unlimited', 'shadowless'], true))->toBeTrue();
        $pairs[] = $r['pokesearch_set_id'].'|'.$r['source_group_id'];
        expect(str_contains(implode(',', $r), '2175'))->toBeFalse();
    }
    expect(array_diff($setIds, array_column($rows, 'pokesearch_set_id')))->toBe([]);
    expect(count(array_unique($pairs)))->toBe(count($pairs));
    $xy9 = array_values(array_filter($rows, fn (array $r): bool => $r['pokesearch_set_id'] === 'xy9'));
    expect(array_column($xy9, 'source_group_id'))->toBe(['1701']);
});

test('every product name of every mapped group parses to a clean base and a slug that satisfies the T36 CHECK', function (): void {
    $root = t37rData();
    if ($root === null) {
        expect($root)->toBeNull();

        return;
    }
    $groups = array_unique(array_map(fn (array $r): int => (int) $r['source_group_id'], array_filter(t37rCsvRows(), fn (array $r): bool => $r['source_group_id'] !== '')));
    $total = 0;
    $leaks = [];
    $badSlugs = [];
    foreach ($groups as $group) {
        foreach (t37rProducts($root, $group) as [, $pid, $name]) {
            $total++;
            $p = ProductName::parse($name);
            $treatment = $p->treatment();
            if ($treatment !== null && $treatment !== '-' && preg_match('/\A[a-z0-9]+(-[a-z0-9]+)*\z/', $treatment) !== 1) {
                $badSlugs[] = "{$pid} {$name} => {$treatment}";
            }
            // A bracket left in the base means a tag was dropped without being classified, so a clean treatment
            // would be a guess; the product must at least be flagged unknown.
            if (preg_match('/[()\[\]]/', $p->base) === 1 && ! $p->hasUnknownTag()) {
                $leaks[] = "{$pid} {$name} => base '{$p->base}', treatment ".var_export($treatment, true);
            }
        }
    }
    expect($total)->toBeGreaterThan(20000);
    expect($badSlugs)->toBe([]);
    expect($leaks)->toBe([]);
});

test('for every mapped set the grammar and number key line dataset cards up with products of the same number and name (85 percent per set)', function (): void {
    $root = t37rData();
    if ($root === null) {
        expect($root)->toBeNull();

        return;
    }
    $rows = array_filter(t37rCsvRows(), fn (array $r): bool => $r['source_group_id'] !== '');
    $bySet = [];
    foreach ($rows as $r) {
        $bySet[$r['pokesearch_set_id']][] = (int) $r['source_group_id'];
    }
    $worst = [];
    foreach ($bySet as $setId => $groups) {
        $cards = json_decode((string) file_get_contents(glob($root."/dataset/*/cards/en/{$setId}.json")[0]), true);
        $byNumber = [];
        foreach ($groups as $group) {
            foreach (t37rProducts($root, $group) as [, , $name, $number]) {
                if ($number === null) {
                    continue;
                }
                $byNumber[CardNumber::normalise($number)][] = ProductName::parse($name)->base;
            }
        }
        $agree = 0;
        foreach ($cards as $card) {
            foreach ($byNumber[CardNumber::normalise((string) $card['number'])] ?? [] as $base) {
                // the base keeps ` - 008/102`-style numbers only when the name has none to strip
                $base = (string) preg_replace('#\s+-\s+\S+\z#', '', $base);
                // Hyphenated -EX/-GX and the prism-star diamond are spelled differently by the two sides and are not
                // among the plan's named aliases (T39's concern); fold them here so the check is about numbers and bases.
                $cardName = str_replace(['-', '◇'], [' ', 'Prism Star'], (string) $card['name']);
                if (NameAliases::equal($cardName, str_replace('-', ' ', $base))) {
                    $agree++;
                    break;
                }
            }
        }
        $rate = $agree / max(1, count($cards));
        if ($rate < 0.85) {
            $worst[$setId] = round($rate, 2);
        }
    }
    expect($worst)->toBe([]);
});

test('stamp tags join in name order, ignore case and accents, and combine with a pattern into a valid slug', function (): void {
    expect(ProductName::parse('Pikachu (PRERELEASE) [STAFF] - 025/100')->treatment())->toBe('stamp-prerelease-staff');
    expect(ProductName::parse('Pikachu [Pokémon Center NY]')->treatment())->toBe('stamp-pokemon-center-ny');
    expect(ProductName::parse('Pikachu (e-League) [Winner]')->treatment())->toBe('stamp-e-league-winner');
    $combo = ProductName::parse('Charizard (Poke Ball Pattern) (Prerelease) [Staff]');
    expect($combo->treatment())->toMatch('/\A[a-z0-9]+(-[a-z0-9]+)*\z/');
    expect($combo->treatment())->toBe('pokeball-stamp-prerelease-staff');
    expect($combo->finish('Holofoil'))->toBe('reverse');
});

test('Cosmos Holofoil and Cosmo Holo are one treatment and spacing and case inside a tag do not matter', function (): void {
    foreach (['(Cosmos Holofoil)', '(Cosmo Holo)', '(COSMOS   HOLO)', '(  cosmo holofoil )'] as $tag) {
        expect(ProductName::parse("Pikachu {$tag} - SVP001")->treatment())->toBe('cosmos');
    }
    expect(ProductName::parse('Pikachu (Cosmos)')->hasUnknownTag())->toBeTrue();
    expect(ProductName::parse('Pikachu (Cosmos)')->treatment())->toBeNull();
});

test('an unbalanced or nested bracket never yields a clean stamp treatment (real product 1451/108598)', function (): void {
    $broken = ProductName::parse('Chesnaught - XY68 (Prerelease [Staff]');

    // Either the full tag set is read (prerelease + staff) or the product is flagged unknown. Reading only
    // `[Staff]` and returning `stamp-staff` with no unknown flag is a silently wrong treatment.
    expect($broken->hasUnknownTag() || $broken->treatment() === 'stamp-prerelease-staff')->toBeTrue();
    expect($broken->base)->not->toContain('(');

    $nested = ProductName::parse('Vivillon (High Plains [Orange])');
    expect($nested->base)->toBe('Vivillon');
    expect($nested->hasUnknownTag())->toBeTrue();
    expect($nested->treatment())->toBeNull();
});

test('card number keys: prefixes, suffixes, huge zero runs and case-insensitive routing hold', function (): void {
    expect(CardNumber::normalise('RC5/RC32'))->toBe('RC5');
    expect(CardNumber::isSecondary('RC5/RC32'))->toBeTrue();
    expect(CardNumber::isSecondary('rc5'))->toBeTrue();
    expect(CardNumber::normalise('098a'))->toBe('98a');
    expect(CardNumber::normalise('TG03/TG30'))->toBe('TG3');
    expect(CardNumber::isSecondary('GG05/GG70'))->toBeTrue();
    expect(CardNumber::isSecondary('SV012/SV94'))->toBeTrue();
    expect(CardNumber::isSecondary('SWSH039'))->toBeFalse();
    expect(CardNumber::isSecondary('015/113'))->toBeFalse();
    expect(CardNumber::normalise(str_repeat('0', 40).'7'))->toBe('7');
    expect(CardNumber::normalise(''))->toBe('');
    expect(CardNumber::normalise('!'))->not->toBe(CardNumber::normalise('?'));
    expect(CardNumber::normalise(CardNumber::normalise('RC005/RC032')))->toBe('RC5');
    expect(CardNumber::comparesOnName('cel25c-15_A1'))->toBeTrue();
    expect(CardNumber::comparesOnName('cel25c-17_A'))->toBeTrue();
    expect(CardNumber::comparesOnName('cel25c-17'))->toBeFalse();
    expect(CardNumber::comparesOnName('sv1-15_Abc'))->toBeFalse();
});

test('name folding does not conflate different cards and still joins the spellings the plan names', function (): void {
    expect(NameAliases::equal('Unown A', 'Unown B'))->toBeFalse();
    expect(NameAliases::equal('Nidoran♂', 'Nidoran♀'))->toBeFalse();
    expect(NameAliases::equal('Nidoran♂', 'Nidoran M'))->toBeTrue();
    expect(NameAliases::equal('Umbreon ★', 'Umbreon Star'))->toBeTrue();
    expect(NameAliases::equal('Gardevoir ex δ', 'Gardevoir ex (Delta Species)'))->toBeTrue();
    expect(NameAliases::equal('Gardevoir ex', 'Gardevoir ex (Delta Species)'))->toBeFalse();
    expect(NameAliases::equal('Imposter Professor Oak', 'Impostor Professor Oak'))->toBeTrue();
    expect(NameAliases::equal('Nitro Fire Energy', 'Nitro R Energy'))->toBeTrue();
    expect(NameAliases::equal('Nitro Fire Energy', 'Nitro W Energy'))->toBeFalse();
    expect(NameAliases::equal('Pokémon Breeder', 'Pokemon Breeder'))->toBeTrue();
    expect(NameAliases::equal('Pokémon Breeder', 'Pokemon Breeders'))->toBeFalse();
    expect(NameAliases::equal('', ''))->toBeTrue();
    expect(NameAliases::equal('Eevee', ''))->toBeFalse();
});

test('every broken bracket shape parses as unknown with no bracket left in the base', function (string $name): void {
    $p = ProductName::parse($name);

    expect($p->hasUnknownTag())->toBeTrue();
    expect($p->treatment())->toBeNull();
    expect($p->finish('Holofoil'))->toBeNull();
    expect($p->base)->not->toMatch('/[()\[\]]/');
})->with([
    'doubled parentheses' => ['Pikachu ((double)) - 025/100'],
    'leading closer' => [') Pikachu - 025/100'],
    'stray closer after a good tag' => ['Pikachu (Prerelease) ] - 025/100'],
    'empty brackets' => ['Pikachu [] - 025/100'],
    'empty parentheses' => ['Pikachu () - 025/100'],
    'mismatched pair' => ['Pikachu (Prerelease] - 025/100'],
    'never closed with accents' => ['Pokémon Center Pikachu (Prerelease [Staff]'],
    'opener only' => ['Pikachu ('],
    'nested then good tag' => ['Pikachu (a [b]) (Prerelease)'],
]);

test('balanced separate tags still read in full next to each other and around the number', function (): void {
    $p = ProductName::parse('Pikachu (Prerelease) [Staff] (Poke Ball Pattern) - 025/100');
    expect($p->base)->toBe('Pikachu');
    expect($p->hasUnknownTag())->toBeFalse();
    expect($p->treatment())->toBe('pokeball-stamp-prerelease-staff');
    expect($p->finish('Normal'))->toBe('reverse');

    $delta = ProductName::parse('Mr. Mime (Delta Species) (22)');
    expect($delta->base)->toBe('Mr. Mime');
    expect($delta->isDelta())->toBeTrue();
    expect($delta->treatment())->toBe('-');

    $stamp = ProductName::parse('Chesnaught - XY68 (Prerelease) [Staff]');
    expect($stamp->treatment())->toBe('stamp-prerelease-staff');
    expect($stamp->base)->toBe('Chesnaught');
});
