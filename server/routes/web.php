<?php

use App\Domain\Access\AccessService;
use App\Domain\Access\SignInGuard;
use App\Domain\Crm\Crm;
use App\Domain\Purchasing\OrderSender;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\BrandAssetController;
use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\Office\AdjustmentsController as OfficeAdjustmentsController;
use App\Http\Controllers\Office\BatchesController as OfficeBatchesController;
use App\Http\Controllers\Office\CountsController as OfficeCountsController;
use App\Http\Controllers\Office\CustomersController as OfficeCustomersController;
use App\Http\Controllers\Office\DirectPurchasesController as OfficeDirectPurchasesController;
use App\Http\Controllers\Office\ExpenseClaimsController as OfficeExpenseClaimsController;
use App\Http\Controllers\Office\ExportController as OfficeExportController;
use App\Http\Controllers\Office\GoodsReceiptsController as OfficeGoodsReceiptsController;
use App\Http\Controllers\Office\InvoicesController as OfficeInvoicesController;
use App\Http\Controllers\Office\OfficeController;
use App\Http\Controllers\Office\ProductsController as OfficeProductsController;
use App\Http\Controllers\Office\PurchaseOrdersController as OfficePurchaseOrdersController;
use App\Http\Controllers\Office\ReorderController as OfficeReorderController;
use App\Http\Controllers\Office\ReportsController as OfficeReportsController;
use App\Http\Controllers\Office\RequestsController as OfficeRequestsController;
use App\Http\Controllers\Office\ReturnsController as OfficeReturnsController;
use App\Http\Controllers\Office\SalesController as OfficeSalesController;
use App\Http\Controllers\Office\ShiftsController as OfficeShiftsController;
use App\Http\Controllers\Office\StockController as OfficeStockController;
use App\Http\Controllers\Office\StockReportsController as OfficeStockReportsController;
use App\Http\Controllers\Office\SupplierBillsController as OfficeSupplierBillsController;
use App\Http\Controllers\Office\SupplierInvoicesController as OfficeSupplierInvoicesController;
use App\Http\Controllers\Office\SupplierPaymentsController as OfficeSupplierPaymentsController;
use App\Http\Controllers\Office\SuppliersController as OfficeSuppliersController;
use App\Http\Controllers\Office\TransfersController as OfficeTransfersController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\RemoteConnectController;
use App\Http\Controllers\RfqQuoteController;
use App\Http\Controllers\ServiceDeskPublicController;
use App\Http\Controllers\StockPhotoController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\SupplierPortalController;
use App\Http\Controllers\SupportScreenshotController;
use App\Http\Controllers\WaybillConfirmController;
use App\Http\Controllers\WhatsappMediaController;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Security\Authorizer;
use App\Security\Passkeys\PasskeyLoginController;
use App\Support\Progress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController;

Route::get('/', fn () => redirect('/admin'));

