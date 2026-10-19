<?php

namespace App\Providers;

use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\NetworkMonitorChecker;
use App\Services\Ssh\SshConnector;
use App\Services\Ssh\SshConnectorInterface;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SshConnectorInterface::class, SshConnector::class);
        $this->app->bind(MonitorChecker::class, NetworkMonitorChecker::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Источники бекапов — короткими именами в БД и в путях файлов
        Relation::morphMap(['server' => Server::class, 'database' => DatabaseConnection::class]);

        // Управление пользователями — только администраторам
        Gate::define('manage-users', fn (User $user) => $user->is_admin);

        // Документация OpenAPI: /api/docs и /api/docs.json (доступ — App\Http\Middleware\ApiDocsAccess)
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                // Bearer: токен пользователя (Sanctum) или приложения; X-Api-Key — альтернатива для токена приложения
                $openApi->secure(SecurityScheme::http('bearer')->setDescription('Токен пользователя из POST /auth/login или токен приложения (ska_…).'));
                $openApi->secure(SecurityScheme::apiKey('header', 'X-Api-Key')->setDescription('Токен приложения (ska_…).'));
            })
            ->expose(ui: 'api/docs', document: 'api/docs.json');
    }
}
