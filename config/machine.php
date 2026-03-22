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

        // Advanced settings for enterprise environments
        'advanced' => [
            // Max workflows (unique root_event_ids) to dispatch per scheduler run
            'dispatch_limit' => env('MACHINE_EVENTS_ARCHIVAL_DISPATCH_LIMIT', 50),

            // Queue name for archival jobs (null = default queue)
            // For high-volume archival, use a dedicated queue with its own workers
            'queue' => env('MACHINE_EVENTS_ARCHIVAL_QUEUE'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Parallel Dispatch Settings
    |--------------------------------------------------------------------------
    |
    | These settings control queue-based parallel execution for parallel state
    | regions. When enabled, region entry actions are dispatched as separate
    | Laravel queue jobs, running truly in parallel across queue workers.
    |
    */
    'parallel_dispatch' => [
        // Enable/disable parallel dispatch globally
        'enabled' => env('MACHINE_PARALLEL_DISPATCH_ENABLED', false),

        // Queue name for parallel region jobs (null = default queue)
        'queue' => env('MACHINE_PARALLEL_DISPATCH_QUEUE'),

        // Seconds to wait for database lock acquisition
        'lock_timeout' => env('MACHINE_PARALLEL_DISPATCH_LOCK_TIMEOUT', 30),

        // Seconds before a lock is considered stale
        'lock_ttl' => env('MACHINE_PARALLEL_DISPATCH_LOCK_TTL', 60),

        // Job configuration for ParallelRegionJob
        'job_timeout' => env('MACHINE_PARALLEL_DISPATCH_JOB_TIMEOUT', 300),
        'job_tries'   => env('MACHINE_PARALLEL_DISPATCH_JOB_TRIES', 3),
        'job_backoff' => env('MACHINE_PARALLEL_DISPATCH_JOB_BACKOFF', 30),

        // Seconds before a parallel state is considered stuck. When a parallel
        // state has not completed (all regions final) within this duration, a
        // delayed check job fires and triggers @fail on the parallel state.
        // Set to 0 to disable (default).
        'region_timeout' => env('MACHINE_PARALLEL_DISPATCH_REGION_TIMEOUT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timer Settings
    |--------------------------------------------------------------------------
    |
    | These settings control how time-based events (`after` and `every` keys
    | on transitions) are processed. The sweep command runs at the configured
    | resolution and checks all machine instances for due timers.
    |
    */
    'timers' => [
        // How often the sweep command runs. Maps to Laravel Scheduler method names.
        // Use TimerResolution enum values: everyMinute, everyFiveMinutes, etc.
        'resolution' => env('MACHINE_TIMER_RESOLUTION', 'everyMinute'),

        // Maximum instances to process per sweep query batch.
        // Higher values process more instances per sweep but use more memory.
        'batch_size' => env('MACHINE_TIMER_BATCH_SIZE', 100),

        // Skip sweep if queue has more pending jobs than this threshold.
        // Prevents overwhelming the queue when it's already saturated.
        'backpressure_threshold' => env('MACHINE_TIMER_BACKPRESSURE_THRESHOLD', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Infinite Loop Protection
    |--------------------------------------------------------------------------
    |
    | Maximum recursive transition depth allowed within a single macrostep.
    | Prevents stack overflow from @always transition loops and raise() cycles.
    |
    | Inspired by IBM Rhapsody's DEFAULT_MAX_NULL_STEPS (default: 100),
    | the industry-standard approach from David Harel's statechart implementation.
    |
    | Each external send()/transition() call starts at depth 0. Internal recursive
    | calls (@always, event queue processing) increment the counter. When the limit
    | is reached, MaxTransitionDepthExceededException is thrown.
    |
    | Child machines (sync delegation) have their own independent counter.
    | Queue-dispatched events (timers, scheduled, dispatchTo) start fresh macrosteps.
    |
    */
    'max_transition_depth' => env('MACHINE_MAX_TRANSITION_DEPTH', 100),

    /*
    |--------------------------------------------------------------------------
    | Scenarios
    |--------------------------------------------------------------------------
    |
    | Scenarios are pre-scripted event replay sequences that bring a machine
    | to a desired state. They are intended for staging/test environments
    | only. When disabled, scenario classes are not loaded and endpoints
    | are not registered.
    |
    */
    'scenarios' => [
        'enabled' => env('MACHINE_SCENARIOS_ENABLED', false),
        'path'    => app_path('Machines/Scenarios'),
    ],
];
