<?php

use App\Http\Controllers\VendorPrintController;
use Illuminate\Support\Facades\Route;

// Licence server only (loaded by VendorPanelProvider; left out of client builds).
Route::middleware(['web', 'auth'])->prefix('vendor-print')->name('vendor.')->group(function () {
    Route::get('licenses.csv', [VendorPrintController::class, 'csv'])->name('licenses.csv');
    Route::get('license/{license}', [VendorPrintController::class, 'certificate'])->name('licenses.certificate')->whereUuid('license');
    Route::get('invoice/{invoice}', [VendorPrintController::class, 'invoice'])->name('invoices.print')->whereUuid('invoice');
});
