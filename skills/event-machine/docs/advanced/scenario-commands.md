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
    {--max-iterations=1000} # Cap the path search (truncation is reported, not silent)
```

**Example:**

```bash
php artisan machine:scenario AtAllocation OrderMachine \
    pending SubmitOrderEvent allocation
```

The command:
1. Resolves the path from source to target, cheapest route first
2. Classifies each intermediate state (transient, delegation, interactive, parallel)
3. Generates appropriate `plan()` entries with TODO comments
4. Writes the file to `Scenarios/` next to the machine class

**Options:**
- `--dry-run` — prints generated PHP to stdout without writing the file
- `--force` — overwrites an existing scenario file (without it, the command fails if the file exists)
- `--path=N` — when multiple paths exist from source to target, selects path by index (default: 0). The command lists every resolved path with its signature and stats, including when only one is found. **`N` re-indexed in this release**: paths are now listed cheapest first rather than in the order the search happened to find them, so a pinned `--path=3` selects a different route than it did before. Capture the listing before upgrading and re-match by signature.
- `--max-iterations=N` — caps the path search (default: 1000). **This is now one budget for the whole resolution rather than one per branch of the trigger event**, so a multi-branch trigger has less headroom than before: multiply a pinned value by the branch count. If the search stops with work still pending, the command always says so: **"truncated at the search limit" is a different finding from "no path exists"**, and only the first is fixable by raising this number. With no path found it fails; with a path found it warns and continues, because the route list — and therefore `--path=N` — may be missing routes. A genuinely unconnected source and target fail no matter how high it goes.

### Multiple Paths

When several routes reach the target, the command lists them **cheapest first**:

```
Found 2 paths from pending to allocation:

  [0] [SUBMIT]→eligibility_check→[MANUAL]→manual_review→[APPROVE]→allocation
      0 overrides, 0 delegation outcomes, 2 @continue, weight 2

  [1] [SUBMIT]→eligibility_check→[VERIFY]→payment_verification→[@done]→allocation
      0 overrides, 1 delegation outcomes, 1 @continue, weight 6

Use --path=N to select. Using path [0].
```

The signature names every state the route **enters** and the event that enters it. The source state
is not in it — a path begins at the trigger transition's target — so `[SUBMIT]→eligibility_check` is
the first hop out of `pending`, not `pending` itself.

The counts and the weight are two views of the same steps, so they agree by construction: `overrides`
counts transient states, `delegation outcomes` counts delegation *and* parallel states, `@continue`
counts interactive ones, and compound and final states appear in neither. Route `[0]` is two
interactive states and a final one — 1 + 1 + 0. Route `[1]` trades one of them for a parallel state:
1 + 5 + 0.

The **weight** is what the list is ordered by. It is the sum of a per-classification cost over the
states the route enters — 0 for transient, compound and final states, 1 for interactive, 3 for
delegation, 5 for parallel — and it measures *what the scenario has to supply that the runtime
cannot*: an event to send, a child outcome to stand in for, guards to pin across concurrent regions.

It is **not** a count of the lines the scaffold will contain. A transient state emits a guard block
and still weighs 0, because the machine leaves it on its own; a delegation state emits one pre-filled
line and weighs 3, because standing in for a child is the expensive part and none of it shows up in
the generated file.

Routes of equal weight are listed shorter first. Routes equal on **both** weight and length have **no
promised order** — it is stable for a given machine but nothing guarantees which comes first, so
`--path=N` is not a durable selector within such a group. Pick by reading the signature, not the index.

A resolution with one route prints the same listing, minus the selection hint:

```
Found 1 path from pending to allocation:

  [0] [SUBMIT]→eligibility_check→[APPROVE]→allocation
      0 overrides, 0 delegation outcomes, 1 @continue, weight 1
```

The weight is printed here for the same reason it is printed for two: it is the only number the
command reports about the route it chose, and comparing one run against the next needs it.

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
| **Interactive (target)** | `continuation()` stub generated with TODO entries for post-target states |

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

    protected function continuation(): array
    {
        // TODO: define Phase 2 overrides for subsequent requests after reaching target.
        // Example: 'delegating' => '@done', 'polling' => [IsPinRequiredGuard::class => false]
        return [];
    }
}
```

The `continuation()` stub is only generated when the **target state is interactive** — meaning QA will interact with the machine after reaching the target, and subsequent requests may need overrides. When the target is a final or transient state, no `continuation()` stub is generated. See [Continuation — Multi-Request Flows](/advanced/scenario-plan#continuation-multi-request-flows) for how to fill in the stub.

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
    {machine?}              # Specific machine FQCN (optional — auto-discovers all if omitted)
    {--scenario=}           # Filter: slug, class basename, or FQCN
    {--max-iterations=1000} # Cap the path search behind each scenario's reachability check
```

**Options:**
- `--scenario=` — validate a single scenario by slug, class basename, or FQCN.
- `--max-iterations=N` — the search budget handed to the resolver for every scenario's path check (default: 1000), counted in expansions across the whole resolution rather than per branch of the trigger. A scenario whose search hits the cap is reported as **truncated at the search limit**, which is a different finding from "no path": raising this number can resolve the first and never the second. Validated before anything is reported, so a typo fails the command rather than silently capping the search at zero.

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

**Level 1b — Continuation validation:**

| Check | Example error |
|-------|---------------|
| `continuation()` state routes exist in machine | `Continuation state 'confirming_pins' not found` |
| Continuation behavior classes exist | `Guard class 'IsPinRequiredGard' not found` |
| Continuation delegation outcomes on delegation states only | `Continuation has '@done' on 'awaiting_pin' which is not a delegation state` |

**Level 2 — Path validation:**

| Check | Example error |
|-------|---------------|
| Path exists from source to target | `No path from 'idle' to 'allocation' via 'StartEvent'` |
| Path search completed | `Path analysis from 'idle' to 'allocation' via 'StartEvent' was truncated at the search limit — a path may still exist` |
| `@continue` events lead toward target | Directional check |
| Deep target child scenario exists | `No scenario found for PaymentMachine targeting 'awaiting_otp'` |

The first two are different findings and must not be read the same way. "No path" means the search
was exhausted and the states really are not connected by transitions. "Truncated" means the search
stopped at its own limit with work still pending, so whether a path exists is unknown — treat it as
a prompt to look, not as an answer.

`machine:scenario-validate` accepts `--max-iterations` (default 1000) to raise that limit, and
`ScenarioValidator` takes the same budget as a constructor argument. Both outcomes still exit with a
failure, so nothing downstream needs to change how it reads the exit code.

Targets inside a parallel region resolve normally: entering a parallel state activates every region,
so each region's initial state is reachable and the resolved route marks that step `@region` to
distinguish it from `@entry`, which is exclusive descent into a compound state. A route through one
region is a projection of a concurrent configuration — its sibling regions are simultaneously active
in unspecified states.

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
