<?php

use App\Http\Controllers\Api\Public\PublicApiController;
use App\Http\Controllers\Api\V1\ChannelWebhookController;
use App\Http\Controllers\Api\V1\DeviceAuthController;
use App\Http\Controllers\Api\V1\ElectronicsController;
use App\Http\Controllers\Api\V1\FashionController;
use App\Http\Controllers\Api\V1\MomoController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\ServicesController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\VanController;
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

    // Mobile money provider callbacks (Paystack signed; Hubtel answered by a status check). The token names the company.
    Route::post('momo/webhook/{token}', [MomoController::class, 'webhook'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:webhooks')->name('momo.webhook');

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
        Route::post('drawer', [PosController::class, 'drawer']);
        Route::post('health', [PosController::class, 'health']);
        Route::post('commands/{id}', [PosController::class, 'commandDone'])->whereUuid('id');
        Route::post('sales', [PosController::class, 'postSale']);
        Route::post('momo', [MomoController::class, 'request'])->middleware('throttle:pos-sync'); // payment request to the customer's phone
        Route::get('momo/{id}', [MomoController::class, 'show'])->whereUuid('id');
        Route::post('momo/{id}/otp', [MomoController::class, 'otp'])->whereUuid('id')->middleware('throttle:pos-sync');
        Route::get('sales/{id}', [PosController::class, 'showSale'])->whereUuid('id');
        Route::post('sales/{id}/send', [PosController::class, 'sendReceipt'])->whereUuid('id')->middleware('throttle:pos-sync');
        Route::post('problems', [PosController::class, 'reportProblem'])->middleware('throttle:pos-sync');
        Route::get('receipts', [PosController::class, 'lookupSale']);
        Route::post('returns', [PosController::class, 'postReturn']);
        Route::post('account-payments', [PosController::class, 'accountPayment']);

        // Electronics: serial / warranty lookup and repair job cards.
        Route::get('serials/{serial}', [ElectronicsController::class, 'serial'])->where('serial', '[A-Za-z0-9 \-]{2,80}');
        Route::get('repairs', [ElectronicsController::class, 'index']);
        Route::post('repairs', [ElectronicsController::class, 'open']);
        Route::post('repairs/{id}/status', [ElectronicsController::class, 'status'])->whereUuid('id');
        Route::post('repairs/{id}/lines', [ElectronicsController::class, 'lines'])->whereUuid('id');
        Route::post('repairs/{id}/hand-over', [ElectronicsController::class, 'handOver'])->whereUuid('id');

        // Fashion: alteration jobs.
        Route::get('alterations', [FashionController::class, 'index']);
        Route::post('alterations', [FashionController::class, 'open']);
        Route::post('alterations/{id}/status', [FashionController::class, 'status'])->whereUuid('id');
        Route::post('alterations/{id}/hand-over', [FashionController::class, 'handOver'])->whereUuid('id');

        // Services: appointments by staff member and a customer's prepaid passes (the module must be on at the branch).
        Route::prefix('services')->group(function () {
            Route::get('appointments', [ServicesController::class, 'day']);
            Route::post('appointments', [ServicesController::class, 'book']);
            Route::post('appointments/{id}', [ServicesController::class, 'book'])->whereUuid('id');
            Route::post('appointments/{id}/status', [ServicesController::class, 'status'])->whereUuid('id');
            Route::get('passes', [ServicesController::class, 'passes']);
        });

        // Distribution: the van's day (route, target), visits and orders taken on the road.
        Route::prefix('van')->group(function () {
            Route::get('today', [VanController::class, 'show']);
            Route::post('visits', [VanController::class, 'visit']);
            Route::post('orders', [VanController::class, 'order']);
        });

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

// The licence server (answers only when this installation is the licence server).
Route::post('license/checkin', [LicenseServerController::class, 'checkin'])->middleware('throttle:30,1');

// Public API v1 for other systems (keys under System → API clients; description at /api/public/v1/openapi.json).
Route::prefix('public/v1')->group(function () {
    Route::get('openapi.json', [PublicApiController::class, 'openapi']);
    Route::middleware(['auth:sanctum', 'throttle:public-api'])->group(function () {
        Route::get('products', [PublicApiController::class, 'products'])->middleware('api.client:products:read');
        Route::get('products/{id}', [PublicApiController::class, 'product'])->whereUuid('id')->middleware('api.client:products:read');
        Route::get('stock', [PublicApiController::class, 'stock'])->middleware('api.client:stock:read');
        Route::get('customers', [PublicApiController::class, 'customers'])->middleware('api.client:customers:read');
        Route::post('customers', [PublicApiController::class, 'createCustomer'])->middleware('api.client:customers:write');
        Route::get('customers/{id}', [PublicApiController::class, 'customer'])->whereUuid('id')->middleware('api.client:customers:read');
        Route::get('sales', [PublicApiController::class, 'sales'])->middleware('api.client:sales:read');
        Route::get('invoices', [PublicApiController::class, 'invoices'])->middleware('api.client:invoices:read');
        Route::get('invoices/{id}', [PublicApiController::class, 'invoice'])->whereUuid('id')->middleware('api.client:invoices:read');
        Route::post('orders', [PublicApiController::class, 'createOrder'])->middleware('api.client:orders:write');
        Route::get('webhooks', [PublicApiController::class, 'webhooks'])->middleware('api.client:webhooks:manage');
        Route::post('webhooks', [PublicApiController::class, 'createWebhook'])->middleware('api.client:webhooks:manage');
        Route::delete('webhooks/{id}', [PublicApiController::class, 'deleteWebhook'])->whereUuid('id')->middleware('api.client:webhooks:manage');
    });
});
