# Your First Machine

In this tutorial, you'll build a complete traffic light state machine. By the end, you'll understand:

- How to define states and transitions
- How to add context (data)
- How to use actions for side effects
- How to use guards for conditional logic
- How to persist and restore state

## Step 1: The Basic Machine

A traffic light has three states: `green`, `yellow`, and `red`. Let's start simple:

<!-- doctest-attr: ignore -->
```php
use Tarfinlabs\EventMachine\Actor\Machine;

$light = Machine::create([
    'config' => [
        'id' => 'traffic_light',
        'initial' => 'green',
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => 'yellow',
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER' => 'red',
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER' => 'green',
                ],
            ],
        ],
    ],
]);
```

Test it:

<!-- doctest-attr: ignore -->
```php
// Start in green
$state = $light->state;
$state->matches('green'); // true

// Send TIMER event
$state = $light->send(['type' => 'TIMER']);
$state->matches('yellow'); // true

// Send another TIMER
$state = $light->send(['type' => 'TIMER']);
$state->matches('red'); // true

// And back to green
$state = $light->send(['type' => 'TIMER']);
$state->matches('green'); // true
```

## Step 2: Add Context

Let's track how many cycles the light has completed:

<!-- doctest-attr: ignore -->
```php
$light = Machine::create([
    'config' => [
        'id' => 'traffic_light',
        'initial' => 'green',
        'context' => [
            'cycles' => 0,
        ],
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => 'yellow',
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER' => 'red',
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'green',
                        'actions' => 'incrementCycles',
                    ],
                ],
            ],
        ],
    ],
    'behavior' => [
        'actions' => [
            'incrementCycles' => function ($context) {
                $context->set('cycles', $context->get('cycles') + 1);
            },
        ],
    ],
]);
```

Now every time the light goes from red to green, the cycle count increases:

<!-- doctest-attr: ignore -->
```php
// Complete one cycle: green -> yellow -> red -> green
$light->send(['type' => 'TIMER']); // yellow
$light->send(['type' => 'TIMER']); // red
$light->send(['type' => 'TIMER']); // green

$light->state->context->get('cycles'); // 1

// Complete another cycle
$light->send(['type' => 'TIMER']); // yellow
$light->send(['type' => 'TIMER']); // red
$light->send(['type' => 'TIMER']); // green

$light->state->context->get('cycles'); // 2
```

## Step 3: Add a Guard

Let's add a `POWER_SAVE` event that only works at night (after 10 PM):

<!-- doctest-attr: ignore -->
```php
$light = Machine::create([
    'config' => [
        'id' => 'traffic_light',
        'initial' => 'green',
        'context' => [
            'cycles' => 0,
        ],
        'states' => [
            'green' => [
                'on' => [
                    'TIMER' => 'yellow',
                    'POWER_SAVE' => [
                        'target' => 'flashing',
                        'guards' => 'isNightTime',
                    ],
                ],
            ],
            'yellow' => [
                'on' => [
                    'TIMER' => 'red',
                ],
            ],
            'red' => [
                'on' => [
                    'TIMER' => [
                        'target' => 'green',
                        'actions' => 'incrementCycles',
                    ],
                ],
            ],
            'flashing' => [
                'on' => [
                    'RESUME' => 'green',
                ],
            ],
        ],
    ],
    'behavior' => [
        'actions' => [
            'incrementCycles' => function ($context) {
                $context->set('cycles', $context->get('cycles') + 1);
            },
        ],
        'guards' => [
            'isNightTime' => function () {
                return now()->hour >= 22 || now()->hour < 6;
            },
        ],
    ],
]);
```

Now `POWER_SAVE` only works at night:

<!-- doctest-attr: ignore -->
```php
// During the day
$light->send(['type' => 'POWER_SAVE']);
$light->state->matches('green'); // true - guard blocked transition

// At night (mock the time in tests)
$light->send(['type' => 'POWER_SAVE']);
$light->state->matches('flashing'); // true - guard allowed transition
```

## Step 4: Convert to a Reusable Class

For production use, define machines as classes:

<!-- doctest-attr: ignore -->
```php
namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class TrafficLightMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id' => 'traffic_light',
                'initial' => 'green',
                'context' => [
                    'cycles' => 0,
                ],
                'states' => [
                    'green' => [
                        'on' => [
                            'TIMER' => 'yellow',
                            'POWER_SAVE' => [
                                'target' => 'flashing',
                                'guards' => 'isNightTime',
                            ],
                        ],
                    ],
                    'yellow' => [
                        'on' => [
                            'TIMER' => 'red',
                        ],
                    ],
                    'red' => [
                        'on' => [
                            'TIMER' => [
                                'target' => 'green',
                                'actions' => 'incrementCycles',
                            ],
                        ],
                    ],
                    'flashing' => [
                        'on' => [
                            'RESUME' => 'green',
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementCycles' => IncrementCyclesAction::class,
                ],
                'guards' => [
                    'isNightTime' => IsNightTimeGuard::class,
                ],
            ],
        );
    }
}
```

Create the action class:

<!-- doctest-attr: ignore -->
```php
namespace App\Machines\Actions;

use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\ContextManager;

class IncrementCyclesAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('cycles', $context->get('cycles') + 1);
    }
}
```

Create the guard class:

<!-- doctest-attr: ignore -->
```php
namespace App\Machines\Guards;

use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsNightTimeGuard extends GuardBehavior
{
    public function __invoke(): bool
    {
        return now()->hour >= 22 || now()->hour < 6;
    }
}
```

Use it:

<!-- doctest-attr: ignore -->
```php
$light = TrafficLightMachine::create();
$light->send(['type' => 'TIMER']);
```

## Step 5: Persistence and Restoration

Every event is automatically persisted:

<!-- doctest-attr: ignore -->
```php
$light = TrafficLightMachine::create();
$light->send(['type' => 'TIMER']); // yellow
$light->send(['type' => 'TIMER']); // red

// Get the root event ID (identifies this machine instance)
$rootEventId = $light->state->history->first()->root_event_id;

// Store this ID in your database, session, etc.
```

Later, restore the exact state:

<!-- doctest-attr: ignore -->
```php
// Restore from the root event ID
$restored = TrafficLightMachine::create(state: $rootEventId);

$restored->state->matches('red'); // true
$restored->state->context->get('cycles'); // 0

// Continue from where we left off
$restored->send(['type' => 'TIMER']); // green
$restored->state->context->get('cycles'); // 1
```

## Step 6: Integrate with Eloquent

Attach the machine to a model:

<!-- doctest-attr: ignore -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Traits\HasMachines;
use Tarfinlabs\EventMachine\Casts\MachineCast;
use App\Machines\TrafficLightMachine;

class Intersection extends Model
{
    use HasMachines;

    protected $casts = [
        'light' => MachineCast::class . ':' . TrafficLightMachine::class,
    ];
}
```

Now the machine is a property of the model:

<!-- doctest-attr: ignore -->
```php
$intersection = Intersection::create(['name' => 'Main & 5th']);

// Access the machine
$intersection->light->send(['type' => 'TIMER']);
$intersection->light->state->matches('yellow'); // true

// The machine state is automatically persisted
// and linked to this model
```

## What You've Learned

- **States** define distinct phases (`green`, `yellow`, `red`, `flashing`)
- **Events** trigger transitions (`TIMER`, `POWER_SAVE`, `RESUME`)
- **Context** holds data (`cycles`)
- **Actions** execute side effects (`incrementCycles`)
- **Guards** control transition flow (`isNightTime`)
- **Event sourcing** is automatic - every transition is persisted
- **Restoration** rebuilds exact state from event history