// The new back office (React + Fluent UI, built into public/office by `npm run build:office` in pos/). The page, and what it
// shows (OfficeController), for people signed in to the back office; pages not yet rebuilt open inside it.
// redirect()->away with the full address: redirect('/office/') would drop the final slash and send /office back to itself.
Route::get('/office', fn () => redirect()->away(rtrim(url('/'), '/').'/office/'));
// Signed out in the new back office: sign in, then back to the same page of it (only ever a page of /office).
Route::get('/office-signin', [OfficeController::class, 'signIn'])->name('office.sign-in');
Route::get('/office/{path?}', [OfficeController::class, 'index'])->where('path', '.*')->name('office');
Route::prefix('office-api')->middleware(['auth', 'throttle:120,1,office'])->group(function () {
    Route::get('me', [OfficeController::class, 'me']);
    Route::get('menu', [OfficeController::class, 'menu']);
    Route::get('search', [OfficeController::class, 'search']);
    Route::post('prefs', [OfficeController::class, 'prefs']);
    Route::get('home', [OfficeController::class, 'home']);
    Route::post('home-layout', [OfficeController::class, 'homeLayout']);
    Route::get('todo', [OfficeController::class, 'todo']);
    Route::get('todo-count', [OfficeController::class, 'todoCount']);
    Route::post('till-requests/{id}/decide', [OfficeController::class, 'decideTillRequest'])->whereUuid('id');
    // Screens rebuilt in the new back office (release 2: sales and shifts).
    Route::get('sales', [OfficeSalesController::class, 'index']);
    Route::get('sales/options', [OfficeSalesController::class, 'options']);
    Route::get('sales/{id}', [OfficeSalesController::class, 'show'])->whereUuid('id');
    Route::post('sales/{id}/save-customer', [OfficeSalesController::class, 'saveCustomer'])->whereUuid('id');
    Route::get('shifts', [OfficeShiftsController::class, 'index']);
    Route::get('shifts/options', [OfficeShiftsController::class, 'options']);
    Route::get('shifts/{id}', [OfficeShiftsController::class, 'show'])->whereUuid('id');
    Route::get('shifts/{id}/report', [OfficeShiftsController::class, 'report'])->whereUuid('id');
    Route::post('shifts/{id}/close', [OfficeShiftsController::class, 'close'])->whereUuid('id');
    Route::post('shifts/{id}/correct', [OfficeShiftsController::class, 'correct'])->whereUuid('id');
    Route::post('shifts/{id}/count-vault', [OfficeShiftsController::class, 'countVault'])->whereUuid('id');
    Route::get('drawer', [OfficeShiftsController::class, 'drawer']);
    Route::get('products', [OfficeProductsController::class, 'index']);
    Route::get('products/options', [OfficeProductsController::class, 'options']);
    Route::get('products/{id}', [OfficeProductsController::class, 'show'])->whereUuid('id');
    Route::post('products/labels', [OfficeProductsController::class, 'labels']);
    Route::post('products/bulk', [OfficeProductsController::class, 'bulk']);
    Route::post('products/hand-off', [OfficeProductsController::class, 'handOff']);
    Route::post('products/batches-all', [OfficeProductsController::class, 'batchesAll']);
    Route::get('products/form', [OfficeProductsController::class, 'form']);
    Route::get('products/{id}/form', [OfficeProductsController::class, 'form'])->whereUuid('id');
    Route::post('products', [OfficeProductsController::class, 'save']);
    Route::post('products/{id}', [OfficeProductsController::class, 'save'])->whereUuid('id');
    Route::post('products/quick-add', [OfficeProductsController::class, 'quickAdd']);
    Route::get('products/ingredients', [OfficeProductsController::class, 'ingredients']);
    Route::post('products/{id}/describe', [OfficeProductsController::class, 'describe'])->whereUuid('id');
    Route::get('stock', [OfficeStockController::class, 'levels']);
    Route::get('stock/options', [OfficeStockController::class, 'levelsOptions']);
    Route::get('stock/find', [OfficeStockController::class, 'find']);
    Route::get('stock/sheet', [OfficeStockController::class, 'sheet']);
    Route::post('stock/adjust', [OfficeStockController::class, 'adjust']);
    Route::get('stock/movements', [OfficeStockController::class, 'movements']);
    Route::get('stock/movements/options', [OfficeStockController::class, 'movementsOptions']);
    Route::get('stock/units', [OfficeAdjustmentsController::class, 'units']);
    Route::get('stock/unit-stock', [OfficeAdjustmentsController::class, 'unitStock']);
    Route::post('stock/photos', [OfficeAdjustmentsController::class, 'photo']);
    Route::get('adjustments', [OfficeAdjustmentsController::class, 'index']);
    Route::get('adjustments/options', [OfficeAdjustmentsController::class, 'options']);
    Route::get('adjustments/form', [OfficeAdjustmentsController::class, 'form']);
    Route::post('adjustments', [OfficeAdjustmentsController::class, 'save']);
    Route::get('adjustments/{id}', [OfficeAdjustmentsController::class, 'show'])->whereUuid('id');
    Route::get('adjustments/{id}/form', [OfficeAdjustmentsController::class, 'form'])->whereUuid('id');
    Route::post('adjustments/{id}', [OfficeAdjustmentsController::class, 'save'])->whereUuid('id');
    Route::post('adjustments/{id}/action', [OfficeAdjustmentsController::class, 'action'])->whereUuid('id');
    Route::get('adjustments/{id}/voucher', [OfficeAdjustmentsController::class, 'voucher'])->whereUuid('id');
    Route::get('counts', [OfficeCountsController::class, 'index']);
    Route::get('counts/options', [OfficeCountsController::class, 'options']);
    Route::post('counts', [OfficeCountsController::class, 'start']);
    Route::get('counts/{id}', [OfficeCountsController::class, 'show'])->whereUuid('id');
    Route::post('counts/{id}/record', [OfficeCountsController::class, 'record'])->whereUuid('id');
    Route::post('counts/{id}/action', [OfficeCountsController::class, 'action'])->whereUuid('id');
    Route::get('counts/{id}/sheet', [OfficeCountsController::class, 'sheet'])->whereUuid('id');
    Route::get('transfers', [OfficeTransfersController::class, 'index']);
    Route::get('transfers/options', [OfficeTransfersController::class, 'options']);
    Route::get('transfers/form', [OfficeTransfersController::class, 'form']);
    Route::post('transfers', [OfficeTransfersController::class, 'save']);
    Route::get('transfers/{id}', [OfficeTransfersController::class, 'show'])->whereUuid('id');
    Route::get('transfers/{id}/form', [OfficeTransfersController::class, 'form'])->whereUuid('id');
    Route::post('transfers/{id}', [OfficeTransfersController::class, 'save'])->whereUuid('id');
    Route::post('transfers/{id}/action', [OfficeTransfersController::class, 'action'])->whereUuid('id');
    Route::get('requests', [OfficeRequestsController::class, 'index']);
    Route::get('requests/options', [OfficeRequestsController::class, 'options']);
    Route::get('requests/form', [OfficeRequestsController::class, 'form']);
    Route::get('requests/stock', [OfficeRequestsController::class, 'stock']);
    Route::post('requests', [OfficeRequestsController::class, 'save']);
    Route::get('requests/{id}', [OfficeRequestsController::class, 'show'])->whereUuid('id');
    Route::get('requests/{id}/products', [OfficeRequestsController::class, 'products'])->whereUuid('id');
    Route::post('requests/{id}/action', [OfficeRequestsController::class, 'action'])->whereUuid('id');
    Route::get('customers', [OfficeCustomersController::class, 'index']);
    Route::get('customers/options', [OfficeCustomersController::class, 'options']);
    Route::get('customers/form', [OfficeCustomersController::class, 'form']);
    Route::post('customers', [OfficeCustomersController::class, 'save']);
    Route::get('customers/{id}', [OfficeCustomersController::class, 'show'])->whereUuid('id');
    Route::get('customers/{id}/form', [OfficeCustomersController::class, 'form'])->whereUuid('id');
    Route::post('customers/{id}', [OfficeCustomersController::class, 'save'])->whereUuid('id');
    Route::post('customers/{id}/action', [OfficeCustomersController::class, 'action'])->whereUuid('id');
    Route::get('invoices', [OfficeInvoicesController::class, 'index']);
    Route::get('invoices/options', [OfficeInvoicesController::class, 'options']);
    Route::get('invoices/{id}', [OfficeInvoicesController::class, 'show'])->whereUuid('id');
    Route::post('invoices/{id}/credit', [OfficeInvoicesController::class, 'credit'])->whereUuid('id');
    Route::get('suppliers', [OfficeSuppliersController::class, 'index']);
    Route::get('suppliers/options', [OfficeSuppliersController::class, 'options']);
    Route::get('suppliers/form', [OfficeSuppliersController::class, 'form']);
    Route::get('suppliers/units', [OfficeSuppliersController::class, 'units']);
    Route::post('suppliers', [OfficeSuppliersController::class, 'save']);
    Route::get('suppliers/{id}', [OfficeSuppliersController::class, 'show'])->whereUuid('id');
    Route::get('suppliers/{id}/form', [OfficeSuppliersController::class, 'form'])->whereUuid('id');
    Route::post('suppliers/{id}', [OfficeSuppliersController::class, 'save'])->whereUuid('id');
    Route::post('suppliers/{id}/action', [OfficeSuppliersController::class, 'action'])->whereUuid('id');
    Route::post('suppliers/{id}/documents', [OfficeSuppliersController::class, 'addDocument'])->whereUuid('id');
    Route::post('suppliers/{id}/documents/{doc}', [OfficeSuppliersController::class, 'document'])->whereUuid(['id', 'doc']);
    Route::get('suppliers/{id}/documents/{doc}/file', [OfficeSuppliersController::class, 'documentFile'])->whereUuid(['id', 'doc']);
    Route::get('purchase-orders', [OfficePurchaseOrdersController::class, 'index']);
    Route::get('purchase-orders/options', [OfficePurchaseOrdersController::class, 'options']);
    Route::get('purchase-orders/form', [OfficePurchaseOrdersController::class, 'form']);
    Route::get('purchase-orders/units', [OfficePurchaseOrdersController::class, 'units']);
    Route::get('purchase-orders/line-info', [OfficePurchaseOrdersController::class, 'lineInfo']);
    Route::get('purchase-orders/pack-code', [OfficePurchaseOrdersController::class, 'packCode']);
    Route::post('purchase-orders/add-supplier', [OfficePurchaseOrdersController::class, 'addSupplier']);
    Route::post('purchase-orders/import', [OfficePurchaseOrdersController::class, 'import']);
    Route::post('purchase-orders', [OfficePurchaseOrdersController::class, 'save']);
    Route::get('purchase-orders/{id}', [OfficePurchaseOrdersController::class, 'show'])->whereUuid('id');
    Route::get('purchase-orders/{id}/form', [OfficePurchaseOrdersController::class, 'form'])->whereUuid('id');
    Route::post('purchase-orders/{id}', [OfficePurchaseOrdersController::class, 'save'])->whereUuid('id');
    Route::post('purchase-orders/{id}/action', [OfficePurchaseOrdersController::class, 'action'])->whereUuid('id');
    Route::get('goods-receipts', [OfficeGoodsReceiptsController::class, 'index']);
    Route::get('goods-receipts/options', [OfficeGoodsReceiptsController::class, 'options']);
    Route::get('goods-receipts/{id}', [OfficeGoodsReceiptsController::class, 'show'])->whereUuid('id');
    Route::post('goods-receipts/{id}/action', [OfficeGoodsReceiptsController::class, 'action'])->whereUuid('id');
    Route::get('direct-purchases', [OfficeDirectPurchasesController::class, 'index']);
    Route::get('direct-purchases/options', [OfficeDirectPurchasesController::class, 'options']);
    Route::get('direct-purchases/form', [OfficeDirectPurchasesController::class, 'form']);
    Route::get('direct-purchases/units', [OfficeDirectPurchasesController::class, 'units']);
    Route::get('direct-purchases/unit', [OfficeDirectPurchasesController::class, 'unit']);
    Route::post('direct-purchases/upload', [OfficeDirectPurchasesController::class, 'upload']);
    Route::post('direct-purchases/scan', [OfficeDirectPurchasesController::class, 'scan']);
    Route::post('direct-purchases/add-product', [OfficeDirectPurchasesController::class, 'addProduct']);
    Route::post('direct-purchases', [OfficeDirectPurchasesController::class, 'save']);
    Route::get('direct-purchases/{id}', [OfficeDirectPurchasesController::class, 'show'])->whereUuid('id');
    Route::get('direct-purchases/{id}/form', [OfficeDirectPurchasesController::class, 'form'])->whereUuid('id');
    Route::get('direct-purchases/{id}/page/{n}', [OfficeDirectPurchasesController::class, 'page'])->whereUuid('id')->whereNumber('n');
    Route::post('direct-purchases/{id}', [OfficeDirectPurchasesController::class, 'save'])->whereUuid('id');
    Route::post('direct-purchases/{id}/action', [OfficeDirectPurchasesController::class, 'action'])->whereUuid('id');
    Route::get('supplier-invoices', [OfficeSupplierInvoicesController::class, 'index']);
    Route::get('supplier-invoices/options', [OfficeSupplierInvoicesController::class, 'options']);
    Route::get('supplier-invoices/{id}/bills', [OfficeSupplierInvoicesController::class, 'bills'])->whereUuid('id');
    Route::post('supplier-invoices/{id}/action', [OfficeSupplierInvoicesController::class, 'action'])->whereUuid('id');
    Route::get('consignment', [OfficeSupplierInvoicesController::class, 'consignment']);
    Route::post('consignment/settle', [OfficeSupplierInvoicesController::class, 'settle']);
    Route::get('reorder-rules', [OfficeReorderController::class, 'rules']);
    Route::get('reorder-rules/options', [OfficeReorderController::class, 'rulesOptions']);
    Route::get('reorder-rules/products', [OfficeReorderController::class, 'products']);
    Route::get('reorder-rules/units', [OfficeReorderController::class, 'productUnits']);
    Route::post('reorder-rules', [OfficeReorderController::class, 'saveRule']);
    Route::get('reorder-rules/{id}/form', [OfficeReorderController::class, 'ruleForm'])->whereUuid('id');
    Route::post('reorder-rules/{id}/default', [OfficeReorderController::class, 'useDefault'])->whereUuid('id');
    Route::get('what-to-order/options', [OfficeReorderController::class, 'orderOptions']);
    Route::post('what-to-order', [OfficeReorderController::class, 'suggestions']);
    Route::get('what-to-order/line', [OfficeReorderController::class, 'lineChoices']);
    Route::post('what-to-order/levels', [OfficeReorderController::class, 'saveLevels']);
    Route::post('what-to-order/moves', [OfficeReorderController::class, 'requestMoves']);
    Route::post('what-to-order/drafts', [OfficeReorderController::class, 'createDrafts']);
    Route::get('suggested-levels', [OfficeReorderController::class, 'suggested']);
    Route::post('suggested-levels/recalculate', [OfficeReorderController::class, 'recalculate']);
    Route::post('suggested-levels/accept', [OfficeReorderController::class, 'accept']);
    Route::get('expense-claims', [OfficeExpenseClaimsController::class, 'index']);
    Route::get('expense-claims/options', [OfficeExpenseClaimsController::class, 'options']);
    Route::get('expense-claims/form', [OfficeExpenseClaimsController::class, 'form']);
    Route::post('expense-claims', [OfficeExpenseClaimsController::class, 'save']);
    Route::get('expense-claims/{id}', [OfficeExpenseClaimsController::class, 'show'])->whereUuid('id');
    Route::post('expense-claims/{id}/action', [OfficeExpenseClaimsController::class, 'action'])->whereUuid('id');
    Route::get('supplier-bills', [OfficeSupplierBillsController::class, 'index']);
    Route::get('supplier-bills/options', [OfficeSupplierBillsController::class, 'options']);
    Route::get('supplier-bills/form', [OfficeSupplierBillsController::class, 'form']);
    Route::get('supplier-bills/billable', [OfficeSupplierBillsController::class, 'billable']);
    Route::post('supplier-bills/add-supplier', [OfficeSupplierBillsController::class, 'addSupplier']);
    Route::post('supplier-bills/scan', [OfficeSupplierBillsController::class, 'scan']);
    Route::post('supplier-bills', [OfficeSupplierBillsController::class, 'save']);
    Route::get('supplier-bills/{id}', [OfficeSupplierBillsController::class, 'show'])->whereUuid('id');
    Route::post('supplier-bills/{id}/action', [OfficeSupplierBillsController::class, 'action'])->whereUuid('id');
    Route::get('supplier-payments', [OfficeSupplierPaymentsController::class, 'index']);
    Route::get('supplier-payments/options', [OfficeSupplierPaymentsController::class, 'options']);
    Route::get('supplier-payments/preview', [OfficeSupplierPaymentsController::class, 'preview']);
    Route::post('supplier-payments', [OfficeSupplierPaymentsController::class, 'pay']);
    Route::get('aged-payables', [OfficeSupplierPaymentsController::class, 'aging']);
    Route::get('withholding-tax', [OfficeSupplierPaymentsController::class, 'withholding']);
    Route::get('withholding-tax/csv', [OfficeSupplierPaymentsController::class, 'withholdingCsv']);
    Route::get('financial-reports', [OfficeReportsController::class, 'financial']);
    Route::get('financial-reports/options', [OfficeReportsController::class, 'financialOptions']);
    Route::get('financial-reports/export', [OfficeReportsController::class, 'financialExport']);
    Route::post('financial-reports/share-head-office', [OfficeReportsController::class, 'shareHeadOffice']);
    Route::get('insights', [OfficeReportsController::class, 'insights']);
    Route::get('insights/export', [OfficeReportsController::class, 'insightsExport']);
    Route::get('shrink', [OfficeStockReportsController::class, 'shrink']);
    Route::get('shrink/export', [OfficeStockReportsController::class, 'shrinkExport']);
    Route::get('slow-stock', [OfficeStockReportsController::class, 'slow']);
    Route::get('slow-stock/export', [OfficeStockReportsController::class, 'slowExport']);
    Route::post('slow-stock/move', [OfficeStockReportsController::class, 'move']);
    Route::get('batches', [OfficeBatchesController::class, 'show']);
    Route::get('batches/products', [OfficeBatchesController::class, 'products']);
    Route::get('batches/product-batches', [OfficeBatchesController::class, 'productBatches']);
    Route::post('batches/markdowns', [OfficeBatchesController::class, 'apply']);
    Route::post('batches/markdowns/{markdown}/withdraw', [OfficeBatchesController::class, 'withdraw'])->whereUuid('markdown');
    Route::post('batches/correct', [OfficeBatchesController::class, 'correct']);
    Route::post('batches/add', [OfficeBatchesController::class, 'addBatch']);
    Route::get('returns', [OfficeReturnsController::class, 'index']);
    Route::get('returns/options', [OfficeReturnsController::class, 'options']);
    Route::get('returns/{sale}/slip', [OfficeReturnsController::class, 'slip'])->whereUuid('sale');
    Route::get('report-builder/options', [OfficeReportsController::class, 'builderOptions']);
    Route::post('report-builder/run', [OfficeReportsController::class, 'builderRun']);
    Route::get('report-builder/export', [OfficeReportsController::class, 'builderExport']);
    Route::get('saved-reports', [OfficeReportsController::class, 'savedReports']);
    Route::post('saved-reports', [OfficeReportsController::class, 'saveReport']);
    Route::get('saved-reports/{report}', [OfficeReportsController::class, 'savedReport'])->whereUuid('report');
    Route::delete('saved-reports/{report}', [OfficeReportsController::class, 'deleteReport'])->whereUuid('report');
    Route::post('saved-reports/{report}/send', [OfficeReportsController::class, 'sendReport'])->whereUuid('report');
    Route::get('export/{list}', [OfficeExportController::class, 'export'])->whereIn('list', ['sales', 'shifts', 'drawer', 'products', 'stock', 'movements', 'adjustments', 'counts', 'transfers', 'requests', 'customers', 'invoices', 'suppliers', 'purchase-orders', 'goods-receipts', 'direct-purchases', 'supplier-invoices', 'reorder-rules', 'expense-claims', 'supplier-bills', 'supplier-payments', 'returns']);
});

