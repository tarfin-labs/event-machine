# Quick Start

Get up and running with EventMachine in just a few minutes!

## Your First State Machine

Let's create a simple light switch state machine to understand the basic concepts:

```php
<?php

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class LightSwitchMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'off',
                'states' => [
                    'off' => [
                        'on' => [
                            'TURN_ON' => 'on'
                        ]
                    ],
                    'on' => [
                        'on' => [
                            'TURN_OFF' => 'off'
                        ]
                    ]
                ]
            ]
        );
    }
}
```

## Using Your State Machine

### 1. Create a Machine Instance

```php
// Create a new machine instance
$lightSwitch = LightSwitchMachine::create();

// Check the initial state
echo $lightSwitch->state->value; // 'off'
```

### 2. Send Events to Change State

```php
// Turn the light on
$lightSwitch = $lightSwitch->send('TURN_ON');
echo $lightSwitch->state->value; // 'on'

// Turn the light off
$lightSwitch = $lightSwitch->send('TURN_OFF');
echo $lightSwitch->state->value; // 'off'
```

### 3. Persist to Database

```php
// Create a machine with a unique ID for persistence
$lightSwitch = LightSwitchMachine::create([
    'id' => 'kitchen-light'
]);

// Send an event - this automatically persists to database
$lightSwitch = $lightSwitch->send('TURN_ON');

// Later, restore from database
$restored = LightSwitchMachine::find('kitchen-light');
echo $restored->state->value; // 'on'
```

## Adding Context and Actions

Let's enhance our machine with context (data) and actions (side effects):

```php
<?php

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class SmartLightMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'off',
                'context' => [
                    'brightness' => 0,
                    'turnOnCount' => 0
                ],
                'states' => [
                    'off' => [
                        'on' => [
                            'TURN_ON' => [
                                'target' => 'on',
                                'actions' => 'setBrightness'
                            ]
                        ]
                    ],
                    'on' => [
                        'on' => [
                            'TURN_OFF' => [
                                'target' => 'off',
                                'actions' => 'turnOff'
                            ],
                            'DIM' => [
                                'actions' => 'adjustBrightness'
                            ]
                        ]
                    ]
                ]
            ],
            behavior: [
                'actions' => [
                    'setBrightness' => function (ContextManager $context): void {
                        $context->brightness = 100;
                        $context->turnOnCount = $context->turnOnCount + 1;
                    },
                    'turnOff' => function (ContextManager $context): void {
                        $context->brightness = 0;
                    },
                    'adjustBrightness' => function (ContextManager $context, $event): void {
                        $context->brightness = $event->payload['level'];
                    }
                ]
            ]
        );
    }
}
```

### Using the Enhanced Machine

```php
// Create the machine
$smartLight = SmartLightMachine::create();

// Turn on the light
$smartLight = $smartLight->send('TURN_ON');
echo $smartLight->state->context['brightness']; // 100
echo $smartLight->state->context['turnOnCount']; // 1

// Dim the light
$smartLight = $smartLight->send('DIM', ['level' => 50]);
echo $smartLight->state->context['brightness']; // 50

// Turn off
$smartLight = $smartLight->send('TURN_OFF');
echo $smartLight->state->context['brightness']; // 0
```

## Using with Eloquent Models

EventMachine integrates seamlessly with Laravel models using the `MachineCast`:

```php
<?php

use Illuminate\Database\Eloquent\Model;
use Tarfinlabs\EventMachine\Casts\MachineCast;

class Room extends Model
{
    protected $casts = [
        'light_state' => MachineCast::class.':'.SmartLightMachine::class
    ];
}
```

```php
// Create a room with a light
$room = Room::create([
    'name' => 'Kitchen',
    'light_state' => SmartLightMachine::create()
]);

// Control the light
$room->light_state = $room->light_state->send('TURN_ON');
$room->save();

// Later retrieval automatically restores the machine
$room = Room::find(1);
echo $room->light_state->state->value; // 'on'
```

## Common Patterns

### 1. Guards (Conditions)

Add conditions that must be met for transitions:

```php
'on' => [
    'TURN_ON' => [
        'target' => 'on',
        'guards' => 'hasElectricity',
        'actions' => 'setBrightness'
    ]
]

// In behavior:
'guards' => [
    'hasElectricity' => function (ContextManager $context): bool {
        return $context->powerAvailable === true;
    }
]
```

### 2. Multiple Actions

Execute multiple actions in sequence:

```php
'TURN_ON' => [
    'target' => 'on',
    'actions' => [
        'logTurnOn',
        'setBrightness', 
        'notifySmartHome'
    ]
]
```

### 3. Event Validation

Define and validate event payloads:

```php
// In behavior section:
'events' => [
    'DIM' => DimEvent::class
]
```

```php
<?php

use Tarfinlabs\EventMachine\Behavior\EventBehavior;

class DimEvent extends EventBehavior
{
    public function validatePayload(): array
    {
        return [
            'level' => 'required|integer|min:0|max:100'
        ];
    }
}
```

## Next Steps

Now that you've seen the basics, dive deeper:

- [Your First State Machine](./first-machine.md) - Detailed walkthrough
- [States and Transitions](../concepts/states-and-transitions.md) - Core concepts
- [Machine Definition](../guides/machine-definition.md) - Complete configuration options
- [Examples](../examples/) - Real-world use cases