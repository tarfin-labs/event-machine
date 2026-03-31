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
- Typed contract declarations (`input` and `failure` config keys reference valid `MachineInput` and `MachineFailure` subclasses)
- `MachineOutput` classes on final states are valid subclasses

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

## machine:xstate

Export machine definition to XState v5 JSON for visualization in [Stately Studio](https://stately.ai).

### Usage

```bash
php artisan machine:xstate "App\Machines\OrderMachine"
```

Maps states, transitions, guards, actions, and delegation (`machine` key → XState `invoke` blocks).

## machine:process-timers

Sweep command for time-based events (`after`/`every` on transitions). Auto-registered via `MachineServiceProvider` — runs on schedule, no manual setup needed.

### Usage

```bash
# Process timers for a specific machine class
php artisan machine:process-timers --class="App\Machines\OrderMachine"
```

### How It Works

1. Discovers machine classes with timer-configured transitions
2. Queries `machine_current_states` for instances past deadline
3. Inserts `machine_timer_fires` records (atomic dedup via `insertOrIgnore`)
4. Dispatches `SendToMachineJob` via `Bus::batch`

### Configuration

```php ignore
// config/machine.php
'timers' => [
    'resolution'              => 'everyMinute',
    'batch_size'              => 100,
    'backpressure_threshold'  => 10000,
],
```

## machine:process-scheduled

Processes a scheduled event for machine instances. Called by `MachineScheduler` via Laravel Scheduler — not typically run manually.

### Usage

```bash
php artisan machine:process-scheduled --class="App\Machines\OrderMachine" --event=CHECK_EXPIRY
```

### How It Works

1. Loads definition, finds resolver for the event
2. Resolver returns root_event_ids, cross-checked against `machine_current_states`
3. Null resolver auto-detects target states from idMap
4. Dispatches `SendToMachineJob` via `Bus::batch`

## machine:timer-status

Display timer status for machine instances — useful for debugging.

### Usage

```bash
php artisan machine:timer-status
```

Shows: root_event_id, machine class, state, entered_at, timer key, last fired, fire count, status.

## machine:paths

Enumerate all paths through a machine definition. Static analysis — no database needed.

```bash
# Console output
php artisan machine:paths "App\Machines\OrderMachine"

# JSON output for CI
php artisan machine:paths "App\Machines\OrderMachine" --json

# Increase path limit for large machines (default: 1000)
php artisan machine:paths "App\Machines\LargeMachine" --max-paths=5000
```

### What It Shows

- Machine stats: states, events, guards, actions, calculators, job actors, child machines, timers
- Child machine and job actor names with async/sync mode and queue info
- All terminal paths grouped by type: HAPPY, FAIL, TIMEOUT, LOOP, GUARD_BLOCK, DEAD_END
- Child machine/job class names on invoke state steps
- Parallel state per-region paths with combination count
- Guard and action details per path
- Unhandled child outcome warnings (child final states without parent @done.{state} routes)

### Example Output

```
OrderMachine — Path Analysis
════════════════════════════

  States: 4 (2 atomic, 2 final)
  Events: 1
  Guards: 0
  Actions: 1
  Job actors: 1
    processing → PaymentJob (queue: default)
  Child machines: 0
  Timers: 0
  Terminal paths: 2

HAPPY PATHS (→ completed): 1 path
──────────────────────────────────
  #1  → idle
      → [START] processing (PaymentJob)
      → [@done] completed
      Actions: capturePaymentAction

FAIL PATHS (→ failed): 1 path
──────────────────────────────
  #2  → idle
      → [START] processing (PaymentJob)
      → [@fail] failed
```

Child machine and job class names appear in parentheses after the invoke state (e.g., `processing (PaymentJob)`). The stats section lists each delegation with its async/sync mode and queue name.

If a child machine has final states that the parent doesn't handle via `@done.{state}` routing (and no catch-all `@done`), a warning is shown at the end:

```
⚠ UNHANDLED CHILD OUTCOMES:
  processing → PaymentChildMachine
    Child final states: approved, rejected, expired
    Parent handles: @done.approved
    Unhandled: rejected, expired
```

## machine:coverage

Report path coverage for a machine definition. Reads coverage data produced by tests.

```bash
# Run tests first to generate coverage data
composer test

# Then report coverage
php artisan machine:coverage "App\Machines\OrderMachine"

# JSON output
php artisan machine:coverage "App\Machines\OrderMachine" --json

# Fail CI if below threshold
php artisan machine:coverage "App\Machines\OrderMachine" --min=100

# Custom coverage file location
php artisan machine:coverage "App\Machines\OrderMachine" --from=path/to/coverage.json
```

### Coverage Matching

The command compares enumerated paths (static analysis) against observed paths (test runtime) using state-sequence matching. Enable tracking in tests with `PathCoverageTracker::enable()` and record paths via `TestMachine::assertFinished()`.

### Example Output

```
OrderMachine — Path Coverage
════════════════════════════

  Coverage: 1/2 paths (50.0%)

  ✓ #1  idle→[START]→processing→[@done]→completed
         Tested by: order_completes_successfully

  ✗ #2  idle→[START]→processing→[@fail]→failed

UNTESTED: 1 path
  → idle
  → [START] processing
  → [@fail] failed
```

## Scheduling Commands

Add commands to your scheduler:

```php ignore
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

```php ignore
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

::: tip Testing
For testing artisan commands like `machine:process-timers` and `machine:process-scheduled`, see [Recipes](/testing/recipes).
:::
```
