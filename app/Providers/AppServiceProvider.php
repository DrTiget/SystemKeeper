<?php

namespace App\Providers;

use App\Services\Backup\LocalCommandRunner;
use App\Services\Backup\ProcessCommandRunner;
use App\Services\Monitoring\NetworkProbe;
use App\Services\Monitoring\SystemNetworkProbe;
use App\Services\Ssh\SshConnector;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\StorageUploader;
use App\Services\Storage\StorageUploaderInterface;
use App\Support\StorageTargetOpenApi;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SshConnectorInterface::class, SshConnector::class);
        $this->app->bind(LocalCommandRunner::class, ProcessCommandRunner::class);
        $this->app->bind(NetworkProbe::class, SystemNetworkProbe::class);
        $this->app->bind(StorageUploaderInterface::class, StorageUploader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Документация OpenAPI: /api/docs и /api/docs.json (доступ — App\Http\Middleware\ApiDocsAccess)
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            })
            ->withDocumentTransformers(new StorageTargetOpenApi)
            ->expose(ui: 'api/docs', document: 'api/docs.json');
    }
}
