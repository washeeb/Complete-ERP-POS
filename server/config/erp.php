<?php

return [
    // Every node runs this codebase; its role is configuration (spec §3.1).
    'node' => [
        'role' => env('ERP_NODE_ROLE', 'hq'), // hq | branch | standalone
        'id' => env('ERP_NODE_ID', 'HQ'),     // short, unique per node; stamped on every record as origin_node
    ],

    // Key for the audit HMAC chain. Keep it outside the database; rotating it requires
    // re-anchoring the chain (documented in docs/security.md).
    'audit_key' => env('ERP_AUDIT_KEY'),

    'modules_path' => base_path('modules'),

    // Shown in problem reports and the pilot report; raised with each release (CHANGELOG).
    'version' => '0.25.0',

    // Where "Report a problem" and the daily pilot report go when the go-live plan names no support address.
    'support_email' => env('ERP_SUPPORT_EMAIL'),
];
