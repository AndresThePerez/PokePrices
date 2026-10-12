<?php

namespace App\Providers;

use App\Domain\Catalog\CatalogRegistrar;
use App\OpenApi\ContractDocument;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // nginx routes no docs UI in P0 (D3 is out of scope), so Scramble registers no /docs routes.
        Scramble::ignoreDefaultRoutes();

        CatalogRegistrar::register();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Servers, contact, the readiness problem schema and closed objects (R78, .spectral.yaml).
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            (new ContractDocument)($openApi);
        });
    }
}
