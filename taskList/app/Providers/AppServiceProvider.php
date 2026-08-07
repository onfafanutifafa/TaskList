<?php

namespace App\Providers;

use App\Providers\MobileMoney\ProviderManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single instance so a test-installed fake provider is seen everywhere.
        $this->app->singleton(ProviderManager::class);
    }

    public function boot(): void
    {
        // A payment ledger must never silently drop an attribute or lazy-load in a loop.
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);
    }
}
