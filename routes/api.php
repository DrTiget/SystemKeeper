<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BackupJobController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\MonitorController;
use App\Http\Controllers\Api\V1\ServerController;
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
    Route::get('ssh-keys/{ssh_key}/public', [SshKeyController::class, 'publicKey']);
    Route::apiResource('ssh-keys', SshKeyController::class)->parameters(['ssh-keys' => 'ssh_key']);

    Route::post('servers/{server}/check', [ServerController::class, 'check']);
    Route::post('servers/{server}/reset-fingerprint', [ServerController::class, 'resetFingerprint']);
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
    Route::apiResource('backups', BackupController::class)->only(['index', 'show', 'destroy']);
});
