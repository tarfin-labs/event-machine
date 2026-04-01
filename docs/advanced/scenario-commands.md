# Scaffold & Validation

The `machine:scenario` and `machine:scenario-validate` artisan commands for generating and validating scenarios.

## Scaffold Command

```bash
php artisan machine:scenario
    {name}       # Scenario class name (Scenario suffix auto-added)
    {machine}    # Machine class FQCN
    {source}     # Source state route (full or partial)
    {event}      # Triggering event (class FQCN or event type string)
    {target}     # Target state route (full or partial)
    {--dry-run}  # Print without writing
    {--force}    # Overwrite existing file
    {--path=0}   # Select path by index when multiple paths exist
```

**Example:**

```bash
php artisan machine:scenario AtAllocation OrderMachine \
    pending SubmitOrderEvent allocation
```

The command:
1. Resolves the path from source to target via BFS
2. Classifies each intermediate state (transient, delegation, interactive, parallel)
3. Generates appropriate `plan()` entries with TODO comments
4. Writes the file to `Scenarios/` next to the machine class

**Options:**
- `--dry-run` — prints generated PHP to stdout without writing the file
- `--force` — overwrites an existing scenario file (without it, the command fails if the file exists)
- `--path=N` — when multiple paths exist from source to target, selects path by index (default: 0). The command lists all paths with signatures and stats when multiple are found.

### Multiple Paths

When BFS finds multiple paths, the command presents them:

```
Found 2 paths from pending to allocation:

  [0] pending → eligibility_check → payment_verification → under_review → allocation
      3 overrides, 2 delegation outcomes, 1 @continue

  [1] pending → eligibility_check → manual_review → allocation
      2 overrides, 0 delegation outcomes, 0 @continue

Use --path=N to select. Using path [0].
```

### Deep Target (Cross-Delegation)

When the target is inside a child machine, use `{region}.{childState}` syntax:

```bash
php artisan machine:scenario AtPaymentDateCorrection OrderMachine \
    pending SubmitOrderEvent payment.awaiting_date_correction
```

The command resolves the delegation boundary, discovers matching child scenarios, and references them in the parent's `plan()`. If no child scenario exists, it suggests the command to create one.

### Generated Output

The scaffolder generates classification-specific entries:

| Classification | Generated entry |
|---------------|----------------|
| **Transient** | `'route' => [Guard::class => false]` with `// TODO: adjust` |
| **Delegation** | `'route' => '@done'` with `// Available: @done.X, @fail, @timeout` |
| **Parallel** | Region outcomes + `@done` guard override |
| **Interactive** | `'route' => ['@continue' => Event::class]` with `// Also: OtherEvent` |

**Full generated file example:**

<!-- doctest-attr: ignore -->
```php
<?php

declare(strict_types=1);

namespace App\Machines\Order\Scenarios;

use App\Machines\Order\OrderMachine;
use App\Machines\Order\Events\SubmitOrderEvent;
use App\Machines\Order\Guards\IsBlacklistedGuard;
use Tarfinlabs\EventMachine\Scenarios\MachineScenario;

class AtAllocationScenario extends MachineScenario
{
    protected string $machine     = OrderMachine::class;
    protected string $source      = 'pending';
    protected string $event       = SubmitOrderEvent::class;
    protected string $target      = 'allocation';
    protected string $description = 'TODO: describe this scenario';

    protected function plan(): array
    {
        return [
            // ── eligibility_check ── @always, guards: [IsBlacklistedGuard]
            'eligibility_check' => [
                IsBlacklistedGuard::class => false, // TODO: adjust
            ],

            // ── payment_processing ── delegation: PaymentJob
            'payment_processing' => '@done', // Available: @done, @fail, @timeout

            // ── under_review ── interactive, @continue to reach target
            'under_review' => [
                '@continue' => 'ReviewApprovedEvent', // Also: ReviewRejectedEvent
            ],
        ];
    }
}
```

When a `@continue` event has `EventBehavior::rules()`, payload fields are extracted:

<!-- doctest-attr: ignore -->
```php
'awaiting_report_request' => [
    '@continue' => [ReportRequestedEvent::class, 'payload' => [
        'phone'   => '', // TODO: required (string)
        'queryId' => '', // TODO: required (string)
    ]],
],
```

## Validation Command

```bash
php artisan machine:scenario-validate
    {machine?}        # Specific machine FQCN (optional — auto-discovers all if omitted)
    {--scenario=}     # Filter: slug, class basename, or FQCN
```

When `{machine}` is omitted, the command auto-discovers all Machine subclasses that have a `Scenarios/` directory (via Composer classmap, falls back to `app/Machines` file scan). Ensure autoload is up to date with `composer dump-autoload` if newly added machines aren't found.

**Filter by single scenario:**

```bash
# By slug
php artisan machine:scenario-validate App\\Machines\\Order\\OrderMachine --scenario=at-review-scenario

# By class basename
php artisan machine:scenario-validate App\\Machines\\Order\\OrderMachine --scenario=AtReviewScenario

# By FQCN
php artisan machine:scenario-validate --scenario=App\\Machines\\Order\\Scenarios\\AtReviewScenario
```

### What It Validates

**Level 1 — Static validation:**

| Check | Example error |
|-------|---------------|
| `$machine` class exists | `Machine class not found: OrderMachine` |
| `$source` exists in machine | `Source state 'awaiting_start' not found` |
| `$target` exists in machine | `Target state 'allocation' not found` |
| `$target` is not transient | `Target 'eligibility_check' is transient (@always)` |
| `$event` valid from `$source` | `Event not available from source` |
| All `plan()` routes exist | `State route 'eligibilty_check' not found` |
| Behavior classes exist | `Guard class 'IsBlacklistedGard' not found` |
| Delegation outcomes on delegation states only | `Has outcome '@done' but is not a delegation state` |
| `@continue` on non-delegation states only | `Has @continue but is a delegation state` |
| Child scenario machine matches delegation | `AtAwaitingOtpScenario targets PaymentMachine but delegates to IdentityCheckMachine` |

**Level 2 — Path validation:**

| Check | Example error |
|-------|---------------|
| Path exists from source to target | `No path from 'idle' to 'allocation' via 'StartEvent'` |
| `@continue` events lead toward target | Directional check |
| Deep target child scenario exists | `No scenario found for PaymentMachine targeting 'awaiting_otp'` |

### Output

```
Validating scenarios...

OrderMachine (5 scenarios)
  ✓ AtPaymentVerificationScenario         pending → payment_verification
  ✓ AtReviewScenario                      pending → under_review
  ✗ AtAllocationScenario                  pending → allocation
    State route 'under_reviews' not found in machine definition
  ✓ AtRejectedScenario                    under_review → rejected

4 passed, 1 failed
```

Exit code 0 = all valid, exit code 1 = failures found.

### CI/CD Integration

```bash
php artisan machine:scenario-validate --ansi
```

Add to your quality gate or CI pipeline to catch broken scenarios before they reach staging.
