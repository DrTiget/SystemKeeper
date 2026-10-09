<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ServerController;
use App\Http\Controllers\Api\V1\SshKeyController;
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
});
