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

// Plan P2-i1 D10 (T04): pricing and ingest code lives in App\Domain\Pricing, strict-typed and final, and reads
// collected files only: no HTTP client and no Elasticsearch client (prices are never written to Elasticsearch).
test('the pricing reader, normalizer, writer, rollup, day ingest, lock, digest and the ingest command exist', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    'App\Domain\Pricing\PriceSnapshotReader',
    'App\Domain\Pricing\PriceNormalizer',
    'App\Domain\Pricing\PricePointWriter',
    'App\Domain\Pricing\DailyRollup',
    'App\Domain\Pricing\DayIngest',
    'App\Domain\Pricing\IngestLock',
    'App\Domain\Pricing\PriceDigest',
    'App\Console\Commands\PricesIngest',
]);

arch('pricing code is strict-typed and final')
    ->expect(['App\Domain\Pricing', 'App\Console\Commands\PricesIngest'])
    ->toUseStrictTypes()
    ->toBeFinal();

arch('pricing code never uses the network or an Elasticsearch client')
    ->expect(['App\Domain\Pricing', 'App\Console\Commands\PricesIngest'])
    ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client', 'GuzzleHttp', 'Elastic', 'Elasticsearch']);

// Plan P4-i1 T02 criterion 14: account code (the invite check, the limiters, the alert seam, the reset-link
// answer) is strict-typed and final.
test('the invite check, the account limiters, the alert seam and the reset-link answer exist in App\Domain\Accounts', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    'App\Domain\Accounts\InviteCode',
    'App\Domain\Accounts\AccountRateLimits',
    'App\Domain\Accounts\AlertEligibility',
    'App\Domain\Accounts\ResetLinkAnswer',
]);

arch('account code is strict-typed, final classes in App\Domain\Accounts')
    ->expect('App\Domain\Accounts')
    ->toBeClasses()
    ->toUseStrictTypes()
    ->toBeFinal();

// Plan P5-i1 T01 criterion 11 (review A10.2): alert code is strict-typed, final classes in App\Domain\Alerts; the
// one interface T02 adds (HostResolver) is the exception.
test('the deliverable-day predicate, the payload, the models and the dispatch job exist in App\Domain\Alerts', function (string $class): void {
    expect(class_exists($class))->toBeTrue();
})->with([
    'App\Domain\Alerts\DeliverableDay',
    'App\Domain\Alerts\PriceMovedPayload',
    'App\Domain\Alerts\Models\WebhookEndpoint',
    'App\Domain\Alerts\Models\Delivery',
    'App\Domain\Alerts\Jobs\DispatchEvents',
    'App\Console\Commands\AlertsDispatch',
]);

arch('alert code is strict-typed, final classes in App\Domain\Alerts')
    ->expect('App\Domain\Alerts')
    ->toBeClasses()
    // ignoring() reaches only the expectation before it: GuardError is an enum, so not a class.
    ->ignoring('App\Domain\Alerts\GuardError')
    ->toUseStrictTypes()
    ->toBeFinal()
    ->ignoring(['App\Domain\Alerts\HostResolver', 'App\Domain\Alerts\GuardError']);
