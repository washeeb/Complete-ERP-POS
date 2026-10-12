<?php

/*
 * Licensing (2026-10-15). Licences are signed by the vendor with an Ed25519 private key that never ships;
 * installations verify them offline with the public keys below. The production public key is part of the code on
 * purpose: a key read from .env could be swapped for a home-made one. Development keys are honoured only in
 * development builds (no signed release manifest present).
 */
return [
    // kid => base64 Ed25519 public key. Add the output of `php artisan license:keygen` here before the first release.
    'public_keys' => [
        // SyncLytics' signing key (created 2026-10-12; the private half is kept offline, never in the repository).
        'syn-2026-1' => 'ZqIAUk+2GZnXxbcj6I4Uapc7HGtUdQRdqEhwz3YydZY=',
    ],

    // Development / test signing key (public part only). Ignored in release builds.
    'dev_public_key' => env('LICENSE_DEV_PUBLIC_KEY'),

    // Vendor side (vendor only): this installation acts as the licence server.
    'server' => [
        'enabled' => (bool) env('LICENSE_SERVER', false),
        'private_key_path' => env('LICENSE_PRIVATE_KEY_PATH'), // outside the repository, readable only by the server user
        'kid' => env('LICENSE_KID'),
    ],

    'warn_days' => 30, // banner before expiry
];
