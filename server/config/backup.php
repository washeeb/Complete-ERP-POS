<?php

return [
    // Encrypts every backup. Without it a backup cannot be restored: keep a printed copy away from the server.
    'passphrase' => env('BACKUP_PASSPHRASE', ''),

    // Folder holding PostgreSQL's pg_dump / pg_restore / psql (empty = they are on the PATH).
    'pg_bin' => env('BACKUP_PG_BIN', ''),

    // When the daily backup runs (server time, HH:MM) and how many backups stay on the server itself.
    'time' => env('BACKUP_TIME', '02:00'),
    'keep_local' => (int) env('BACKUP_KEEP_LOCAL', 7),

    // Where the server keeps its own copies (inside storage, never web-accessible).
    'local_dir' => storage_path('app/private/backups'),
];
