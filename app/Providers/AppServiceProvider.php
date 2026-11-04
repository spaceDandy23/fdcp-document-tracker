<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

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
        Socialite::extend('fdcp_accounts', function () {
            return Socialite::buildProvider(
                FdcpAccountsProvider::class,
                config('services.fdcp_accounts'),
            );
        });
    }
}
