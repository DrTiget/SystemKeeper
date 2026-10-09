<?php

namespace App\Providers;

use App\Services\Ssh\SshConnector;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SshConnectorInterface::class, SshConnector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
