<?php

use App\Http\Controllers\Api\V1\AlertChannelController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BackupHookController;
use App\Http\Controllers\Api\V1\BackupStorageController;
use App\Http\Controllers\Api\V1\BackupTemplateController;
use App\Http\Controllers\Api\V1\DatabaseConnectionController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectGroupController;
use App\Http\Controllers\Api\V1\ServerBackupSettingsController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServerFilesController;
use App\Http\Controllers\Api\V1\ServerMetricsController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// Временная подписанная ссылка на файл бекапа — для облаков, которые сами скачивают его (Яндекс Диск)
Route::get('transfers/{storage}/{path}', [TransferController::class, 'show'])->where('path', '.*')->middleware(['signed:relative', 'throttle:60,1'])->name('transfers.show');

// Ссылка-хук: запуск бекапа без входа, по секрету в адресе (из CI/CD)
Route::post('hooks/{token}', [BackupHookController::class, 'trigger'])->middleware('throttle:30,1');
Route::get('hooks/{token}/backups/{backup}', [BackupHookController::class, 'backup'])->whereNumber('backup')->middleware('throttle:120,1');

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->prefix('profile')->group(function () {
    Route::get('/', [ProfileController::class, 'show']);
    Route::match(['put', 'patch'], '/', [ProfileController::class, 'update']);
    Route::put('password', [ProfileController::class, 'password']);
    Route::get('tokens', [ProfileController::class, 'tokens']);
    Route::delete('tokens', [ProfileController::class, 'destroyOtherTokens']);
    Route::delete('tokens/{token}', [ProfileController::class, 'destroyToken'])->whereNumber('token');
});

Route::middleware(['auth:sanctum', 'can:manage-users'])->group(function () {
    Route::apiResource('users', UserController::class);

    Route::get('settings/notifications', [NotificationSettingsController::class, 'show']);
    Route::match(['put', 'patch'], 'settings/notifications', [NotificationSettingsController::class, 'update']);
    Route::post('settings/notifications/test-mail', [NotificationSettingsController::class, 'testMail'])->middleware('throttle:10,1');
    Route::post('settings/notifications/test-telegram', [NotificationSettingsController::class, 'testTelegram'])->middleware('throttle:10,1');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('project-groups', ProjectGroupController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::get('projects/{project}/backups', [BackupController::class, 'projectIndex']);

    Route::post('monitors/test', [MonitorController::class, 'test'])->middleware('throttle:30,1');
    Route::post('monitors/{monitor}/check', [MonitorController::class, 'check'])->middleware('throttle:60,1');
    Route::get('monitors/{monitor}/heartbeats', [MonitorController::class, 'heartbeats']);
    Route::delete('monitors/{monitor}/heartbeats', [MonitorController::class, 'clearHeartbeats']);
    Route::delete('monitors/{monitor}/heartbeats/{heartbeat}', [MonitorController::class, 'destroyHeartbeat'])->whereNumber('heartbeat');
    Route::delete('monitors/{monitor}/incidents/{incident}', [MonitorController::class, 'destroyIncident'])->whereNumber('incident');
    Route::get('monitors/{monitor}/incidents', [MonitorController::class, 'incidents']);
    Route::apiResource('monitors', MonitorController::class);

    Route::post('servers/{server}/check', [ServerController::class, 'check']);
    Route::post('servers/{server}/reset-fingerprint', [ServerController::class, 'resetFingerprint']);
    Route::get('servers/{server}/files', [ServerFilesController::class, 'index'])->middleware('throttle:120,1');
    Route::post('servers/{server}/discover', [ServerFilesController::class, 'discover'])->middleware('throttle:10,1');
    Route::get('backup/pre-command-templates', [BackupTemplateController::class, 'preCommands']);
    Route::get('servers/{server}/backup-settings', [ServerBackupSettingsController::class, 'show']);
    Route::match(['put', 'patch'], 'servers/{server}/backup-settings', [ServerBackupSettingsController::class, 'update']);
    Route::get('metrics/servers', [ServerMetricsController::class, 'index']);
    Route::get('servers/{server}/metrics', [ServerMetricsController::class, 'show']);
    Route::get('servers/{server}/metrics/history', [ServerMetricsController::class, 'history']);
    Route::match(['put', 'patch'], 'servers/{server}/metrics/settings', [ServerMetricsController::class, 'update']);
    Route::apiResource('servers', ServerController::class);

    Route::post('databases/{database}/provision-user', [DatabaseConnectionController::class, 'provisionUser']);
    Route::post('databases/{database}/check', [DatabaseConnectionController::class, 'check']);
    Route::get('databases/{database}/schemas', [DatabaseConnectionController::class, 'schemas'])->middleware('throttle:120,1');
    Route::get('databases/{database}/tables', [DatabaseConnectionController::class, 'tables'])->middleware('throttle:120,1');
    Route::apiResource('databases', DatabaseConnectionController::class);

    Route::get('servers/{server}/backups', [BackupController::class, 'serverIndex']);
    Route::post('servers/{server}/backups', [BackupController::class, 'serverRun']);
    Route::get('servers/{server}/backup-hook', [BackupHookController::class, 'serverShow']);
    Route::post('servers/{server}/backup-hook', [BackupHookController::class, 'serverStore']);
    Route::delete('servers/{server}/backup-hook', [BackupHookController::class, 'serverDestroy']);
    Route::get('databases/{database}/backups', [BackupController::class, 'databaseIndex']);
    Route::post('databases/{database}/backups', [BackupController::class, 'databaseRun']);
    Route::get('databases/{database}/backup-hook', [BackupHookController::class, 'databaseShow']);
    Route::post('databases/{database}/backup-hook', [BackupHookController::class, 'databaseStore']);
    Route::delete('databases/{database}/backup-hook', [BackupHookController::class, 'databaseDestroy']);
    Route::post('storages/{storage}/check', [BackupStorageController::class, 'check']);
    Route::get('storages/{storage}/sync', [BackupStorageController::class, 'syncShow']);
    Route::post('storages/{storage}/sync', [BackupStorageController::class, 'sync']);
    Route::apiResource('storages', BackupStorageController::class);

    Route::get('backups/latest', [BackupController::class, 'latest']);
    Route::get('backups/{backup}', [BackupController::class, 'show']);
    Route::delete('backups/{backup}', [BackupController::class, 'destroy']);
    Route::get('backups/{backup}/files/{file}', [BackupController::class, 'download'])->where('file', '[^/]+');

    Route::post('alert-channels/{alert_channel}/test', [AlertChannelController::class, 'test'])->middleware('throttle:20,1');
    Route::apiResource('alert-channels', AlertChannelController::class);
    Route::apiResource('alert-rules', AlertRuleController::class);
    Route::get('alerts/summary', [AlertController::class, 'summary']);
    Route::get('alerts', [AlertController::class, 'index']);
    Route::post('alerts/{alert}/ack', [AlertController::class, 'ack'])->whereNumber('alert');

    Route::get('system/version', [SystemController::class, 'version']);
    Route::get('system/changelog', [SystemController::class, 'changelog']);
    Route::post('system/check', [SystemController::class, 'check'])->middleware('throttle:10,1');
    Route::post('system/update', [SystemController::class, 'update']);
});
