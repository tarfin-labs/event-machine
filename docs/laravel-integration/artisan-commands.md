# Artisan Commands

EventMachine provides several Artisan commands for managing state machines.

## machine:validate

Validate machine configuration and wiring. Exits non-zero on failure, so it can gate CI.

### Usage

```bash
# Validate a specific machine (by class basename or fully-qualified name)
php artisan machine:validate "App\Machines\OrderMachine"

# Validate every machine the command can discover
php artisan machine:validate --all
```

### What It Checks

**Configuration shape**

- Valid state configuration keys
- Final states without transitions
- Final states without children
- Required initial states for compound states
- Behavior references
- Typed contract declarations (`input` and `failure` config keys reference valid `MachineInput` and `MachineFailure` subclasses)
- `MachineOutput` classes on final states are valid subclasses

**Wiring**

- **Behavior-to-context compatibility.** A behavior whose `__invoke()` type-hints a context the machine does not declare would raise a `TypeError` the first time its transition fires — possibly a rare branch, weeks after deploy. This reports it before the branch is ever taken.
- **`$requiredContext` keys.** A key the machine's declared context class cannot supply is reported. The check errs toward silence: a key that might be satisfiable at runtime is left alone, because a false failure in a CI gate is worse than a missed one.
- **Event-type collisions.** Two event classes deriving the same type collapse to one entry in the machine's event registry, and that registry is what reconstructs persisted events — so payload validation can come from the wrong class. The finding names the class that currently owns the type.

### Exit Codes

| Code | Meaning |
|------|---------|
| `0` | Every validated machine produced no findings |
| `1` | A machine produced findings, was unresolvable, had no definition, or threw |
| `2` | Called with neither a machine argument nor `--all` |

`--all` reporting zero discovered machines is a failure, not a success: a sweep that validated nothing must not read as clean. The discovered count is printed on every run, but it is **informational only** — a shrinking count never fails on its own, so it cannot serve as a discovery-regression signal. A project that wants one names its machines explicitly.

::: warning What a passing run does not guarantee
Two limitations feed the same exit code, and neither makes the run fail:

- `--all` discovers only classes that **directly extend** `Machine`. A machine behind an intermediate base class is invisible to the sweep. Naming it explicitly still works — a named argument is resolved as a class first, so it is validated whether or not discovery found it.
- The `$requiredContext` check deliberately under-reports (see above).

A green exit means no finding was produced, not that the wiring is complete.
:::

### Example Output

```
$ php artisan machine:validate --all
Discovered 14 machine(s)
✓ Machine 'App\Machines\Findeks\FindeksMachine' configuration is valid.
✗ Machine 'App\Machines\Conversion\ConversionMachine' has 2 wiring problem(s):
  App\Machines\Shared\Actions\ApproveAction::__invoke() expects App\Machines\Application\ApplicationContext but machine App\Machines\Conversion\ConversionMachine declares context App\Machines\Conversion\ConversionContext.
  App\Machines\Shared\Actions\ApproveAction::$requiredContext['application'] is not a property of App\Machines\Conversion\ConversionContext (machine App\Machines\Conversion\ConversionMachine).

Validation complete: 12 valid, 2 failed
$ echo $?
1
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

## machine:scenario

Generate a `MachineScenario` class by analyzing the machine definition and resolving the path from source to target.

### Usage

```bash
# Generate scenario class
php artisan machine:scenario AtAllocation CarSalesMachine \
    awaiting_customer_start CustomerStartedEvent allocation

# Preview without writing
php artisan machine:scenario AtAllocation CarSalesMachine \
    awaiting_customer_start CustomerStartedEvent allocation --dry-run

# Overwrite existing file
php artisan machine:scenario AtAllocation CarSalesMachine \
    awaiting_customer_start CustomerStartedEvent allocation --force

# Select specific path when multiple exist
php artisan machine:scenario AtAllocation CarSalesMachine \
    awaiting_customer_start CustomerStartedEvent allocation --path=1
```

### Arguments

| Argument | Description |
|----------|-------------|
| `name` | Scenario class name (`Scenario` suffix auto-added if missing) |
| `machine` | Machine class FQCN |
| `source` | Source state route (full or partial) |
| `event` | Triggering event (class FQCN or event type string) |
| `target` | Target state route (full or partial, supports deep targets) |

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Print generated file to stdout without writing |
| `--force` | Overwrite existing scenario file |
| `--path=N` | Select path by index when multiple paths exist (default: 0) |

The command classifies each intermediate state (transient, delegation, interactive, parallel) and generates appropriate `plan()` entries with TODO comments. Supports deep targets (cross-delegation) with automatic child scenario discovery.

See [Scenarios — Scaffold Command](/advanced/scenarios#scaffold-command) for full details.

## machine:scenario-validate

Validate all scenarios against their machine definitions. Catches structural errors and broken paths without running machines.

### Usage

```bash
# Validate all scenarios for all machines
php artisan machine:scenario-validate

# Validate scenarios for a specific machine
php artisan machine:scenario-validate "App\Machines\CarSalesMachine"

# Validate a single scenario
php artisan machine:scenario-validate --scenario=AtCheckingProtocolScenario
```

### What It Checks

**Level 1 — Static validation:** machine class exists, source/target/event valid, plan() routes exist, behavior classes exist, delegation outcomes on correct states, child scenario machine matches.

**Level 2 — Path validation:** path exists from source to target, `@continue` events lead toward target, deep target child scenarios exist.

### Output

```
Validating scenarios...

CarSalesMachine (5 scenarios)
  ✓ AtVerificationScenario                idle → verification
  ✓ AtCheckingProtocolScenario            idle → checking_protocol
  ✗ AtAllocationScenario                  idle → allocation
    State route 'checking_protocols' not found in machine definition

4 passed, 1 failed
```

Exit code 0 = all valid, exit code 1 = failures found. Suitable for CI/CD pipelines.

See [Scenarios — Validation Command](/advanced/scenarios#validation-command) for full details.

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

::: warning
`machine:validate` only started returning a non-zero exit code in 9.16.0. Before that the
`emailOutputOnFailure` hook above could never fire. Adopting this schedule on an existing
project can start delivering mail immediately — validate locally first.
:::

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
