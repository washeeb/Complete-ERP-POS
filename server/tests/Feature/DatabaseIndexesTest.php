<?php

use Illuminate\Support\Facades\DB;

it('indexes every foreign key, so lists and reports stay fast as data grows', function () {
    $missing = collect(DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text || '.' || a.attname AS col
        FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        JOIN pg_class t ON t.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace AND n.nspname = current_schema()
        WHERE c.contype = 'f' AND array_length(c.conkey, 1) = 1
          AND NOT EXISTS (SELECT 1 FROM pg_index i WHERE i.indrelid = c.conrelid AND i.indkey[0] = c.conkey[1])
    SQL))->pluck('col')->all();

    expect($missing)->toBe([]); // add an index in the migration that creates the column (->index(), or a composite starting with it)
});
