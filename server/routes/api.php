<?php

use App\Http\Controllers\Api\V1\ChannelWebhookController;
use App\Http\Controllers\Api\V1\DeviceAuthController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\WmsController;
use App\Http\Controllers\LicenseServerController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('devices/enroll', [DeviceAuthController::class, 'enroll'])->middleware('throttle:device-enroll');

    Route::post('pos/login', [DeviceAuthController::class, 'pinLogin'])
        ->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-login']);
    Route::post('pos/clock', [DeviceAuthController::class, 'clock'])->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-login']);

    // Queued (offline) work; device token + device-key signature, people identified by their offline grants.
    Route::post('pos/sync', [PosController::class, 'sync'])->middleware(['auth:sanctum', 'device.token:device', 'throttle:pos-sync']);

    // Store webhooks (WooCommerce, Shopify, generic REST): signature checked over the raw body, stored once, processed by the relay.
    Route::post('channels/{channel}/webhooks', ChannelWebhookController::class)->whereUuid('channel')->middleware('throttle:webhooks');

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
        Route::get('customers', [PosController::class, 'customers']);
        Route::post('shifts/open', [PosController::class, 'openShift']);
        Route::post('shifts/close', [PosController::class, 'closeShift']);
        Route::post('sales', [PosController::class, 'postSale']);
        Route::get('sales/{id}', [PosController::class, 'showSale'])->whereUuid('id');
        Route::get('receipts', [PosController::class, 'lookupSale']);
        Route::post('returns', [PosController::class, 'postReturn']);
        Route::post('account-payments', [PosController::class, 'accountPayment']);

        // Restaurant: bills shared by tills and waiter tablets (the module must be on at the branch).
        Route::prefix('restaurant')->group(function () {
            Route::get('floor', [RestaurantController::class, 'floor']);
            Route::post('orders', [RestaurantController::class, 'open']);
            Route::get('orders/{id}', [RestaurantController::class, 'show'])->whereUuid('id');
            Route::post('orders/{id}/items', [RestaurantController::class, 'addItems'])->whereUuid('id');
            Route::post('orders/{id}/fire', [RestaurantController::class, 'fire'])->whereUuid('id');
            Route::post('orders/{id}/void', [RestaurantController::class, 'void'])->whereUuid('id');
            Route::post('orders/{id}/serve', [RestaurantController::class, 'serve'])->whereUuid('id');
            Route::post('orders/{id}/move', [RestaurantController::class, 'move'])->whereUuid('id');
            Route::post('orders/{id}/close', [RestaurantController::class, 'close'])->whereUuid('id');
            Route::get('reservations', [RestaurantController::class, 'reservations']);
            Route::post('reservations', [RestaurantController::class, 'book']);
            Route::post('reservations/{id}', [RestaurantController::class, 'book'])->whereUuid('id');
            Route::post('reservations/{id}/deposit', [RestaurantController::class, 'deposit'])->whereUuid('id');
            Route::post('reservations/{id}/refund', [RestaurantController::class, 'refund'])->whereUuid('id');
            Route::post('reservations/{id}/seat', [RestaurantController::class, 'seat'])->whereUuid('id');
            Route::post('reservations/{id}/no-show', [RestaurantController::class, 'noShow'])->whereUuid('id');
            Route::post('reservations/{id}/cancel', [RestaurantController::class, 'cancelBooking'])->whereUuid('id');
            Route::post('waste', [RestaurantController::class, 'recordWaste']);
        });
    });

    // Kitchen screens: the enrolled kitchen device itself (no person signs in); polled every few seconds.
    Route::middleware(['auth:sanctum', 'device.token:device', 'throttle:kds'])->prefix('kds')->group(function () {
        Route::get('tickets', [RestaurantController::class, 'tickets']);
        Route::post('bump', [RestaurantController::class, 'bump']);
        Route::get('products', [RestaurantController::class, 'kdsProducts']);
        Route::post('waste', [RestaurantController::class, 'kdsWaste'])->middleware('throttle:pos-login'); // a PIN is checked
    });

    // Warehouse handheld (receive, put away, transfers, counts) — PIN sign-in on an enrolled handheld device.
    Route::middleware(['auth:sanctum', 'device.token:handheld', 'throttle:wms'])->prefix('wms')->group(function () {
        Route::get('bootstrap', [WmsController::class, 'bootstrap']);
        Route::post('logout', [WmsController::class, 'logout']);
        Route::get('lookup', [WmsController::class, 'lookup']);
        Route::get('purchase-orders', [WmsController::class, 'purchaseOrders']);
        Route::post('receipts', [WmsController::class, 'receive']);
        Route::get('bin-stock', [WmsController::class, 'binStock']);
        Route::post('putaway', [WmsController::class, 'putAway']);
        Route::get('transfers', [WmsController::class, 'transfers']);
        Route::post('transfers/{id}/receive', [WmsController::class, 'receiveTransfer'])->whereUuid('id');
        Route::get('counts', [WmsController::class, 'counts']);
        Route::post('counts', [WmsController::class, 'startCount']);
        Route::post('counts/{id}/lines', [WmsController::class, 'countLine'])->whereUuid('id');
        Route::post('counts/{id}/submit', [WmsController::class, 'submitCount'])->whereUuid('id');
    });
});

// Live updates for tills: private channel auth with the staff token (POST /api/v1/pos/broadcasting/auth).
Broadcast::routes(['prefix' => 'v1/pos', 'middleware' => ['auth:sanctum', 'device.token:pos']]);

// Synclytics licence server (answers only when this installation is the licence server).
Route::post('license/checkin', [LicenseServerController::class, 'checkin'])->middleware('throttle:30,1');
