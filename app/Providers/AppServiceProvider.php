<?php

namespace App\Providers;

use App\Services\Ssh\SshConnector;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\StorageUploader;
use App\Services\Storage\StorageUploaderInterface;
use App\Services\Monitoring\NetworkProbe;
use App\Services\Monitoring\SystemNetworkProbe;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SshConnectorInterface::class, SshConnector::class);
        $this->app->bind(NetworkProbe::class, SystemNetworkProbe::class);
        $this->app->bind(StorageUploaderInterface::class, StorageUploader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
