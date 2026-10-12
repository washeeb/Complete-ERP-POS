<?php

/*
 * The product's own brand (the software), as opposed to each client company's brand (Company → Branding). The artwork
 * lives in branding/product/ at the repository root; `npm run brand` (pos folder) writes it to public/brand/. Changing
 * the product name is this file (or BRAND_NAME in .env) plus new artwork.
 */
return [
    'name' => env('BRAND_NAME') ?: 'SES Labs',

    'tagline' => 'Engineering Solutions. Empowering Businesses.',

    // The company that makes, licenses and supports the product (the publisher), as opposed to the product name:
    // licence messages, copyright and installer "Publisher". A reseller under white-label sets BRAND_VENDOR.
    'vendor' => env('BRAND_VENDOR') ?: 'SyncLytics',

    // The publisher's contact details: on the Licence page and in licence messages, so a customer knows whom to call.
    // SyncLytics' own are the default only while the vendor is SyncLytics; a reseller (BRAND_VENDOR) gives its own.
    'website' => env('BRAND_WEBSITE') ?: (env('BRAND_VENDOR') ? null : 'https://synclyticsenterprisesystems.com'),
    'support_phone' => env('BRAND_SUPPORT_PHONE') ?: (env('BRAND_VENDOR') ? null : '024 563 6889'),
    'support_email' => env('BRAND_SUPPORT_EMAIL') ?: (env('BRAND_VENDOR') ? null : 'info@synclyticsenterprisesystems.com'),

    // The product's colour (back office before a client chooses theirs; online shop accents).
    'color' => '#031E4C',

    'logo' => '/brand/logo.svg',
    'logo_dark' => '/brand/logo-dark.svg',
    'mark' => '/brand/mark.svg',
    'favicon' => '/brand/favicon.svg',
];
