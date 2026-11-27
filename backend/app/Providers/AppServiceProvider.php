<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Ontwikkeling en tests: lazy loading (N+1-queries) wordt een fout, zodat het direct opvalt.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Ondertekende links (facturen) moeten altijd op het echte domein uitkomen, ook achter een proxy.
        if ($this->app->isProduction() && config('app.url')) {
            URL::forceRootUrl(config('app.url'));
        }
    }
}
