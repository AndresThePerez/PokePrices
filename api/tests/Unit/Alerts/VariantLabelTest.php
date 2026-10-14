<?php

declare(strict_types=1);

use App\Domain\Alerts\VariantLabel;

// Plan P5-i1 T06 criterion 4 (D4): the server-side twin of the web's variantLabel (web/src/format.ts): finish,
// edition and treatment from the variant key, '-' and '' left out, joined by ', '; the key itself when none is left.

it('labels a variant the way the web does', function (string $key, string $label): void {
    expect(VariantLabel::of($key))->toBe($label);
})->with([
    'finish only' => ['base1-4:holo:-:-', 'holo'],
    'finish and edition' => ['ex10-?:holo:1st:-', 'holo, 1st'],
    'all three' => ['base1-4:reverse:1st:staff-stamp', 'reverse, 1st, staff-stamp'],
    'finish and treatment' => ['base1-4:normal:-:galaxy', 'normal, galaxy'],
    'empty parts are none too' => ['base1-4:holo::', 'holo'],
    'no finish, edition or treatment: the key' => ['base1-3:-:-:-', 'base1-3:-:-:-'],
    'all empty: the key' => ['base1-3:::', 'base1-3:::'],
    'no parts at all: the key' => ['base1-3', 'base1-3'],
]);
