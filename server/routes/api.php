<?php

use App\Http\Controllers\Api\V1\DeviceAuthController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('devices/enroll', [DeviceAuthController::class, 'enroll'])->middleware('throttle:device-enroll');

    Route::post('pos/login', [DeviceAuthController::class, 'pinLogin'])
        ->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-login']);

    // Queued (offline) work; device token + device-key signature, people identified by their offline grants.
    Route::post('pos/sync', [PosController::class, 'sync'])->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-sync']);

    // Node-to-node sync (branch server ⇄ HQ). HMAC-signed with the peer's shared secret.
    Route::prefix('sync')->middleware('throttle:node-sync')->group(function () {
        Route::get('hello', [SyncController::class, 'hello']);
        Route::post('push', [SyncController::class, 'push']);
        Route::get('pull', [SyncController::class, 'pull']);
    });

    Route::middleware(['auth:sanctum', 'device.token:pos'])->prefix('pos')->group(function () {
        Route::get('me', [DeviceAuthController::class, 'me']);
        Route::post('logout', [DeviceAuthController::class, 'logout']);
        Route::get('bootstrap', [PosController::class, 'bootstrap']);
        Route::get('catalog', [PosController::class, 'catalog']);
        Route::post('shifts/open', [PosController::class, 'openShift']);
        Route::post('shifts/close', [PosController::class, 'closeShift']);
        Route::post('sales', [PosController::class, 'postSale']);
        Route::get('sales/{id}', [PosController::class, 'showSale'])->whereUuid('id');
    });
});
