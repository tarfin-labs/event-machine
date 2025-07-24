<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Data Compression Settings
    |--------------------------------------------------------------------------
    |
    | These settings control how machine event data is compressed before storing
    | in the database. Compression can significantly reduce storage requirements
    | for large payloads, contexts, and metadata.
    |
    */
    'compression' => [
        // Enable/disable compression globally
        'enabled' => env('MACHINE_EVENTS_COMPRESSION_ENABLED', true),

        // Compression level (0-9). Higher levels provide better compression
        // but use more CPU. Level 6 provides good balance of speed/compression.
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),

        // Fields to compress. machine_value is kept as JSON for querying.
        'fields' => ['payload', 'context', 'meta'],

        // Minimum data size (in bytes) before compression is applied.
        // Small data may not benefit from compression due to overhead.
        'threshold' => env('MACHINE_EVENTS_COMPRESSION_THRESHOLD', 100),
    ],
];
