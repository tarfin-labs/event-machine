# Laravel Integration Overview

EventMachine is designed as a Laravel-first package with deep integration into the Laravel ecosystem.

## Service Provider

EventMachine automatically registers through Laravel's package auto-discovery:

<!-- doctest-attr: ignore -->
```php
// Registered automatically via composer.json
Tarfinlabs\EventMachine\MachineServiceProvider::class
```

The service provider:
- Publishes configuration and migrations
- Registers Artisan commands
- Sets up the Facade

## Facade

Access EventMachine functionality via the facade:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Facades\EventMachine;

// Reset all fakes (useful in testing)
EventMachine::resetAllFakes();
```

## Configuration

Publish configuration:

```bash
php artisan vendor:publish --tag="event-machine-config"
```

This creates `config/machine.php` with archival settings.

## Migrations

Publish and run migrations:

```bash
php artisan vendor:publish --tag="event-machine-migrations"
php artisan migrate
```

Creates:
- `machine_events` - Stores all machine events
- `machine_events_archive` - Stores compressed archived events

## Key Integration Features

### Eloquent Integration

Attach machines to models:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Traits\HasMachines;

class Order extends Model
{
    use HasMachines;

    protected function machines(): array
    {
        return [
            'status' => OrderStatusMachine::class . ':order',
        ];
    }
}

// Usage
$order->status->send(['type' => 'SUBMIT']);
```

### Dependency Injection

Laravel's container injects dependencies into behaviors:

```php
use Tarfinlabs\EventMachine\Behavior\ActionBehavior; // [!code hide]

class ProcessOrderAction extends ActionBehavior
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}
}
```

### Database Persistence

Events are automatically persisted to the database:

<!-- doctest-attr: ignore -->
```php
$machine = OrderMachine::create();
$machine->send(['type' => 'SUBMIT']);

// Events stored in machine_events table
```

### Queue Integration

Archive jobs can be dispatched to queues:

```bash
php artisan machine:archive-events --queue
```

## Artisan Commands

| Command | Description |
|---------|-------------|
| `machine:uml {machine}` | Generate PlantUML diagram |
| `machine:validate {machine?} --all` | Validate configuration |
| `machine:archive-events` | Archive old events |
| `machine:archive-status` | Check archive status |

## Distributed Locking

EventMachine uses Laravel's cache-based locking to prevent concurrent event processing:

<!-- doctest-attr: ignore -->
```php
// Behind the scenes
Cache::lock("machine:{$rootEventId}", 60)->block(5, function () {
    // Process event
});
```

## Transaction Support

Events can be wrapped in database transactions:

```php
use Tarfinlabs\EventMachine\Behavior\EventBehavior; // [!code hide]

class CriticalEvent extends EventBehavior
{
    public bool $isTransactional = true; // Default
    public static function getType(): string { return 'CRITICAL'; } // [!code hide]
}
```
