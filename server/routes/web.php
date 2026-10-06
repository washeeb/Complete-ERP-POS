<?php

use App\Http\Controllers\StorefrontController;
use App\Security\Passkeys\PasskeyLoginController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController;

Route::get('/', fn () => redirect('/admin'));

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
