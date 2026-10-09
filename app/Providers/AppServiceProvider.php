<?php

namespace App\Providers;

use App\Events\BackupFailed;
use App\Events\BackupSucceeded;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Events\SslExpiring;
use App\Events\StorageCopyFailed;
use App\Services\Alerts\AlertEventListener;
use App\Services\Alerts\DisabledFcmDriver;
use App\Services\Alerts\FcmDriver;
use App\Services\Alerts\MinishlinkWebPushClient;
use App\Services\Alerts\WebPushClient;
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
use Illuminate\Support\Facades\Event;
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
        $this->app->bind(WebPushClient::class, MinishlinkWebPushClient::class);
        $this->app->bind(FcmDriver::class, DisabledFcmDriver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([
            IncidentOpened::class => 'incidentOpened',
            IncidentResolved::class => 'incidentResolved',
            BackupFailed::class => 'backupFailed',
            BackupSucceeded::class => 'backupSucceeded',
            StorageCopyFailed::class => 'storageCopyFailed',
            SslExpiring::class => 'sslExpiring',
        ] as $event => $method) {
            Event::listen($event, [AlertEventListener::class, $method]);
        }

        // Документация OpenAPI: /api/docs и /api/docs.json (доступ — App\Http\Middleware\ApiDocsAccess)
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            })
            ->withDocumentTransformers(new StorageTargetOpenApi)
            ->expose(ui: 'api/docs', document: 'api/docs.json');
    }
}
