# Installation

## Requirements

- PHP 8.2 or higher
- Laravel 10.x or 11.x
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
    // Table name for storing events
    'table_name' => env('MACHINE_EVENTS_TABLE', 'machine_events'),

    // Event archival settings
    'archival' => [
        'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),
        'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),
        'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),
        'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),
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

```php
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

## Next Steps

- [Build your first machine](/getting-started/your-first-machine) - A complete tutorial
- [Understanding states](/understanding/states-and-transitions) - Learn the core model
