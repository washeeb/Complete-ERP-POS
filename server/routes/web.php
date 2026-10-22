<?php

use App\Domain\Purchasing\OrderSender;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\StockPhotoController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\SupportScreenshotController;
use App\Http\Controllers\WaybillConfirmController;
use App\Models\PurchaseOrder;
use App\Security\Passkeys\PasskeyLoginController;
use App\Support\Progress;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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
    Route::get('supplier-return/{return}', [PrintController::class, 'supplierReturn'])->name('supplier-return')->whereUuid('return');
    Route::get('disposal/{adjustment}', [PrintController::class, 'disposal'])->name('disposal')->whereUuid('adjustment');
    Route::get('disposal-register', [PrintController::class, 'disposalRegister'])->name('disposal-register');
    Route::get('waybill/{waybill}', [PrintController::class, 'waybill'])->name('waybill')->whereUuid('waybill');
    Route::get('labels/{token}', [PrintController::class, 'labels'])->name('labels')->whereUuid('token');
    Route::get('purchase-order/{po}', [PrintController::class, 'purchaseOrder'])->name('purchase-order')->whereUuid('po');
    Route::get('goods-receipt/{grn}', [PrintController::class, 'goodsReceipt'])->name('goods-receipt')->whereUuid('grn');
    Route::get('transfer/{t}', [PrintController::class, 'transfer'])->name('transfer')->whereUuid('t');
    Route::get('stock-request/{r}', [PrintController::class, 'stockRequest'])->name('stock-request')->whereUuid('r');
    Route::get('purchase-request/{r}', [PrintController::class, 'purchaseRequest'])->name('purchase-request')->whereUuid('r');
    Route::get('waybill/{waybill}/pod', [PrintController::class, 'waybillPod'])->name('waybill.pod')->whereUuid('waybill');
});

// Receipt photos attached to expense claims (private storage).
Route::get('support-screenshot/{report}', SupportScreenshotController::class)->middleware(['auth'])->name('support.screenshot')->whereUuid('report');
Route::get('expense-receipt/{claim}', ExpenseReceiptController::class)->middleware(['auth', 'license:output'])->name('expense.receipt')->whereUuid('claim');

// Disposal certificates attached to disposals of expired or damaged stock (private storage).
Route::get('disposal-certificate/{adjustment}', [PrintController::class, 'disposalCertificate'])->middleware(['auth', 'license:output'])->name('disposal.certificate')->whereUuid('adjustment');

// The receiver of a waybill confirms delivery from the QR code: no sign-in, the waybill's secret link only.
Route::get('w/{token}', [WaybillConfirmController::class, 'show'])->name('waybill.confirm')->middleware('throttle:30,1');
Route::post('w/{token}', [WaybillConfirmController::class, 'confirm'])->middleware('throttle:10,1');

// A supplier downloads the purchase order sent to them by WhatsApp: no sign-in, a signed link for 30 days, that PDF only.
Route::get('po/{po}', function (string $po) {
    $path = OrderSender::download($po);
    abort_unless($path, 404);

    return Storage::disk('local')->download($path, PurchaseOrder::withoutGlobalScopes()->whereKey($po)->value('number').'.pdf');
})->name('purchase-order.pdf')->whereUuid('po')->middleware(['signed', 'throttle:30,1']);

// The photo on a stock adjustment line: for people who may see that store's stock.
Route::get('stock-photo/{line}', StockPhotoController::class)->middleware(['auth'])->name('stock-photo')->whereUuid('line');

// "Ask for access" from the no-access page: the people who give roles are told who asked, for which page and why.
Route::post('access-request', AccessRequestController::class)->middleware(['auth', 'throttle:5,10'])->name('access.request');

// How far the signed-in person's long work has got (imports, bulk changes): asked by the progress card every second.
Route::get('progress-status', fn () => response()->json(Progress::live()))->middleware(['auth'])->name('progress.status');
