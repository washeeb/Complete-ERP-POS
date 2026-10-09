<?php

/*
 * The product's own brand (the software), as opposed to each client company's brand (Company → Branding). The artwork
 * lives in branding/product/ at the repository root; `npm run brand` (pos folder) writes it to public/brand/. Changing
 * the product name is this file (or BRAND_NAME in .env) plus new artwork.
 */
return [
    'name' => env('BRAND_NAME') ?: 'SES Labs',

    'tagline' => 'Engineering Solutions. Empowering Businesses.',

    // Who issues licences and gives support (shown in licence messages). Defaults to the product name.
    'vendor' => env('BRAND_VENDOR') ?: (env('BRAND_NAME') ?: 'SES Labs'),

    'website' => env('BRAND_WEBSITE') ?: null,

    // The product's colour (back office before a client chooses theirs; online shop accents).
    'color' => '#031E4C',

    'logo' => '/brand/logo.svg',
    'logo_dark' => '/brand/logo-dark.svg',
    'mark' => '/brand/mark.svg',
    'favicon' => '/brand/favicon.svg',
];
