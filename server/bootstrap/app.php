<?php

use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnforceLicense;
use App\Http\Middleware\EnsureApiClient;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\EnsureMfaForLevel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->redirectGuestsTo('/admin/login'); // printable documents live outside the panel
        $middleware->alias([
            'device.token' => EnsureDeviceToken::class,
            'api.client' => EnsureApiClient::class,
            'erp.mfa' => EnsureMfaForLevel::class,
            'license' => EnforceLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API error shape: {"error": "stable_code", "detail": "safe explanation"}
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->is('api/*')) {
                $status = match (true) {
                    $e instanceof AccessDenied => 403,
                    in_array($e->errorCode, ['invalid_credentials', 'device_revoked', 'enrollment_failed', 'bad_signature'], true) => 401,
                    in_array($e->errorCode, ['pin_locked', 'license_read_only', 'license_missing'], true) => 423,
                    $e->errorCode === 'conflict' => 409,
                    $e->errorCode === 'not_found' => 404,
                    default => 422,
                };

                return response()->json(['error' => $e->errorCode, 'detail' => $e->getMessage()], $status);
            }
        });
    })->create();
