<?php

namespace App\Providers;

use App\Services\LaunchDarklyService;
use Illuminate\Support\ServiceProvider;

class LaunchDarklyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(LaunchDarklyService::class, function ($app) {
            $testMode = $app->environment('testing');
            return new LaunchDarklyService($testMode);
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}