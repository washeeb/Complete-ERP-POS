<?php

use App\Models\Passkey;
use App\Models\User;
use App\Security\Passkeys\AuthenticationOptions;
use App\Security\Passkeys\RegisterOptions;
use Spatie\LaravelPasskeys\Actions\ConfigureCeremonyStepManagerFactoryAction;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;

return [
    'redirect_to_after_login' => '/admin',

    'actions' => [
        // ERP overrides: user verification required + 32-byte challenges (app/Security/Passkeys).
        'generate_passkey_register_options' => RegisterOptions::class,
        'store_passkey' => StorePasskeyAction::class,
        'generate_passkey_authentication_options' => AuthenticationOptions::class,
        'find_passkey' => FindPasskeyToAuthenticateAction::class,
        'configure_ceremony_step_manager_factory' => ConfigureCeremonyStepManagerFactoryAction::class,
    ],

    // Passkeys are bound to this host name. Set APP_URL to the real domain before staff enrol
    // passkeys; changing the domain later means everyone re-enrols.
    'relying_party' => [
        'name' => env('APP_NAME', env('BRAND_NAME', 'SES Labs')),
        'id' => parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'icon' => null,
    ],

    'models' => [
        'passkey' => Passkey::class,
        'authenticatable' => User::class,
    ],
];
