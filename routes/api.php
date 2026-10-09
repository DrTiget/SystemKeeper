<?php

use App\Http\Controllers\Api\V1\AlertChannelController;
use App\Http\Controllers\Api\V1\AlertDeliveryController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BackupJobController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\PushController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServerMetricController;
use App\Http\Controllers\Api\V1\SshKeyController;
use App\Http\Controllers\Api\V1\StorageTargetController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('dashboard', DashboardController::class);

    Route::get('ssh-keys/{ssh_key}/public', [SshKeyController::class, 'publicKey']);
    Route::apiResource('ssh-keys', SshKeyController::class)->parameters(['ssh-keys' => 'ssh_key']);

    Route::post('servers/{server}/check', [ServerController::class, 'check']);
    Route::post('servers/{server}/reset-fingerprint', [ServerController::class, 'resetFingerprint']);
    Route::get('servers/{server}/metrics/latest', [ServerMetricController::class, 'latest']);
    Route::post('servers/{server}/metrics/collect', [ServerMetricController::class, 'collect']);
    Route::get('servers/{server}/metrics', [ServerMetricController::class, 'history']);
    Route::apiResource('servers', ServerController::class);

    Route::post('storage-targets/{storage_target}/check', [StorageTargetController::class, 'check']);
    Route::apiResource('storage-targets', StorageTargetController::class)->parameters(['storage-targets' => 'storage_target']);
});

// Публичный heartbeat: без авторизации, доступ по токену монитора
Route::match(['get', 'post'], 'heartbeat/{token}', [HeartbeatController::class, 'ping'])->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('monitors/{monitor}/pause', [MonitorController::class, 'pause']);
    Route::post('monitors/{monitor}/resume', [MonitorController::class, 'resume']);
    Route::get('monitors/{monitor}/checks', [MonitorController::class, 'checks']);
    Route::get('monitors/{monitor}/incidents', [MonitorController::class, 'incidents']);
    Route::get('monitors/{monitor}/uptime', [MonitorController::class, 'uptime']);
    Route::apiResource('monitors', MonitorController::class);

    Route::apiResource('incidents', IncidentController::class)->only(['index', 'show']);

    Route::post('backup-jobs/{backup_job}/run', [BackupJobController::class, 'run']);
    Route::post('backup-jobs/{backup_job}/rotate', [BackupJobController::class, 'rotate']);
    Route::apiResource('backup-jobs', BackupJobController::class)->parameters(['backup-jobs' => 'backup_job']);

    Route::get('backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');
    Route::post('backups/{backup}/download-link', [BackupController::class, 'downloadLink']);
    Route::post('backups/{backup}/verify-restore', [BackupController::class, 'verifyRestore']);
    Route::apiResource('backups', BackupController::class)->only(['index', 'show', 'destroy']);
});

// Скачивание бекапа по временной подписанной ссылке: без Bearer, подлинность — в подписи (см. POST backups/{id}/download-link)
Route::get('backups/{backup}/file', [BackupController::class, 'file'])
    ->middleware(['signed:relative', 'throttle:60,1'])
    ->name('backups.file');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('alert-channels/{alert_channel}/test', [AlertChannelController::class, 'test']);
    Route::apiResource('alert-channels', AlertChannelController::class)->parameters(['alert-channels' => 'alert_channel']);
    Route::apiResource('alert-rules', AlertRuleController::class)->parameters(['alert-rules' => 'alert_rule']);
    Route::get('alert-deliveries', [AlertDeliveryController::class, 'index']);

    Route::get('push/vapid-public-key', [PushController::class, 'vapidPublicKey']);
    Route::post('push-subscriptions', [PushController::class, 'subscribe']);
    Route::delete('push-subscriptions', [PushController::class, 'unsubscribe']);
    Route::post('device-tokens', [PushController::class, 'registerDevice']);
    Route::delete('device-tokens', [PushController::class, 'unregisterDevice']);
});
