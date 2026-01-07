# Artisan Commands

EventMachine provides several Artisan commands for managing state machines.

## machine:validate

Validate machine configuration for potential issues.

### Usage

```bash
# Validate specific machine
php artisan machine:validate "App\Machines\OrderMachine"

# Validate all machines in project
php artisan machine:validate --all
```

### What It Checks

- Valid state configuration keys
- Final states without transitions
- Final states without children
- Required initial states for compound states
- Behavior references

### Example Output

```
Validating: App\Machines\OrderMachine

✓ State configuration is valid
✓ Final states have no transitions
✓ All behaviors are registered
✓ Initial states are defined

Validation passed!
```

### Error Examples

```
Validating: App\Machines\BrokenMachine

✗ State 'completed' is final but has transitions
✗ State 'processing' is compound but has no initial state
✗ Behavior 'unknownAction' is not registered

Validation failed with 3 errors.
```

## machine:uml

Generate PlantUML state diagrams for visualization.

### Usage

```bash
# Generate UML for a machine
php artisan machine:uml "App\Machines\OrderMachine"

# Output to specific file
php artisan machine:uml "App\Machines\OrderMachine" --output=order.puml
```

### Example Output

```text
@startuml OrderMachine

[*] --> pending

state pending {
}

pending --> processing : SUBMIT [hasItems]
processing --> completed : COMPLETE
processing --> cancelled : CANCEL

state completed <<final>> {
}

state cancelled <<final>> {
}

completed --> [*]
cancelled --> [*]

@enduml
```

### Rendering

Use PlantUML to render the diagram:

```bash
# Install PlantUML
brew install plantuml

# Render to PNG
plantuml order.puml

# Render to SVG
plantuml -tsvg order.puml
```

### Features Shown

- States and nested states
- Transitions with event names
- Guards (in square brackets)
- Final states
- Initial states

## machine:archive-events

Archive old machine events to compressed storage.

### Usage

```bash
# Run archival synchronously
php artisan machine:archive-events

# Preview without changes (dry run)
php artisan machine:archive-events --dry-run

# Dispatch to queue
php artisan machine:archive-events --queue

# Custom batch size
php artisan machine:archive-events --batch-size=200

# Skip confirmation prompt
php artisan machine:archive-events --force
```

### Options

| Option | Description |
|--------|-------------|
| `--batch-size=N` | Instances per batch (default: 100) |
| `--dry-run` | Preview without changes |
| `--force` | Skip confirmation |
| `--queue` | Dispatch to queue |

### Example Output

```
Archiving machine events...

Configuration:
  Days inactive: 30
  Compression level: 6
  Batch size: 100

Found 1,234 machines eligible for archival.

Proceed with archival? (yes/no) [no]:
> yes

Archiving... 100%

Archival complete!
  Machines archived: 1,234
  Events archived: 45,678
  Original size: 125 MB
  Compressed size: 18 MB
  Savings: 85%
```

### Dry Run Output

```
php artisan machine:archive-events --dry-run

DRY RUN - No changes will be made

Found 1,234 machines eligible for archival:
  - order: 456 machines
  - payment: 389 machines
  - fulfillment: 389 machines

Estimated compression:
  Original: ~125 MB
  Compressed: ~18 MB (85% savings)
```

## machine:archive-status

View archival status and manage archives.

### Usage

```bash
# Show overall statistics
php artisan machine:archive-status

# Show details for specific machine type
php artisan machine:archive-status --machine-id=order

# Restore archived events
php artisan machine:archive-status --restore=01HXYZ...

# Clean up archive entry
php artisan machine:archive-status --cleanup-archive=01HXYZ...
```

### Options

| Option | Description |
|--------|-------------|
| `--machine-id=ID` | Filter by machine ID |
| `--restore=ID` | Restore specific archive |
| `--cleanup-archive=ID` | Delete specific archive |

### Statistics Output

```
Archive Statistics
==================

Total Archives: 5,678
Total Events Archived: 234,567

Storage:
  Original Size: 1.2 GB
  Compressed Size: 180 MB
  Savings: 85%

By Machine Type:
  order: 2,345 archives (95,678 events)
  payment: 1,876 archives (78,234 events)
  fulfillment: 1,457 archives (60,655 events)

Recent Activity:
  Last 24h: 123 archives created
  Last 7d: 456 archives created

Restore Activity:
  Total restores: 234
  Last restore: 2 hours ago
```

### Specific Machine Output

```
php artisan machine:archive-status --machine-id=01HXYZ...

Archive Details
===============

Root Event ID: 01HXYZ...
Machine ID: order
Event Count: 156
Original Size: 45 KB
Compressed Size: 7 KB
Compression Ratio: 15.6%

Archived At: 2024-01-15 10:30:00
Restore Count: 2
Last Restored: 2024-02-01 14:22:00

Events:
  First Event: 2023-06-01 09:00:00
  Last Event: 2023-12-15 16:45:00
```

### Restore Operation

```
php artisan machine:archive-status --restore=01HXYZ...

Restoring archive 01HXYZ...

  Machine ID: order
  Event Count: 156
  Compressed Size: 7 KB

Decompressing... done
Inserting events... done

Restore complete!
  Events restored: 156
  Time taken: 0.5s

Note: Archive entry retained for future reference.
```

## Scheduling Commands

Add commands to your scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Archive events daily at midnight
    $schedule->command('machine:archive-events --force')
        ->daily()
        ->at('00:00')
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/archive.log'));

    // Or use queue
    $schedule->command('machine:archive-events --force --queue')
        ->daily()
        ->at('00:00');

    // Weekly validation check
    $schedule->command('machine:validate --all')
        ->weekly()
        ->mondays()
        ->at('06:00')
        ->emailOutputOnFailure('admin@example.com');
}
```

## Custom Commands

Create custom commands for your machines:

```php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Machines\OrderMachine;
use Tarfinlabs\EventMachine\Models\MachineEvent;

class OrderMachineStatsCommand extends Command
{
    protected $signature = 'orders:stats';
    protected $description = 'Show order machine statistics';

    public function handle(): void
    {
        $stats = MachineEvent::where('machine_id', 'order')
            ->selectRaw('
                COUNT(DISTINCT root_event_id) as machines,
                COUNT(*) as events,
                MIN(created_at) as first_event,
                MAX(created_at) as last_event
            ')
            ->first();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Machines', $stats->machines],
                ['Total Events', $stats->events],
                ['First Event', $stats->first_event],
                ['Last Event', $stats->last_event],
            ]
        );
    }
}
```
