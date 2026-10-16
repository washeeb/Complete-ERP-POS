<?php

use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\StorefrontController;
use App\Security\Passkeys\PasskeyLoginController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController;

Route::get('/', fn () => redirect('/admin'));

// The till app (built into public/pos by `npm run build:server` in pos/). Its files are served directly by the web server;
// these routes only catch the bare address and a till app that has not been built yet.
// A company's logo, icon or monogram (Company → Branding), public: it prints on receipts and shows in the online shop.
Route::get('/brand/{slug}/{kind}', BrandAssetController::class)->where(['slug' => '[a-z0-9-]+', 'kind' => 'logo|logo_dark|icon|monogram\.svg'])->name('brand.asset');

Route::get('/pos', fn () => redirect('/pos/'));
Route::get('/pos/{path?}', function () {
    $index = public_path('pos/index.html');

    return is_file($index) ? response()->file($index, ['Cache-Control' => 'no-cache'])
        : response('The till app is not installed on this server yet. In the pos folder run: npm run build:server', 404);
})->where('path', '.*');

// Passkey sign-in for the back office (package routes, with our hardened login controller).
Route::prefix('passkeys')->middleware('throttle:10,1')->group(function () {
    Route::get('authentication-options', GeneratePasskeyAuthenticationOptionsController::class)->name('passkeys.authentication_options');
    Route::post('authenticate', PasskeyLoginController::class)->name('passkeys.login');
});

// Built-in online shop (one per storefront channel; 404 unless the channel is active).
Route::prefix('shop/{slug}')->where(['slug' => '[a-z0-9-]+'])->group(function () {
    Route::get('/', [StorefrontController::class, 'index'])->name('shop.index');
    Route::post('cart', [StorefrontController::class, 'add'])->name('shop.add')->middleware('throttle:60,1');
    Route::get('cart', [StorefrontController::class, 'cartView'])->name('shop.cart');
    Route::post('cart/update', [StorefrontController::class, 'update'])->name('shop.update')->middleware('throttle:60,1');
    Route::post('checkout', [StorefrontController::class, 'checkout'])->name('shop.checkout')->middleware('throttle:10,1');
    Route::get('order/{order}', [StorefrontController::class, 'order'])->name('shop.order')->whereUuid('order')->middleware('signed');
});

// A4 documents (print or save as PDF from the browser). Signed-in back-office users only; permission-checked per document.
Route::prefix('print')->middleware(['auth', 'license:output'])->name('print.')->group(function () {
    Route::get('invoice/{invoice}', [PrintController::class, 'invoice'])->name('invoice')->whereUuid('invoice');
    Route::get('receipt/{sale}', [PrintController::class, 'receipt'])->name('receipt')->whereUuid('sale'); // a till sale's receipt, marked COPY
    Route::get('order/{order}', [PrintController::class, 'order'])->name('order')->whereUuid('order');
    Route::get('statement/{customer}', [PrintController::class, 'statement'])->name('statement')->whereUuid('customer');
    Route::get('payment/{payment}', [PrintController::class, 'payment'])->name('payment')->whereUuid('payment');
    Route::get('markdown-labels', [PrintController::class, 'markdownLabels'])->name('markdown-labels');
    Route::get('payslip/{payslip}', [PrintController::class, 'payslip'])->name('payslip')->whereUuid('payslip');
    Route::get('payroll/{run}/payslips', [PrintController::class, 'payslips'])->name('payslips')->whereUuid('run');
    Route::get('payroll/{run}/pay-sheet', [PrintController::class, 'paySheet'])->name('pay-sheet')->whereUuid('run');
    Route::get('payroll/{run}/pay-list.csv', [PrintController::class, 'payList'])->name('pay-list')->whereUuid('run');
    Route::get('controlled-register', [PrintController::class, 'controlledRegister'])->name('controlled-register');
    Route::get('nhis-claims.csv', [PrintController::class, 'nhisClaims'])->name('nhis-claims');
});

// Receipt photos attached to expense claims (private storage).
Route::get('expense-receipt/{claim}', ExpenseReceiptController::class)->middleware(['auth', 'license:output'])->name('expense.receipt')->whereUuid('claim');
