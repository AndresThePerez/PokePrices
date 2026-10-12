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

test('the map builder, the snapshot reader and the map:build command exist', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    'App\Domain\Mapping\MapBuilder',
    'App\Domain\Mapping\SnapshotReader',
    'App\Console\Commands\MapBuild',
]);

arch('mapping code is strict-typed and final')
    ->expect(['App\Domain\Mapping', 'App\Console\Commands\MapBuild'])
    ->toUseStrictTypes()
    ->toBeFinal();

arch('the product map is built from collected files only, never from the network')
    ->expect(['App\Domain\Mapping', 'App\Console\Commands\MapBuild'])
    ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client', 'GuzzleHttp']);
