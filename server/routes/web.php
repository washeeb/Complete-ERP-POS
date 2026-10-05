<?php

use App\Security\Passkeys\PasskeyLoginController;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController;

Route::get('/', fn () => redirect('/admin'));

// Passkey sign-in for the back office (package routes, with our hardened login controller).
Route::prefix('passkeys')->middleware('throttle:10,1')->group(function () {
    Route::get('authentication-options', GeneratePasskeyAuthenticationOptionsController::class)->name('passkeys.authentication_options');
    Route::post('authenticate', PasskeyLoginController::class)->name('passkeys.login');
});