// The till app (built into public/pos by `npm run build:server` in pos/). Its files are served directly by the web server;
// these routes only catch the bare address and a till app that has not been built yet.
// A company's logo, icon or monogram (Company → Branding), public: it prints on receipts and shows in the online shop.
Route::get('/brand/{slug}/{kind}', BrandAssetController::class)->where(['slug' => '[a-z0-9-]+', 'kind' => 'logo|logo_dark|icon|monogram\.svg'])->name('brand.asset');

Route::get('/pos', fn () => redirect()->away(rtrim(url('/'), '/').'/pos/')); // the final slash kept, as /office
Route::get('/pos/{path?}', function () {
    $index = public_path('pos/index.html');

    return is_file($index) ? response()->file($index, ['Cache-Control' => 'no-cache'])
        : response('The till app is not installed on this server yet. In the pos folder run: npm run build:server', 404);
})->where('path', '.*');

// Passkey sign-in for the back office (package routes, with our hardened login controller).
Route::prefix('passkeys')->middleware('throttle:10,1,passkeys')->group(function () {
    Route::get('authentication-options', GeneratePasskeyAuthenticationOptionsController::class)->name('passkeys.authentication_options');
    Route::post('authenticate', PasskeyLoginController::class)->name('passkeys.login');
});

// Built-in online shop (one per storefront channel; 404 unless the channel is active).
Route::prefix('shop/{slug}')->where(['slug' => '[a-z0-9-]+'])->group(function () {
    Route::get('/', [StorefrontController::class, 'index'])->name('shop.index');
    Route::post('cart', [StorefrontController::class, 'add'])->name('shop.add')->middleware('throttle:60,1,shop-cart');
    Route::get('cart', [StorefrontController::class, 'cartView'])->name('shop.cart');
    Route::post('cart/update', [StorefrontController::class, 'update'])->name('shop.update')->middleware('throttle:60,1,shop-cart');
    Route::post('checkout', [StorefrontController::class, 'checkout'])->name('shop.checkout')->middleware('throttle:10,1,shop-checkout');
    Route::get('order/{order}', [StorefrontController::class, 'order'])->name('shop.order')->whereUuid('order')->middleware('signed');
});

