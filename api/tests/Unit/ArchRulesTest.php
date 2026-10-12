<?php

declare(strict_types=1);

// Plain architecture rules (the plan's T24 arch test under the R153 override).

arch('catalog code is strict-typed classes in App\Domain\Catalog')
    ->expect('App\Domain\Catalog')
    ->toBeClasses()
    ->toUseStrictTypes();

test('the PokéSearch client, the id rule and the registrar exist in App\Domain\Catalog', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    'App\Domain\Catalog\CatalogRegistrar',
    'App\Domain\Catalog\PokeSearchClient',
    'App\Domain\Catalog\CardId',
]);

arch('catalog classes are final')
    ->expect('App\Domain\Catalog')
    ->toBeFinal();

arch('AppServiceProvider delegates catalog registration to CatalogRegistrar')
    ->expect('App\Providers\AppServiceProvider')
    ->toUse('App\Domain\Catalog\CatalogRegistrar');

arch('AppServiceProvider registers no Http macro of its own')
    ->expect('App\Providers\AppServiceProvider')
    ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client\Factory']);
