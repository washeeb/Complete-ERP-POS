<?php

use App\Http\Controllers\Api\V1\DeviceAuthController;
use App\Http\Controllers\Api\V1\PosController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('devices/enroll', [DeviceAuthController::class, 'enroll'])->middleware('throttle:device-enroll');

    Route::post('pos/login', [DeviceAuthController::class, 'pinLogin'])
        ->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-login']);

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
