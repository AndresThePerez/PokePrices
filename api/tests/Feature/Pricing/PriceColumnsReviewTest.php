<?php

declare(strict_types=1);

use App\Domain\Accounts\VariantRows;
use App\Domain\Pricing\PriceColumns;
use App\Http\Resources\PriceBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AccountRows;

// Reviewer tests for Q1-i4 T04: PriceBlock::COLUMNS became PriceColumns::LATEST. VariantRows selects each entry as
// price_<column> and PriceBlock::fromRow() reads them back by name, so the list must stay the same set of real
// price_latest columns.

it('keeps the price_latest column list exactly as it was and every entry is a real price_latest column', function (): void {
    expect(PriceColumns::LATEST)->toBe(['variant_key', 'source', 'series_epoch', 'close', 'day', 'as_of', 'previous_close', 'previous_day', 'change', 'change_pct'])
        ->and(array_diff(PriceColumns::LATEST, Schema::getColumnListing('price_latest')))->toBe([])
        ->and(count(array_unique(PriceColumns::LATEST)))->toBe(count(PriceColumns::LATEST));
});

it('maps every joined price_latest value to its own block field', function (): void {
    AccountRows::price('rev-9:holo:unlimited:-', '968.91', '2026-10-12', '950.00', '2026-10-10', '18.91', '1.99');
    $select = VariantRows::selectAndJoin('w');
    preg_match_all('/p\.(\w+) AS price_(\w+)/', $select, $aliases, PREG_SET_ORDER);
    $row = DB::selectOne(
        "SELECT {$select} FROM (SELECT 'rev-9:holo:unlimited:-'::text AS variant_key) w ".VariantRows::joins('w'),
        ['tcgplayer'],
    );

    expect(array_map(fn (array $m): string => $m[1], $aliases))->toBe(PriceColumns::LATEST)
        ->and(array_map(fn (array $m): string => $m[2], $aliases))->toBe(PriceColumns::LATEST)
        ->and(PriceBlock::fromRow(get_object_vars($row)))->toBe([
            'source' => 'tcgplayer', 'feed' => 'tcgcsv', 'basis' => 'market', 'currency' => 'USD', 'cadence' => 'daily',
            'close' => '968.91', 'day' => '2026-10-12', 'as_of' => '2026-10-12T20:05:45Z', 'previous_close' => '950.00',
            'previous_day' => '2026-10-10', 'change' => '18.91', 'change_pct' => '1.99', 'series_epoch' => 1,
        ]);
});

it('builds no block from a joined row that found no close', function (): void {
    AccountRows::variant('rev-9:holo:1st:-');
    $select = VariantRows::selectAndJoin('w');
    $row = DB::selectOne(
        "SELECT {$select} FROM (SELECT 'rev-9:holo:1st:-'::text AS variant_key) w ".VariantRows::joins('w'),
        ['tcgplayer'],
    );

    expect(PriceBlock::fromRow(get_object_vars($row)))->toBeNull();
});
