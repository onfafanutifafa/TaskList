<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // Horizon exposes queue internals, so the dashboard is locked down outside
        // local: only the emails in HORIZON_DASHBOARD_EMAILS (comma-separated) pass.
        Gate::define('viewHorizon', function ($user = null) {
            $allowed = array_filter(array_map('trim', explode(',', (string) env('HORIZON_DASHBOARD_EMAILS'))));

            return $user !== null && in_array($user->email, $allowed, true);
        });
    }
}
