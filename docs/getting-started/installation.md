# Installation

## Requirements

- PHP 8.2 or higher
- Laravel 10.x, 11.x, or 12.x
- ext-zlib extension (for compression features)

## Installation via Composer

Install EventMachine using Composer:

```bash
composer require tarfin-labs/event-machine
```

## Publish Configuration (Optional)

You can publish the configuration file to customize compression settings:

```bash
php artisan vendor:publish --provider="Tarfinlabs\EventMachine\MachineServiceProvider" --tag="machine-config"
```

This will create `config/machine.php` with the following default settings:

```php
<?php

return [
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
```

## Run Migrations

EventMachine requires a database table to store machine events. Run the migration:

```bash
php artisan migrate
```

This creates the `machine_events` table with the following structure:

- `id`: Primary key
- `machine_id`: Unique identifier for the machine instance
- `root_event_id`: Reference to the root event (for state restoration)
- `machine_value`: Current machine state (JSON, uncompressed for querying)
- `payload`: Event payload (compressed if enabled)
- `context`: Machine context data (compressed if enabled)
- `meta`: Additional metadata (compressed if enabled)
- `created_at`: Timestamp

## Environment Variables

You can configure EventMachine behavior using environment variables:

```env
# Enable/disable compression (default: true)
MACHINE_EVENTS_COMPRESSION_ENABLED=true

# Compression level 0-9 (default: 6)
MACHINE_EVENTS_COMPRESSION_LEVEL=6

# Minimum size in bytes before compression kicks in (default: 100)
MACHINE_EVENTS_COMPRESSION_THRESHOLD=100
```

## Service Provider Registration

The package automatically registers its service provider through Laravel's package discovery. The following services are registered:

- **EventMachine Facade**: `EventMachine`
- **Artisan Commands**:
  - `machine:generate-uml` - Generate UML diagrams
  - `machine:validate-config` - Validate machine configurations
  - `machine:compress-events` - Compress existing events
  - `machine:migrate-events` - Migrate events for v3.0 compatibility

## Verify Installation

You can verify that EventMachine is properly installed by running:

```bash
php artisan machine:validate-config
```

This command will check that your installation is working correctly.

## Next Steps

Now that EventMachine is installed, let's create your first state machine:

- [Quick Start](./quick-start.md)
- [Your First State Machine](./first-machine.md)