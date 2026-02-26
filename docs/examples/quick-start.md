# Quick Start Examples

Simple examples you can copy and adapt.

## Calculator

A single-state machine with inline actions and event payloads.

```php no_run
<?php

namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class CalculatorMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'ready',
                'context' => [
                    'result' => 0,
                ],
                'states' => [
                    'ready' => [
                        'on' => [
                            'ADD' => ['actions' => 'additionAction'],
                            'SUB' => ['actions' => 'subtractionAction'],
                            'MUL' => ['actions' => 'multiplicationAction'],
                            'DIV' => [
                                'guards'  => 'notDivideByZero',
                                'actions' => 'divisionAction',
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'guards' => [
                    'notDivideByZero' => fn(ContextManager $c, EventDefinition $e): bool
                        => $e->payload['value'] !== 0,
                ],
                'actions' => [
                    'additionAction' => fn(ContextManager $c, EventDefinition $e)
                        => $c->result += $e->payload['value'],
                    'subtractionAction' => fn(ContextManager $c, EventDefinition $e)
                        => $c->result -= $e->payload['value'],
                    'multiplicationAction' => fn(ContextManager $c, EventDefinition $e)
                        => $c->result *= $e->payload['value'],
                    'divisionAction' => fn(ContextManager $c, EventDefinition $e)
                        => $c->result /= $e->payload['value'],
                ],
            ],
        );
    }
}
```

### Usage

```php no_run
$machine = CalculatorMachine::create();

$machine->send(['type' => 'ADD', 'payload' => ['value' => 10]]);
$machine->send(['type' => 'MUL', 'payload' => ['value' => 3]]);
$machine->send(['type' => 'SUB', 'payload' => ['value' => 5]]);

expect($machine->state->context->result)->toBe(25);
```

---

## Traffic Light Counter

A counter with typed context, validation guards, and event classes.

### Context Class

```php no_run
<?php

namespace App\Machines\TrafficLights;

use Spatie\LaravelData\Optional;
use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\IntegerType;

class TrafficLightsContext extends ContextManager
{
    public function __construct(
        #[IntegerType]
        #[Min(0)]
        public int|Optional $count,
    ) {
        parent::__construct();
        if ($this->count instanceof Optional) {
            $this->count = 0;
        }
    }
}
```

### Machine Definition

```php no_run
<?php

namespace App\Machines\TrafficLights;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class IsEvenGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Count is not even';

    public function __invoke(TrafficLightsContext $context): bool
    {
        return $context->count % 2 === 0;
    }
}

class IncrementAction extends ActionBehavior
{
    public function __invoke(TrafficLightsContext $context): void
    {
        $context->count++;
    }
}

class MultiplyByTwoAction extends ActionBehavior
{
    public function __invoke(TrafficLightsContext $context): void
    {
        $context->count *= 2;
    }
}

class TrafficLightsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'active',
                'context' => TrafficLightsContext::class,
                'states'  => [
                    'active' => [
                        'on' => [
                            'INC' => ['actions' => IncrementAction::class],
                            'MUT' => [
                                'guards'  => IsEvenGuard::class,
                                'actions' => MultiplyByTwoAction::class,
                            ],
                        ],
                    ],
                ],
            ],
        );
    }
}
```

### Usage

```php no_run
$machine = TrafficLightsMachine::create();

$machine->send(['type' => 'INC']); // count = 1
$machine->send(['type' => 'INC']); // count = 2
$machine->send(['type' => 'MUT']); // count = 4 (2 is even, multiply passes)

expect($machine->state->context->count)->toBe(4);

// Guard blocks when count is odd
$machine->send(['type' => 'INC']); // count = 5
expect(fn() => $machine->send(['type' => 'MUT']))
    ->toThrow(MachineValidationException::class);
```
