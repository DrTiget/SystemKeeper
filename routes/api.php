<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProfileController;
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
