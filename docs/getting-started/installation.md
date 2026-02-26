# Installation

## Requirements

- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x
- ext-zlib (for compression features)

## Install via Composer

```bash
composer require tarfin-labs/event-machine
```

## Publish Configuration

```bash
php artisan vendor:publish --tag="machine-config"
```

This creates `config/machine.php`:

```php
return [
    'archival' => [
        // Enable/disable archival globally
        'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),

        // Compression level (0-9)
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),

        // Minimum data size (bytes) before compression
        'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),

        // Archive after this many days of inactivity
        'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),

        // Cooldown hours before allowing restore
        'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),

        'advanced' => [
            // Max workflows to dispatch per scheduler run
            'dispatch_limit' => env('MACHINE_EVENTS_ARCHIVAL_DISPATCH_LIMIT', 50),

            // Queue name (null = default queue)
            'queue' => env('MACHINE_EVENTS_ARCHIVAL_QUEUE'),
        ],
    ],
];
```

## Run Migrations

```bash
php artisan migrate
```

This creates the `machine_events` table for event sourcing.

## Verify Installation

Create a simple test machine:

```php no_run
use Tarfinlabs\EventMachine\Actor\Machine;

$machine = Machine::create([
    'config' => [
        'initial' => 'idle',
        'states' => [
            'idle' => [
                'on' => ['START' => 'running'],
            ],
            'running' => [
                'on' => ['STOP' => 'idle'],
            ],
        ],
    ],
]);

$state = $machine->send(['type' => 'START']);

echo $state->matches('running'); // true
```

If this works, EventMachine is installed correctly.