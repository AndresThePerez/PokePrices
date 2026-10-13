<?php

declare(strict_types=1);

use App\Domain\Accounts\VariantKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountRows;
use Tests\Support\Accounts;
use Tests\Support\CatalogCache;

// Plan P4-i1 T05 criterion 8 (D5, review F-P4-05): VariantKey::isValid mirrors the card_variants checks, and every
// {variantKey} route runs it before any query: PUT answers 422 errors.variant_key, DELETE 204, never 500. (A path of
// invalid UTF-8 never reaches a route: Laravel compiles every route with the UTF-8 flag and answers it 400 first, as
// for any /v1 path; VariantKey::isValid still refuses such a key.)

beforeEach(function (): void {
    Accounts::setUp();
    $this->prefix = AccountRows::fakePokeSearch();
});

afterEach(function (): void {
    CatalogCache::flush($this->prefix);
});

it('accepts keys card_variants accepts', function (string $key): void {
    expect(VariantKey::isValid($key))->toBeTrue();
})->with([
    'base1-4:holo:unlimited:-',
    'base1-4:holo:1st:-',
    'ex5-17:reverse:-:-',
    'ex10-?:holo:-:-',
    'sv1-1!:normal:shadowless:-',
    'swsh9-1:holo:-:cosmos-holo',
    'a.b_c-d:normal:-:x1',
    'max length card id' => [str_repeat('a', 128).':holo:-:-'],
]);

it('refuses keys card_variants would refuse, and oversized or malformed bytes', function (string $key): void {
    expect(VariantKey::isValid($key))->toBeFalse();
})->with([
    'NUL byte' => ["a\0b:holo:-:-"],
    'invalid UTF-8' => ["\xFF:holo:-:-"],
    '5000 characters' => [str_repeat('a', 5000).':holo:-:-'],
    'over 300 bytes' => ['a:holo:-:'.str_repeat('a', 300)],
    'a slash' => ['ex10/1:holo:-:-'],
    'empty' => [''],
    'card id starts with a dash' => ['-base1:holo:-:-'],
    'card id of 129 characters' => [str_repeat('a', 129).':holo:-:-'],
    'card id with a space' => ['base 1:holo:-:-'],
    'card id with é' => ['bé-1:holo:-:-'],
    'unknown finish' => ['base1-4:foil:-:-'],
    'unknown edition' => ['base1-4:holo:2nd:-'],
    'upper-case treatment' => ['base1-4:holo:-:Cosmos'],
    'treatment with a double dash' => ['base1-4:holo:-:cosmos--holo'],
    'treatment ending in a dash' => ['base1-4:holo:-:cosmos-'],
    'three segments' => ['base1-4:holo:-'],
    'five segments' => ['base1-4:holo:-:-:-'],
    'trailing newline' => ["base1-4:holo:-:-\n"],
    'empty card id' => [':holo:-:-'],
]);

it('answers PUT with an invalid key with 422 errors.variant_key and DELETE with 204, with no database query', function (string $path): void {
    $user = Accounts::user();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (! preg_match('/\bfrom\s+"?users"?\b/i', $query->sql)) {
            $queries[] = $query->sql;
        }
    });

    $responses = [];
    foreach (['watchlist', 'portfolio'] as $list) {
        $put = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->putJson("/v1/{$list}/{$path}", ['quantity' => 1]);
        $delete = AccountRows::signIn($this, $user)->withHeaders(Accounts::FIRST_PARTY)->deleteJson("/v1/{$list}/{$path}");
        $responses[] = [$put->getStatusCode(), $put->json('errors'), $delete->getStatusCode()];
    }

    $refused = [422, ['variant_key' => ['PokéPrices has no variant with this key.']], 204];
    expect($responses)->toBe([$refused, $refused])
        ->and($queries)->toBe([]);
})->with([
    'NUL byte' => ['a%00b:holo:-:-'],
    '5000 characters' => [str_repeat('a', 5000).':holo:-:-'],
    'encoded slash' => ['ex10%2F1:holo:-:-'],
    'unknown finish' => ['base1-4:foil:-:-'],
    'unknown edition' => ['base1-4:holo:2nd:-'],
    'bad treatment' => ['base1-4:holo:-:Cosmos'],
    'three segments' => ['base1-4:holo:-'],
    'trailing newline' => ['base1-4:holo:-:-%0A'],
    'card id starts with a dash' => ['-base1:holo:-:-'],
]);
