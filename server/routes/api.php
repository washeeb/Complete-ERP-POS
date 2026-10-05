<?php

use App\Http\Controllers\Api\V1\DeviceAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('devices/enroll', [DeviceAuthController::class, 'enroll'])->middleware('throttle:device-enroll');

    Route::post('pos/login', [DeviceAuthController::class, 'pinLogin'])
        ->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-login']);

    Route::middleware(['auth:sanctum', 'device.token:pos'])->group(function () {
        Route::get('pos/me', [DeviceAuthController::class, 'me']);
        Route::post('pos/logout', [DeviceAuthController::class, 'logout']);
    });
});
