<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Machine Events Archival Settings
    |--------------------------------------------------------------------------
    |
    | These settings control how machine events are archived and compressed.
    | Archival moves old machine workflows from the active events table to
    | compressed storage to improve performance and reduce storage costs.
    |
    */
    'archival' => [
        // Enable/disable archival globally
        'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),

        // Compression level (0-9). Higher levels provide better compression
        // but use more CPU. Level 6 provides good balance of speed/compression.
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),

        // Minimum data size (in bytes) before compression is applied during archival.
        // Smaller datasets may not benefit from compression due to overhead.
        'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),

        // Archive after this many days of inactivity
        'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),

        // Restore tracking and cooldown settings
        'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),

        // Automatic archive retention policy (days). Set to null to keep forever.
        'archive_retention_days' => env('MACHINE_EVENTS_ARCHIVE_RETENTION_DAYS'),

        // Advanced settings for enterprise environments
        'advanced' => [
            // Batch size for archival processing (per transaction)
            'batch_size' => env('MACHINE_EVENTS_ARCHIVAL_BATCH_SIZE', 100),
        ],

        // Machine-specific overrides (can be set per machine type)
        'machine_overrides' => [
            // Example:
            // 'critical_machine' => [
            //     'days_inactive' => 90,      // Keep longer for critical machines
            //     'compression_level' => 9,   // Maximum compression
            // ],
        ],
    ],
];