// A4 documents (print or save as PDF from the browser). Signed-in back-office users only; permission-checked per document.
Route::prefix('print')->middleware(['auth', 'license:output'])->name('print.')->group(function () {
    Route::get('invoice/{invoice}', [PrintController::class, 'invoice'])->name('invoice')->whereUuid('invoice');
    Route::get('staff-cards/{token}', [PrintController::class, 'staffCards'])->name('staff-cards')->where('token', '[A-Za-z0-9]{40}'); // new staff cards, printable once
    Route::get('receipt/{sale}', [PrintController::class, 'receipt'])->name('receipt')->whereUuid('sale'); // a till sale's receipt, marked COPY
    Route::get('order/{order}', [PrintController::class, 'order'])->name('order')->whereUuid('order');
    Route::get('statement/{customer}', [PrintController::class, 'statement'])->name('statement')->whereUuid('customer');
    Route::get('payment/{payment}', [PrintController::class, 'payment'])->name('payment')->whereUuid('payment');
    Route::get('wht/{payment}', [PrintController::class, 'whtCertificate'])->name('wht')->whereUuid('payment'); // withholding tax credit certificate
    Route::get('markdown-labels', [PrintController::class, 'markdownLabels'])->name('markdown-labels');
    Route::get('payslip/{payslip}', [PrintController::class, 'payslip'])->name('payslip')->whereUuid('payslip');
    Route::get('payroll/{run}/payslips', [PrintController::class, 'payslips'])->name('payslips')->whereUuid('run');
    Route::get('payroll/{run}/pay-sheet', [PrintController::class, 'paySheet'])->name('pay-sheet')->whereUuid('run');
    Route::get('payroll/{run}/pay-list.csv', [PrintController::class, 'payList'])->name('pay-list')->whereUuid('run');
    Route::get('controlled-register', [PrintController::class, 'controlledRegister'])->name('controlled-register');
    Route::get('nhis-claims.csv', [PrintController::class, 'nhisClaims'])->name('nhis-claims');
    Route::get('nhis-claim/{claim}.csv', [PrintController::class, 'nhisClaim'])->name('nhis-claim')->whereUuid('claim');
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
Route::get('remote/connect/{device}/{mode}', RemoteConnectController::class)->middleware(['auth', 'throttle:30,1,remote-connect'])->name('remote.connect')->whereUuid('device')->where('mode', 'view|control');
Route::get('whatsapp-file/{order}/{index}', WhatsappMediaController::class)->middleware(['auth'])->name('whatsapp.file')->whereUuid('order')->whereNumber('index');
Route::get('support-screenshot/{report}', SupportScreenshotController::class)->middleware(['auth'])->name('support.screenshot')->whereUuid('report');
Route::get('expense-receipt/{claim}', ExpenseReceiptController::class)->middleware(['auth', 'license:output'])->name('expense.receipt')->whereUuid('claim');

// Disposal certificates attached to disposals of expired or damaged stock (private storage).
Route::get('disposal-certificate/{adjustment}', [PrintController::class, 'disposalCertificate'])->middleware(['auth', 'license:output'])->name('disposal.certificate')->whereUuid('adjustment');

// The receiver of a waybill confirms delivery from the QR code: no sign-in, the waybill's secret link only.
Route::get('w/{token}', [WaybillConfirmController::class, 'show'])->name('waybill.confirm')->middleware('throttle:30,1,waybill');
Route::post('w/{token}', [WaybillConfirmController::class, 'confirm'])->middleware('throttle:10,1,waybill-confirm');

// A supplier downloads the purchase order sent to them by WhatsApp: no sign-in, a signed link for 30 days, that PDF only.
Route::get('po/{po}', function (string $po) {
    $path = OrderSender::download($po);
    abort_unless($path, 404);

    return Storage::disk('local')->download($path, PurchaseOrder::withoutGlobalScopes()->whereKey($po)->value('number').'.pdf');
})->name('purchase-order.pdf')->whereUuid('po')->middleware(['signed', 'throttle:30,1,po-pdf']);

// Someone stops campaign e-mails from the link in one (CRM): no sign-in, a signed link. The page asks first, so mail
// "This wasn't me" (owner decision 2026-11-13): from the new-computer notice or e-mail. The link shows the page; the
// button signs the account out everywhere (scanners opening links change nothing). Signed, 7 days.
Route::get('account/not-me/{user}/{computer}', fn (string $user) => view('access.not-me', ['done' => false, 'name' => User::withoutGlobalScopes()->findOrFail($user)->name, 'locked' => false]))
    ->name('account.not-me')->whereUuid(['user', 'computer'])->middleware(['signed', 'throttle:20,1,not-me']);
Route::post('account/not-me/{user}/{computer}', function (Request $request, string $user, string $computer) {
    $user = User::withoutGlobalScopes()->findOrFail($user);
    app(SignInGuard::class)->notMe($user, $computer, $request->boolean('lock'));

    return view('access.not-me', ['done' => true, 'name' => $user->name, 'locked' => $request->boolean('lock')]);
})->whereUuid(['user', 'computer'])->middleware(['signed', 'throttle:20,1,not-me-answer']);

// scanners opening links do not unsubscribe people; the button posts.
Route::get('u/{kind}/{id}/{channel}', fn (string $kind, string $id, string $channel) => view('crm-unsubscribe', ['done' => false, 'company' => null]))
    ->name('crm.unsubscribe')->where(['kind' => 'customer|lead', 'channel' => 'email|sms|whatsapp'])->whereUuid('id')->middleware(['signed', 'throttle:30,1,unsubscribe']);
Route::post('u/{kind}/{id}/{channel}', fn (string $kind, string $id, string $channel) => view('crm-unsubscribe', ['done' => true,
    'company' => app(Crm::class)->unsubscribe($kind, $id, $channel)]))
    ->where(['kind' => 'customer|lead', 'channel' => 'email|sms|whatsapp'])->whereUuid('id')->middleware(['signed', 'throttle:10,1,unsubscribe-answer']);

// Service desk (paid add-on): customers report a problem on the company's web form; "How did we do?" after a ticket is
// resolved (signed link; the page asks first).
Route::get('help/{company}', [ServiceDeskPublicController::class, 'form'])->where('company', '[a-z0-9-]+')->middleware('throttle:30,1,help')->name('service.help');
Route::post('help/{company}', [ServiceDeskPublicController::class, 'submit'])->where('company', '[a-z0-9-]+')->middleware('throttle:5,1,help-submit');
Route::get('r/{ticket}', [ServiceDeskPublicController::class, 'rateForm'])->whereUuid('ticket')->middleware(['signed', 'throttle:30,1,rate'])->name('ticket.rate');
Route::post('r/{ticket}', [ServiceDeskPublicController::class, 'rate'])->whereUuid('ticket')->middleware(['signed', 'throttle:10,1,rate-submit']);

// A supplier quotes on a request for quotation from the link sent to them: no sign-in, that request only.
Route::get('q/{token}', [RfqQuoteController::class, 'show'])->name('rfq.quote')->middleware('throttle:30,1,rfq');
Route::post('q/{token}', [RfqQuoteController::class, 'quote'])->middleware('throttle:10,1,rfq-submit');

// The supplier portal: the supplier's own page from their secret link (orders, invoices, payments). 404 while it is off.
Route::prefix('s/{token}')->middleware('throttle:30,1,supplier-portal')->group(function () {
    Route::get('/', [SupplierPortalController::class, 'home'])->name('supplier.portal');
    Route::get('order/{po}', [SupplierPortalController::class, 'order'])->name('supplier.portal.order')->whereUuid('po');
    Route::get('order/{po}/pdf', [SupplierPortalController::class, 'orderPdf'])->name('supplier.portal.order-pdf')->whereUuid('po');
    Route::post('order/{po}', [SupplierPortalController::class, 'act'])->whereUuid('po')->middleware('throttle:10,1,supplier-order');
    Route::post('invoice', [SupplierPortalController::class, 'invoice'])->name('supplier.portal.invoice')->middleware('throttle:10,1,supplier-invoice');
    Route::get('wht/{payment}', [SupplierPortalController::class, 'wht'])->name('supplier.portal.wht')->whereUuid('payment');
});
Route::get('agreement-file/{agreement}', function (PurchaseAgreement $agreement, Authorizer $authz) {
    $u = request()->user();
    abort_unless($u && $u->tenant_id === $agreement->tenant_id && collect(['purchasing.view', 'purchasing.manage', 'purchasing.approve'])->contains(fn ($p) => $authz->allowsAnywhere($u, $p)), 403);
    abort_unless($agreement->file_path && Storage::disk('local')->exists($agreement->file_path), 404);

    return Storage::disk('local')->response($agreement->file_path, $agreement->file_name);
})->middleware(['auth'])->name('agreement.file')->whereUuid('agreement');
Route::get('supplier-invoice/{invoice}', [SupplierPortalController::class, 'file'])->middleware(['auth'])->name('supplier-invoice.file')->whereUuid('invoice');

// The photo on a stock adjustment line: for people who may see that store's stock.
Route::get('stock-photo/{line}', StockPhotoController::class)->middleware(['auth'])->name('stock-photo')->whereUuid('line');

// "Ask for access" from the no-access page: the people who give roles are told who asked, for which page and why.
Route::post('access-request', AccessRequestController::class)->middleware(['auth', 'throttle:5,10,access-request'])->name('access.request');

// How far the signed-in person's long work has got (imports, bulk changes): asked by the progress card every second.
Route::get('progress-status', fn () => response()->json(Progress::live()))->middleware(['auth'])->name('progress.status');

// A person's profile photo: for people signed in to the same company. Cached by the browser (the address changes
// with the photo).
Route::get('user-photo/{user}', function (User $user) {
    $me = request()->user();
    abort_unless($me && $me->tenant_id === $user->tenant_id, 403);
    abort_unless($user->avatar_path && Storage::disk('local')->exists($user->avatar_path), 404);

    return Storage::disk('local')->response($user->avatar_path, 'photo.jpg', ['Cache-Control' => 'private, max-age=604800, immutable']);
})->middleware(['auth'])->name('user.photo')->whereUuid('user');

// The star on the menu band: adds the page to the person's favourites, or takes it off (new look).
Route::post('admin-ui/favourite', function (Request $request, AccessService $access) {
    $data = $request->validate(['url' => ['required', 'string', 'max:500'], 'label' => ['nullable', 'string', 'max:200']]);
    try {
        $list = DB::transaction(fn () => $access->toggleOwnFavourite(Actor::user($request->user()), $request->user(), $data['url'], (string) ($data['label'] ?? '')));
    } catch (DomainException $e) {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    return response()->json(['favourites' => $list]);
})->middleware(['auth', 'throttle:30,1,favourite'])->name('ui.favourite');
