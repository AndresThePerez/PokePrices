<?php

declare(strict_types=1);

use App\Domain\Mapping\CardNumber;

// The card-number normaliser (plan T38 item 4, adversary rec 3). The same rule runs on the dataset's `number`
// and on the TCGplayer `Number` extended-data value, so both sides compare on one key.

mutates(CardNumber::class);

test('the printed total after the slash is dropped and leading zeros leave the numeric part', function (string $raw, string $key): void {
    expect(CardNumber::normalise($raw))->toBe($key);
})->with([
    '005/131' => ['005/131', '5'],
    '008/102' => ['008/102', '8'],
    '8' => ['8', '8'],
    '68/113' => ['68/113', '68'],
    '068/113' => ['068/113', '68'],
    '15/25' => ['15/25', '15'],
    '264/217' => ['264/217', '264'],
    'a lone zero stays' => ['0', '0'],
    'zeros only collapse to one' => ['000/030', '0'],
    'surrounding spaces' => [' 021/172 ', '21'],
]);

test('! and ? stay tokens and stay distinct (Unown 90166 and 90167)', function (): void {
    $products = [90166 => '!/28', 90167 => '?/28'];
    $keys = array_map(fn (string $n): string => CardNumber::normalise($n), $products);

    expect($keys)->toBe([90166 => '!', 90167 => '?']);
    expect(CardNumber::normalise('!'))->toBe($keys[90166]);
    expect(CardNumber::normalise('?'))->toBe($keys[90167]);
});

test('Unown letters are kept as their number', function (): void {
    expect(CardNumber::normalise('A/28'))->toBe('A');
    expect(CardNumber::normalise('Z/28'))->toBe('Z');
});

test('letter suffixes are kept', function (string $raw, string $key): void {
    expect(CardNumber::normalise($raw))->toBe($key);
})->with([
    '98a' => ['98a', '98a'],
    '98b' => ['98b', '98b'],
    '107a' => ['107a', '107a'],
    '098a/122' => ['098a/122', '98a'],
    '106p' => ['106p', '106p'],
    '11g' => ['11g', '11g'],
]);

test('98a, 98b and 98 are three different keys', function (): void {
    expect(array_unique([CardNumber::normalise('98'), CardNumber::normalise('98a'), CardNumber::normalise('98b')]))->toHaveCount(3);
});

test('RC, SV, TG and GG prefixes are kept; only the numeric part loses its zeros', function (string $raw, string $key): void {
    expect(CardNumber::normalise($raw))->toBe($key);
})->with([
    'RC5/RC32' => ['RC5/RC32', 'RC5'],
    'RC5' => ['RC5', 'RC5'],
    'SV001/SV094' => ['SV001/SV094', 'SV1'],
    'SV1' => ['SV1', 'SV1'],
    'TG01/TG30' => ['TG01/TG30', 'TG1'],
    'TG01' => ['TG01', 'TG1'],
    'GG01/GG70' => ['GG01/GG70', 'GG1'],
    'GG70' => ['GG70', 'GG70'],
    'RC10 keeps its inner zero' => ['RC10', 'RC10'],
]);

test('RC5 is not 5', function (): void {
    expect(CardNumber::normalise('RC5/RC32'))->not->toBe(CardNumber::normalise('005/032'));
});

test('RC, SV, TG and GG numbers route to the secondary group; plain numbers do not', function (string $raw, bool $secondary): void {
    expect(CardNumber::isSecondary($raw))->toBe($secondary);
})->with([
    'g1-RC5 product RC5/RC32' => ['RC5/RC32', true],
    'dataset RC1' => ['RC1', true],
    'SV001/SV094' => ['SV001/SV094', true],
    'TG01' => ['TG01', true],
    'GG01' => ['GG01', true],
    'lower-case rc5' => ['rc5', true],
    '005/131' => ['005/131', false],
    '98a' => ['98a', false],
    '!' => ['!/28', false],
    'SWSH001 is a promo prefix, not a secondary one' => ['SWSH001', false],
    'Mew R of me55' => ['R', false],
]);

test('_A1-style ids compare on number plus name', function (string $cardId, bool $needsName): void {
    expect(CardNumber::comparesOnName($cardId))->toBe($needsName);
})->with([
    'cel25c-15_A1' => ['cel25c-15_A1', true],
    'cel25c-15_A4' => ['cel25c-15_A4', true],
    'cel25c-17_A' => ['cel25c-17_A', true],
    'base1-4' => ['base1-4', false],
    'g1-RC5' => ['g1-RC5', false],
    'xy9-98a' => ['xy9-98a', false],
    'ex10-!' => ['ex10-!', false],
]);

test('the four cel25c number-15 cards share one number key, so the name decides', function (): void {
    $keys = array_map(fn (string $n): string => CardNumber::normalise($n), ['15', '15', '15', '15']);
    expect(array_unique($keys))->toBe(['15']);
    expect(CardNumber::normalise('15/25'))->toBe('15');
});
