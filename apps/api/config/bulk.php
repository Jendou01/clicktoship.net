<?php

return [
    'max_bytes' => (int) env('BULK_MAX_BYTES', 10485760),
    'max_rows' => (int) env('BULK_MAX_ROWS', 10000),
    'max_columns' => 96,
    'max_cell_bytes' => 4096,
    'max_record_bytes' => 262144,
    'max_expanded_bytes' => 33554432,
    'max_zip_entries' => 64,
    'max_zip_ratio' => 100,
    'chunk_rows' => (int) env('BULK_CHUNK_ROWS', 100),
    'max_pending_batches' => 5,
    'max_retained_bytes' => 104857600,
    'max_daily_imports' => 20,
    'processing_seconds' => 40,
    // Structural inspection is NOT an antivirus certification. Production fails closed
    // until a reviewed malware scanner and retention/storage policy are installed.
    'structural_only' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
];
