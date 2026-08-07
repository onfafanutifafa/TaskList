<?php

namespace App\Providers;

use App\Providers\Crypto\CryptoProviderManager;
use App\Providers\MobileMoney\ProviderManager;
use App\Services\Fx\RateProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Single instance so a test-installed fake provider is seen everywhere.
        $this->app->singleton(ProviderManager::class);
        $this->app->singleton(CryptoProviderManager::class);
        $this->app->singleton(RateProviderManager::class);
    }

    public function boot(): void
    {
        // A payment ledger must never silently drop an attribute or lazy-load in a loop.
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);

        // Never emit plaintext links/credentials over http in production.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Rate limits. The merchant API is keyed per API key (falling back to IP);
        // provider/watcher webhooks are keyed per IP.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by(optional($request->attributes->get('api_key'))->id ?: $request->ip()));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }
}
