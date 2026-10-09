<?php

return [
    // GRA E-VAT access, issued by GRA when the business is onboarded. Empty = nothing is sent (submissions wait).
    'gra' => [
        'url' => env('GRA_EVAT_URL', ''),
        'key' => env('GRA_EVAT_KEY', ''),
        'tin' => env('GRA_EVAT_TIN', ''),
        'timeout' => (int) env('GRA_EVAT_TIMEOUT', 8), // seconds the till waits for GRA before printing without the code
    ],
];
