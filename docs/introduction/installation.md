# Installation

## Requirements

- PHP 8.2, 8.3, or 8.4
- Laravel 10.48.4+, 11.0.8+, or 12.0+
- ext-zlib (for compression support)

## Install via Composer

```bash
composer require tarfin-labs/event-machine
```

## Publish and Run Migrations

EventMachine stores events in the database. Publish the migrations:

```bash
php artisan vendor:publish --tag="event-machine-migrations"
```

Run the migrations:

```bash
php artisan migrate
```

This creates two tables:
- `machine_events` - Stores all machine events
- `machine_events_archive` - Stores compressed archived events

## Publish Configuration (Optional)

Publish the configuration file to customize EventMachine:

```bash
php artisan vendor:publish --tag="event-machine-config"
```

This creates `config/machine.php`:

```php
return [
    'archival' => [
        // Enable/disable archival globally
        'enabled' => env('MACHINE_EVENTS_ARCHIVAL_ENABLED', true),

        // Compression level (0-9): Higher = better compression, more CPU
        'level' => env('MACHINE_EVENTS_COMPRESSION_LEVEL', 6),

        // Minimum data size (bytes) before compression
        'threshold' => env('MACHINE_EVENTS_ARCHIVAL_THRESHOLD', 1000),

        // Archive events after this many days of inactivity
        'days_inactive' => env('MACHINE_EVENTS_ARCHIVAL_DAYS', 30),

        // Cooldown hours before allowing restore
        'restore_cooldown_hours' => env('MACHINE_EVENTS_RESTORE_COOLDOWN_HOURS', 24),

        // Archive retention (days). null = keep forever
        'archive_retention_days' => env('MACHINE_EVENTS_ARCHIVE_RETENTION_DAYS', null),

        'advanced' => [
            'batch_size' => env('MACHINE_EVENTS_ARCHIVAL_BATCH_SIZE', 100),
        ],

        // Per-machine overrides
        'machine_overrides' => [
            // 'critical_machine' => [
            //     'days_inactive' => 90,
            //     'compression_level' => 9,
            // ],
        ],
    ],
];
```

## Configuration Options

### Archival Settings

| Option | Default | Description |
|--------|---------|-------------|
| `enabled` | `true` | Enable/disable event archival |
| `level` | `6` | Compression level (0-9) |
| `threshold` | `1000` | Minimum bytes before compression |
| `days_inactive` | `30` | Days before archival |
| `restore_cooldown_hours` | `24` | Hours between restores |
| `archive_retention_days` | `null` | Days to keep archives |

### Environment Variables

Add to your `.env` file:

```ini
MACHINE_EVENTS_ARCHIVAL_ENABLED=true
MACHINE_EVENTS_COMPRESSION_LEVEL=6
MACHINE_EVENTS_ARCHIVAL_DAYS=30
```

## Verify Installation

Create a simple test machine:

```php
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

$machine = MachineDefinition::define(
    config: [
        'initial' => 'idle',
        'states' => [
            'idle' => [
                'on' => ['START' => 'running'],
            ],
            'running' => [],
        ],
    ],
);

$state = $machine->getInitialState();
echo $state->value[0]; // 'machine.idle'
```
