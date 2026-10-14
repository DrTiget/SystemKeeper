<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\BackupStorageController;
use App\Http\Controllers\Api\V1\BackupTemplateController;
use App\Http\Controllers\Api\V1\DatabaseConnectionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ProjectGroupController;
use App\Http\Controllers\Api\V1\ServerBackupSettingsController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\ServerFilesController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

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
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('project-groups', ProjectGroupController::class);
    Route::apiResource('projects', ProjectController::class);
    Route::get('projects/{project}/backups', [BackupController::class, 'projectIndex']);

    Route::post('servers/{server}/check', [ServerController::class, 'check']);
    Route::post('servers/{server}/reset-fingerprint', [ServerController::class, 'resetFingerprint']);
    Route::get('servers/{server}/files', [ServerFilesController::class, 'index'])->middleware('throttle:120,1');
    Route::get('backup/pre-command-templates', [BackupTemplateController::class, 'preCommands']);
    Route::get('servers/{server}/backup-settings', [ServerBackupSettingsController::class, 'show']);
    Route::match(['put', 'patch'], 'servers/{server}/backup-settings', [ServerBackupSettingsController::class, 'update']);
    Route::apiResource('servers', ServerController::class);

    Route::post('databases/{database}/provision-user', [DatabaseConnectionController::class, 'provisionUser']);
    Route::post('databases/{database}/check', [DatabaseConnectionController::class, 'check']);
    Route::get('databases/{database}/schemas', [DatabaseConnectionController::class, 'schemas'])->middleware('throttle:120,1');
    Route::get('databases/{database}/tables', [DatabaseConnectionController::class, 'tables'])->middleware('throttle:120,1');
    Route::apiResource('databases', DatabaseConnectionController::class);

    Route::get('servers/{server}/backups', [BackupController::class, 'serverIndex']);
    Route::post('servers/{server}/backups', [BackupController::class, 'serverRun']);
    Route::get('databases/{database}/backups', [BackupController::class, 'databaseIndex']);
    Route::post('databases/{database}/backups', [BackupController::class, 'databaseRun']);
    Route::post('storages/{storage}/check', [BackupStorageController::class, 'check']);
    Route::apiResource('storages', BackupStorageController::class);

    Route::get('backups/{backup}', [BackupController::class, 'show']);
    Route::delete('backups/{backup}', [BackupController::class, 'destroy']);
    Route::get('backups/{backup}/files/{file}', [BackupController::class, 'download'])->where('file', '[^/]+');

    Route::get('system/version', [SystemController::class, 'version']);
    Route::post('system/check', [SystemController::class, 'check'])->middleware('throttle:10,1');
    Route::post('system/update', [SystemController::class, 'update']);
});
