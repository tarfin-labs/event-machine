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

        // Default archival triggers (can be overridden per machine)
        'triggers' => [
            // Archive after this many days of inactivity
            'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),

            // Archive when machine has this many events (0 = disabled)
            'max_events' => env('MACHINE_EVENTS_ARCHIVAL_MAX_EVENTS', 0),

            // Archive when total machine events exceed this size in bytes (0 = disabled)
            'max_size' => env('MACHINE_EVENTS_ARCHIVAL_MAX_SIZE', 0),
        ],

        // Restore tracking and cooldown settings
        'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),

        // Automatic archive retention policy (days). Set to null to keep forever.
        'archive_retention_days' => env('MACHINE_EVENTS_ARCHIVE_RETENTION_DAYS', null),

        // Advanced settings for enterprise environments
        'advanced' => [
            // Batch size for archival processing (per transaction)
            'batch_size' => env('MACHINE_EVENTS_ARCHIVAL_BATCH_SIZE', 100),

            // Maximum number of concurrent archival jobs
            'max_concurrent_jobs' => env('MACHINE_EVENTS_MAX_CONCURRENT_JOBS', 3),

            // Whether to automatically schedule periodic archival
            'auto_schedule' => env('MACHINE_EVENTS_AUTO_SCHEDULE_ARCHIVAL', false),

            // Archival schedule (cron expression for auto scheduling)
            'schedule_expression' => env('MACHINE_EVENTS_ARCHIVAL_SCHEDULE', '0 2 * * *'), // Daily at 2 AM

            // Performance monitoring - log slow archive operations (seconds)
            'slow_operation_threshold' => env('MACHINE_EVENTS_SLOW_THRESHOLD', 60),

            // Archive verification - verify compressed data integrity
            'verify_integrity' => env('MACHINE_EVENTS_VERIFY_INTEGRITY', true),
        ],

        // Machine-specific overrides (can be set per machine type)
        'machine_overrides' => [
            // Example:
            // 'critical_machine' => [
            //     'triggers' => [
            //         'days_inactive' => 90,  // Keep longer for critical machines
            //         'max_events' => 1000,   // Archive when very large
            //     ],
            //     'compression_level' => 9,   // Maximum compression
            // ],
        ],
    ],
];
