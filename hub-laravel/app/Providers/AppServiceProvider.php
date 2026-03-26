<?php

namespace App\Providers;

use App\Services\WayMbService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(WayMbService::class, fn () => new WayMbService(
            url: config('services.waymb.url'),
            accountEmail: config('services.waymb.account_email'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
