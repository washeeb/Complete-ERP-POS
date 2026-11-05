<?php

use App\Http\Controllers\LicenseSyncController;
use App\Http\Middleware\VerifySyncSignature;
use Illuminate\Support\Facades\Route;

// The SES Licensing desktop app (loaded by the vendor panel, so only on the licence server, and left out of client builds).
Route::post('register', [LicenseSyncController::class, 'register'])->middleware('throttle:5,1');
Route::middleware(['throttle:120,1', VerifySyncSignature::class])->group(function () {
    Route::post('pull', [LicenseSyncController::class, 'pull']);
    Route::post('push', [LicenseSyncController::class, 'push']);
    Route::post('update', [LicenseSyncController::class, 'update']);
});
// The app's own updates: reached only through the short-lived links a signed request above hands out.
Route::middleware(['throttle:30,1', 'signed'])->group(function () {
    Route::get('update/manifest', [LicenseSyncController::class, 'updateManifest'])->name('license-sync.update.manifest');
    Route::get('update/download', [LicenseSyncController::class, 'updateDownload'])->name('license-sync.update.download');
});
