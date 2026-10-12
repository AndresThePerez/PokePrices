<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Edge cases for the product-map tables (plan T36, R131). Reviewer-authored; self-contained helpers (a test file must not depend on another file's functions).

function edgeSeed(): array
{
    $build = DB::table('map_builds')->insertGetId([
        'snapshot_day' => '2026-10-11',
        'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
        'started_at' => '2026-10-12 08:00:00+00',
        'counts' => '{}',
    ]);
    foreach (['unlimited', 'shadowless'] as $edition) {
        DB::table('card_variants')->insert([
            'variant_key' => "base1-4:holo:{$edition}:-",
            'card_id' => 'base1-4',
            'finish' => 'holo',
            'edition' => $edition,
        ]);
    }

    return ['@build' => $build, '@variant' => 'base1-4:holo:unlimited:-', '@variant2' => 'base1-4:holo:shadowless:-'];
}

function edgeRow(string $table, array $ids, array $overrides = []): array
{
    $mapping = [
        'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => 42382, 'source_subtype' => 'Holofoil',
        'source_product_name' => 'Charizard', 'source_number' => '4/102', 'variant_key' => '@variant',
        'method' => 'number+name', 'confidence' => '1.000', 'name_agreement' => true, 'series_epoch' => 1,
        'map_build_id' => '@build',
    ];
    $base = match ($table) {
        'set_aliases' => [
            'pokesearch_set_id' => 'base1', 'source' => 'tcgplayer', 'source_group_id' => 604, 'role' => 'primary',
            'method' => 'manual', 'unlimited_means' => 'unlimited', 'note' => 'groups.json of 2026-10-11',
        ],
        'card_variants' => ['card_id' => 'base1-58', 'finish' => 'normal', 'edition' => '-', 'treatment' => '-'],
        'source_product_map' => $mapping,
        'source_product_map_history' => $mapping + ['ended_at' => '2026-10-12 09:00:00+00', 'end_reason' => 'remapped'],
        'map_builds' => [
            'snapshot_day' => '2026-10-11', 'dataset_ref' => '39a26a144c8b6ef6c2fb17b2c29d0bb7121e3a11',
            'started_at' => '2026-10-12 08:00:00+00', 'counts' => '{"cards": 0}',
        ],
        'unmapped_source_items' => [
            'map_build_id' => '@build', 'source' => 'tcgplayer', 'source_group_id' => 604, 'source_product_id' => 99999,
            'source_subtype' => 'Normal', 'product_name' => 'Base Set Booster Pack', 'reason' => 'non_card',
        ],
    };
    $row = array_merge($base, $overrides);
    if ($table === 'card_variants' && ! array_key_exists('variant_key', $overrides)) {
        $row['variant_key'] = implode(':', [$row['card_id'], $row['finish'], $row['edition'], $row['treatment']]);
    }

    return array_map(fn ($v) => is_string($v) && array_key_exists($v, $ids) ? $ids[$v] : $v, $row);
}

function edgeError(Closure $insert): ?QueryException
{
    try {
        DB::transaction($insert);
    } catch (QueryException $e) {
        return $e;
    }

    return null;
}

/** Seeds, then inserts $overrides into $table and returns the QueryException, or null when the row was accepted. */
function edgeInsert(string $table, array $overrides): ?QueryException
{
    $ids = edgeSeed();

    return edgeError(fn () => DB::table($table)->insert(edgeRow($table, $ids, $overrides)));
}

test('card_id rejects unicode lookalikes, accents and a trailing newline (card_id regex is ASCII-only and anchored)', function (string $cardId): void {
    $error = edgeInsert('card_variants', ['card_id' => $cardId]);

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23514')
        ->and($error->getMessage())->toContain('card_variants_card_id_check');
})->with([
    'fullwidth letter' => ["\u{FF42}ase1-4"],
    'cyrillic a' => ["b\u{0430}se1-4"],
    'accented e' => ["pok\u{00E9}1-4"],
    'trailing newline' => ["base1-4\n"],
    'leading newline' => ["\nbase1-4"],
    'colon inside' => ['base1:4'],
    'empty' => [''],
    'leading dot' => ['.base1'],
    'leading bang' => ['!base1'],
])->group('DC3');

test('treatment accepts only lower-case dash-joined slugs, or a lone dash', function (string $treatment, bool $valid): void {
    $error = edgeInsert('card_variants', ['treatment' => $treatment]);

    if ($valid) {
        expect($error)->toBeNull();

        return;
    }
    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23514')
        ->and($error->getMessage())->toContain('card_variants_treatment_check');
})->with([
    'digit only' => ['0', true],
    'three parts' => ['a-b-c', true],
    'leading dash' => ['-foo', false],
    'trailing dash' => ['foo-', false],
    'underscore' => ['a_b', false],
    'accent' => ["caf\u{00E9}", false],
    'trailing newline' => ["foo\n", false],
    'space' => ['cracked ice', false],
    'fullwidth colon' => ["a\u{FF1A}b", false],
    'plain colon' => ['a:b', false],
])->group('DC3');

test('variant_key must equal its four parts joined by colons, including for treatment "-"', function (string $key, bool $valid): void {
    $error = edgeInsert('card_variants', ['variant_key' => $key]);

    if ($valid) {
        expect($error)->toBeNull();

        return;
    }
    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23514')
        ->and($error->getMessage())->toContain('card_variants_variant_key_check');
})->with([
    'exact with dash treatment' => ['base1-58:normal:-:-', true],
    'dash treatment dropped' => ['base1-58:normal:-:', false],
    'treatment doubled' => ['base1-58:normal:-:--', false],
    'upper-case card id' => ['BASE1-58:normal:-:-', false],
    'fullwidth colon' => ["base1-58\u{FF1A}normal:-:-", false],
    'trailing space' => ['base1-58:normal:-:- ', false],
    'empty key' => ['', false],
])->group('DC3');

test('deleting a card_variants or map_builds row that a mapping or history row references is refused and loses nothing', function (): void {
    $ids = edgeSeed();
    DB::table('source_product_map')->insert(edgeRow('source_product_map', $ids));
    DB::table('source_product_map_history')->insert(edgeRow('source_product_map_history', $ids));

    $deleteVariant = edgeError(fn () => DB::table('card_variants')->where('variant_key', $ids['@variant'])->delete());
    $deleteBuild = edgeError(fn () => DB::table('map_builds')->where('id', $ids['@build'])->delete());

    expect($deleteVariant)->toBeInstanceOf(QueryException::class)
        ->and($deleteVariant->getCode())->toBe('23503')
        ->and($deleteBuild)->toBeInstanceOf(QueryException::class)
        ->and($deleteBuild->getCode())->toBe('23503')
        ->and(DB::table('source_product_map')->count())->toBe(1)
        ->and(DB::table('source_product_map_history')->count())->toBe(1)
        ->and(DB::table('card_variants')->count())->toBe(2);
});

test('an upsert that conflicts on source_product_map_history cannot rewrite the ended row', function (): void {
    $ids = edgeSeed();
    DB::table('source_product_map_history')->insert(edgeRow('source_product_map_history', $ids));

    $error = edgeError(fn () => DB::table('source_product_map_history')->upsert(
        [edgeRow('source_product_map_history', $ids, ['end_reason' => 'rewritten'])],
        ['variant_key', 'source', 'series_epoch'],
        ['end_reason'],
    ));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23001')
        ->and(DB::table('source_product_map_history')->value('end_reason'))->toBe('remapped');
});

test('the history table keeps successive epochs and repeated products, which the current map forbids', function (): void {
    $ids = edgeSeed();
    $rows = [
        edgeRow('source_product_map_history', $ids, ['series_epoch' => 1]),
        edgeRow('source_product_map_history', $ids, ['series_epoch' => 2, 'end_reason' => 'remapped again']),
        edgeRow('source_product_map_history', $ids, ['variant_key' => '@variant2']),
    ];
    DB::table('source_product_map_history')->insert($rows);

    $sameEpochAgain = edgeError(fn () => DB::table('source_product_map_history')->insert(
        edgeRow('source_product_map_history', $ids, ['source_product_id' => 5, 'end_reason' => 'duplicate epoch']),
    ));

    expect(DB::table('source_product_map_history')->count())->toBe(3)
        ->and($sameEpochAgain)->toBeInstanceOf(QueryException::class)
        ->and($sameEpochAgain->getCode())->toBe('23505');
});

test('unmapped items repeat across builds and subtypes but not within one build', function (): void {
    $ids = edgeSeed();
    $second = DB::table('map_builds')->insertGetId(edgeRow('map_builds', $ids));
    DB::table('unmapped_source_items')->insert([
        edgeRow('unmapped_source_items', $ids),
        edgeRow('unmapped_source_items', $ids, ['map_build_id' => $second]),
        edgeRow('unmapped_source_items', $ids, ['source_subtype' => 'Holofoil']),
        edgeRow('unmapped_source_items', $ids, ['source_subtype' => '']),
    ]);

    $dupEmpty = edgeError(fn () => DB::table('unmapped_source_items')->insert(
        edgeRow('unmapped_source_items', $ids, ['source_subtype' => '', 'reason' => 'ambiguous']),
    ));

    expect(DB::table('unmapped_source_items')->count())->toBe(4)
        ->and($dupEmpty)->toBeInstanceOf(QueryException::class)
        ->and($dupEmpty->getCode())->toBe('23505');
});

test('NOT NULL columns reject null (name_agreement, source_subtype of the map, counts, role)', function (string $table, string $column): void {
    $error = edgeInsert($table, [$column => null]);

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and($error->getCode())->toBe('23502')
        ->and($error->getMessage())->toContain("\"{$column}\"");
})->with([
    'map name_agreement' => ['source_product_map', 'name_agreement'],
    'map source_subtype' => ['source_product_map', 'source_subtype'],
    'map confidence' => ['source_product_map', 'confidence'],
    'unmapped source_subtype' => ['unmapped_source_items', 'source_subtype'],
    'map_builds counts' => ['map_builds', 'counts'],
    'set_aliases role' => ['set_aliases', 'role'],
    'set_aliases note' => ['set_aliases', 'note'],
    'history end_reason' => ['source_product_map_history', 'end_reason'],
    'history ended_at' => ['source_product_map_history', 'ended_at'],
]);

test('set_aliases boundaries: negative and empty-string values are refused, one set may have several groups', function (): void {
    $ids = edgeSeed();
    $negative = edgeError(fn () => DB::table('set_aliases')->insert(edgeRow('set_aliases', $ids, ['source_group_id' => -604])));
    $emptyMeans = edgeError(fn () => DB::table('set_aliases')->insert(edgeRow('set_aliases', $ids, ['unlimited_means' => ''])));
    DB::table('set_aliases')->insert([
        edgeRow('set_aliases', $ids, ['source_group_id' => 1]),
        edgeRow('set_aliases', $ids, ['source_group_id' => 2147483647, 'role' => 'secondary', 'unlimited_means' => null]),
    ]);

    expect($negative?->getMessage())->toContain('set_aliases_source_group_id_check')
        ->and($emptyMeans?->getMessage())->toContain('set_aliases_unlimited_means_check')
        ->and(DB::table('set_aliases')->count())->toBe(2);
});

test('confidence is bounded after numeric(4,3) rounding: 1.0004 rounds into range, 1.0005 does not, 10 overflows', function (): void {
    $ids = edgeSeed();
    $rounds = edgeError(fn () => DB::table('source_product_map')->insert(edgeRow('source_product_map', $ids, ['confidence' => '1.0004'])));
    $over = edgeError(fn () => DB::table('source_product_map')->insert(
        edgeRow('source_product_map', $ids, ['confidence' => '1.0005', 'source_product_id' => 7, 'variant_key' => '@variant2']),
    ));
    $overflow = edgeError(fn () => DB::table('source_product_map')->insert(
        edgeRow('source_product_map', $ids, ['confidence' => '10', 'source_product_id' => 8, 'variant_key' => '@variant2']),
    ));

    expect($rounds)->toBeNull()
        ->and($over?->getCode())->toBe('23514')
        ->and($overflow?->getCode())->toBe('22003')
        ->and(DB::table('source_product_map')->value('confidence'))->toBe('1.000');
});
