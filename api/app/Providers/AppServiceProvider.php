<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Server;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The contract's only server is the relative /v1, so the export never depends on APP_URL (R78).
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->servers = [new Server('/v1')];
        });
    }
}
