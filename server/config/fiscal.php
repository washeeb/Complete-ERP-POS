<?php

return [
    // GRA E-VAT access, issued by GRA when the business is onboarded. Empty = nothing is sent (submissions wait).
    'gra' => [
        'url' => env('GRA_EVAT_URL', ''),
        'key' => env('GRA_EVAT_KEY', ''),
        'tin' => env('GRA_EVAT_TIN', ''),
        'reference' => env('GRA_EVAT_COMPANY_REFERENCE', ''), // in the address, e.g. C00XXXXXXXX-001
        // Separate books: {"<TIN>": {"reference": "…", "key": "…"}} for each other legal entity with its own GRA account.
        'accounts' => json_decode((string) env('GRA_EVAT_ACCOUNTS', '{}'), true) ?: [],
        'timeout' => (int) env('GRA_EVAT_TIMEOUT', 8), // seconds the till waits for GRA before printing without the code
    ],
];
