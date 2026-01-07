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
# Dispatch archival jobs to queue (default)
php artisan machine:archive-events

# Preview what would be dispatched
php artisan machine:archive-events --dry-run

# Run synchronously (testing only)
php artisan machine:archive-events --sync

# Custom dispatch limit per run
php artisan machine:archive-events --dispatch-limit=100
```

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Preview without changes |
| `--sync` | Run synchronously instead of queue |
| `--dispatch-limit=N` | Max workflows to dispatch per run (default: 50) |

### Example Output

```
Finding eligible machines for archival...

Configuration:
  Days inactive: 30
  Dispatch limit: 50

Dispatching archival jobs...
  Dispatched: 50 workflows to queue

Run again to dispatch the next batch.
```

### Dry Run Output

```
php artisan machine:archive-events --dry-run

DRY RUN - No jobs will be dispatched

Found 1,234 machines eligible for archival:
  - order: 456 machines
  - payment: 389 machines
  - fulfillment: 389 machines

Would dispatch: 50 jobs (dispatch_limit)
Remaining: 1,184 machines
```

## machine:archive-status

View archive summary and manage archived events.

### Usage

```bash
# Show summary
php artisan machine:archive-status

# Restore archived events
php artisan machine:archive-status --restore=01HXYZ...
```

### Options

| Option | Description |
|--------|-------------|
| `--restore=ID` | Restore events from archive |

### Output

```
Machine Events Archive Status

+----------+-----------+--------+--------+
|          | Instances | Events | Size   |
+----------+-----------+--------+--------+
| Active   | 1,234     | 56,789 | -      |
| Archived | 5,678     | 234,567| 180 MB |
+----------+-----------+--------+--------+

Compression: 85% saved (1.02 GB)
```

## Scheduling Commands

Add commands to your scheduler:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Fan-out archival: dispatches individual jobs per workflow
    $schedule->command('machine:archive-events')
        ->everyFiveMinutes()
        ->withoutOverlapping()
        ->onOneServer()
        ->runInBackground();

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
