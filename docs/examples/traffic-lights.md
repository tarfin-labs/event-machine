# Traffic Lights State Machine

This example demonstrates a traffic light control system using EventMachine. It showcases hierarchical states, timers, parallel execution, and complex state transitions.

## Basic Traffic Light

Let's start with a simple traffic light that cycles through red, yellow, and green:

```php
<?php

namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class SimpleTrafficLightMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'red',
                'states' => [
                    'red' => [
                        'on' => [
                            'TIMER' => 'green'
                        ]
                    ],
                    'green' => [
                        'on' => [
                            'TIMER' => 'yellow'
                        ]
                    ],
                    'yellow' => [
                        'on' => [
                            'TIMER' => 'red'
                        ]
                    ]
                ]
            ]
        );
    }
}
```

### Usage

```php
$light = SimpleTrafficLightMachine::create();
echo $light->state->value; // 'red'

$light = $light->send('TIMER');
echo $light->state->value; // 'green'

$light = $light->send('TIMER');
echo $light->state->value; // 'yellow'

$light = $light->send('TIMER');
echo $light->state->value; // 'red'
```

## Advanced Traffic Light with Context

Let's create a more sophisticated version with timing, counters, and configuration:

```php
<?php

namespace App\Contexts;

use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Attributes\Validation\Min;

class TrafficLightContext extends ContextManager
{
    public function __construct(
        #[Min(0)]
        public int|Optional $cycleCount,
        #[Min(0)]
        public int|Optional $redDuration,
        #[Min(0)]
        public int|Optional $yellowDuration,
        #[Min(0)]
        public int|Optional $greenDuration,
        public array|Optional $schedule,
        public bool|Optional $maintenanceMode,
        public string|Optional $intersectionId,
        public ?DateTime|Optional $lastStateChange
    ) {
        parent::__construct();
        
        // Set defaults
        if ($this->cycleCount instanceof Optional) {
            $this->cycleCount = 0;
        }
        if ($this->redDuration instanceof Optional) {
            $this->redDuration = 30; // 30 seconds
        }
        if ($this->yellowDuration instanceof Optional) {
            $this->yellowDuration = 5; // 5 seconds
        }
        if ($this->greenDuration instanceof Optional) {
            $this->greenDuration = 25; // 25 seconds
        }
        if ($this->schedule instanceof Optional) {
            $this->schedule = [];
        }
        if ($this->maintenanceMode instanceof Optional) {
            $this->maintenanceMode = false;
        }
    }

    public function getCurrentDuration(string $state): int
    {
        return match($state) {
            'red' => $this->redDuration,
            'yellow' => $this->yellowDuration,
            'green' => $this->greenDuration,
            default => 30
        };
    }

    public function isRushHour(): bool
    {
        $hour = now()->hour;
        return ($hour >= 7 && $hour <= 9) || ($hour >= 17 && $hour <= 19);
    }

    public function shouldUseNightMode(): bool
    {
        $hour = now()->hour;
        return $hour >= 22 || $hour <= 6;
    }
}
```

## Complete Traffic Light Machine

```php
<?php

namespace App\Machines;

use App\Contexts\TrafficLightContext;
use App\Actions\TrafficLight\StartTimerAction;
use App\Actions\TrafficLight\IncrementCycleAction;
use App\Actions\TrafficLight\LogStateChangeAction;
use App\Guards\TrafficLight\IsMaintenanceModeGuard;
use App\Guards\TrafficLight\IsNightModeGuard;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class TrafficLightMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'operational',
                'context' => TrafficLightContext::class,
                'states' => [
                    'operational' => [
                        'initial' => 'red',
                        'entry' => 'logStateChange',
                        'states' => [
                            'red' => [
                                'entry' => [
                                    'startTimer',
                                    'logStateChange'
                                ],
                                'on' => [
                                    'TIMER_EXPIRED' => [
                                        [
                                            'target' => '#nightMode',
                                            'guards' => 'isNightMode'
                                        ],
                                        [
                                            'target' => 'green',
                                            'actions' => 'incrementCycle'
                                        ]
                                    ]
                                ]
                            ],
                            'green' => [
                                'entry' => [
                                    'startTimer',
                                    'logStateChange'
                                ],
                                'on' => [
                                    'TIMER_EXPIRED' => 'yellow',
                                    'PEDESTRIAN_REQUEST' => [
                                        'guards' => 'canGrantPedestrianCrossing',
                                        'actions' => 'schedulePedestrianCrossing'
                                    ]
                                ]
                            ],
                            'yellow' => [
                                'entry' => [
                                    'startTimer',
                                    'logStateChange'
                                ],
                                'on' => [
                                    'TIMER_EXPIRED' => 'red'
                                ]
                            ]
                        ],
                        'on' => [
                            'MAINTENANCE_MODE' => 'maintenance',
                            'EMERGENCY' => 'emergency'
                        ]
                    ],
                    'nightMode' => [
                        'id' => 'nightMode',
                        'entry' => 'startFlashing',
                        'on' => [
                            'DAY_MODE' => 'operational.red',
                            'MAINTENANCE_MODE' => 'maintenance'
                        ]
                    ],
                    'maintenance' => [
                        'entry' => 'enableMaintenanceMode',
                        'on' => [
                            'RESUME_OPERATION' => [
                                [
                                    'target' => 'nightMode',
                                    'guards' => 'isNightMode'
                                ],
                                {
                                    'target' => 'operational.red'
                                }
                            ]
                        ]
                    ],
                    'emergency' => [
                        'entry' => 'activateEmergencyMode',
                        'on' => [
                            'CLEAR_EMERGENCY' => 'operational.red'
                        ]
                    ]
                ]
            ],
            behavior: [
                'actions' => [
                    'startTimer' => StartTimerAction::class,
                    'incrementCycle' => IncrementCycleAction::class,
                    'logStateChange' => LogStateChangeAction::class,
                    'startFlashing' => function (TrafficLightContext $context): void {
                        Log::info('Traffic light entering night mode - flashing yellow', [
                            'intersection_id' => $context->intersectionId,
                            'time' => now()
                        ]);
                    },
                    'enableMaintenanceMode' => function (TrafficLightContext $context): void {
                        $context->maintenanceMode = true;
                        $context->lastStateChange = now();
                        
                        // Notify maintenance system
                        event(new TrafficLightMaintenanceActivated($context->intersectionId));
                    },
                    'activateEmergencyMode' => function (TrafficLightContext $context): void {
                        Log::emergency('Traffic light emergency mode activated', [
                            'intersection_id' => $context->intersectionId,
                            'time' => now()
                        ]);
                        
                        // Notify emergency services
                        event(new TrafficLightEmergencyActivated($context->intersectionId));
                    }
                ],
                'guards' => [
                    'isNightMode' => IsNightModeGuard::class,
                    'isMaintenanceMode' => IsMaintenanceModeGuard::class,
                    'canGrantPedestrianCrossing' => function (TrafficLightContext $context): bool {
                        // Allow pedestrian crossing if green light has been on for at least 10 seconds
                        return $context->lastStateChange && 
                               $context->lastStateChange->diffInSeconds(now()) >= 10;
                    }
                ]
            ]
        );
    }
}
```

## Action Implementations

```php
<?php

namespace App\Actions\TrafficLight;

use App\Contexts\TrafficLightContext;
use App\Jobs\TrafficLightTimerJob;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class StartTimerAction extends ActionBehavior
{
    public function __invoke(TrafficLightContext $context, $event, $state): void
    {
        $currentState = $state->key;
        $duration = $context->getCurrentDuration($currentState);
        
        // Adjust duration for rush hour
        if ($context->isRushHour()) {
            $duration = match($currentState) {
                'green' => $duration + 15, // Longer green during rush hour
                'red' => $duration + 10,   // Longer red for cross traffic
                default => $duration
            };
        }
        
        // Dispatch timer job
        TrafficLightTimerJob::dispatch($context->intersectionId, $duration)
            ->delay($duration);
            
        Log::info('Traffic light timer started', [
            'intersection_id' => $context->intersectionId,
            'state' => $currentState,
            'duration' => $duration,
            'rush_hour_adjustment' => $context->isRushHour()
        ]);
    }
}

class IncrementCycleAction extends ActionBehavior
{
    public function __invoke(TrafficLightContext $context): void
    {
        $context->cycleCount++;
        $context->lastStateChange = now();
        
        // Log cycle statistics every 100 cycles
        if ($context->cycleCount % 100 === 0) {
            Log::info('Traffic light cycle milestone', [
                'intersection_id' => $context->intersectionId,
                'total_cycles' => $context->cycleCount,
                'uptime_hours' => now()->diffInHours($context->lastStateChange)
            ]);
        }
    }
}

class LogStateChangeAction extends ActionBehavior
{
    public function __invoke(TrafficLightContext $context, $event, $state): void
    {
        $context->lastStateChange = now();
        
        Log::info('Traffic light state changed', [
            'intersection_id' => $context->intersectionId,
            'new_state' => $state->key,
            'event' => $event->type ?? 'entry',
            'cycle_count' => $context->cycleCount,
            'timestamp' => now()
        ]);
    }
}
```

## Guard Implementations

```php
<?php

namespace App\Guards\TrafficLight;

use App\Contexts\TrafficLightContext;
use Tarfinlabs\EventMachine\Behavior\GuardBehavior;

class IsNightModeGuard extends GuardBehavior
{
    public function __invoke(TrafficLightContext $context): bool
    {
        return $context->shouldUseNightMode();
    }
}

class IsMaintenanceModeGuard extends GuardBehavior
{
    public function __invoke(TrafficLightContext $context): bool
    {
        return $context->maintenanceMode;
    }
}
```

## Timer Job Implementation

```php
<?php

namespace App\Jobs;

use App\Machines\TrafficLightMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrafficLightTimerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $intersectionId,
        private int $duration
    ) {}

    public function handle(): void
    {
        try {
            $trafficLight = TrafficLightMachine::find($this->intersectionId);
            
            if ($trafficLight) {
                $trafficLight->send('TIMER_EXPIRED');
                
                Log::debug('Traffic light timer expired', [
                    'intersection_id' => $this->intersectionId,
                    'duration' => $this->duration
                ]);
            }
        } catch (Exception $e) {
            Log::error('Traffic light timer job failed', [
                'intersection_id' => $this->intersectionId,
                'error' => $e->getMessage()
            ]);
            
            // Retry the job
            $this->fail($e);
        }
    }
}
```

## Intersection with Multiple Traffic Lights

```php
<?php

namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class IntersectionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'normalOperation',
                'context' => [
                    'intersectionId' => null,
                    'trafficLights' => [],
                    'pedestrianSignals' => [],
                    'emergencyOverride' => false
                ],
                'states' => [
                    'normalOperation' => [
                        'type' => 'parallel',
                        'states' => [
                            'northSouth' => [
                                'initial' => 'red',
                                'states' => [
                                    'red' => [
                                        'entry' => 'startNSTimer',
                                        'on' => ['NS_TIMER_EXPIRED' => 'green']
                                    ],
                                    'green' => [
                                        'entry' => 'startNSTimer',
                                        'on' => ['NS_TIMER_EXPIRED' => 'yellow']
                                    ],
                                    'yellow' => [
                                        'entry' => 'startNSTimer',
                                        'on' => ['NS_TIMER_EXPIRED' => 'red']
                                    ]
                                ]
                            ],
                            'eastWest' => [
                                'initial' => 'green',
                                'states' => [
                                    'red' => [
                                        'entry' => 'startEWTimer',
                                        'on' => ['EW_TIMER_EXPIRED' => 'green']
                                    ],
                                    'green' => [
                                        'entry' => 'startEWTimer',
                                        'on' => ['EW_TIMER_EXPIRED' => 'yellow']
                                    ],
                                    'yellow' => [
                                        'entry' => 'startEWTimer',
                                        'on' => ['EW_TIMER_EXPIRED' => 'red']
                                    ]
                                ]
                            ]
                        ],
                        'on' => [
                            'EMERGENCY_OVERRIDE' => 'emergencyOperation'
                        ]
                    ],
                    'emergencyOperation' => [
                        'entry' => 'activateEmergencySequence',
                        'on' => [
                            'CLEAR_EMERGENCY' => 'normalOperation'
                        ]
                    ]
                ]
            ],
            behavior: [
                'actions' => [
                    'startNSTimer' => function ($context, $event, $state): void {
                        // Start timer for North-South lights
                        $duration = $this->getTimingForState('northSouth', $state->key);
                        TrafficLightTimerJob::dispatch("ns_{$context->intersectionId}", $duration)
                            ->delay($duration);
                    },
                    'startEWTimer' => function ($context, $event, $state): void {
                        // Start timer for East-West lights  
                        $duration = $this->getTimingForState('eastWest', $state->key);
                        TrafficLightTimerJob::dispatch("ew_{$context->intersectionId}", $duration)
                            ->delay($duration);
                    },
                    'activateEmergencySequence' => function ($context): void {
                        // All lights to red, then clear for emergency vehicles
                        Log::emergency('Intersection emergency override activated', [
                            'intersection_id' => $context->intersectionId
                        ]);
                        
                        $context->emergencyOverride = true;
                    }
                ]
            ]
        );
    }
}
```

## Laravel Integration Examples

### Controller

```php
<?php

namespace App\Http\Controllers;

use App\Machines\TrafficLightMachine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TrafficLightController extends Controller
{
    public function createIntersection(Request $request): JsonResponse
    {
        $request->validate([
            'intersection_id' => 'required|string|unique:traffic_lights',
            'red_duration' => 'integer|min:5|max:120',
            'yellow_duration' => 'integer|min:3|max:10', 
            'green_duration' => 'integer|min:5|max:120'
        ]);

        $trafficLight = TrafficLightMachine::create([
            'intersectionId' => $request->intersection_id,
            'redDuration' => $request->red_duration ?? 30,
            'yellowDuration' => $request->yellow_duration ?? 5,
            'greenDuration' => $request->green_duration ?? 25
        ]);

        return response()->json([
            'intersection_id' => $trafficLight->state->context->intersectionId,
            'current_state' => $trafficLight->state->value,
            'cycle_count' => $trafficLight->state->context->cycleCount
        ]);
    }

    public function getStatus(string $intersectionId): JsonResponse
    {
        $trafficLight = TrafficLightMachine::find($intersectionId);

        if (!$trafficLight) {
            return response()->json(['error' => 'Intersection not found'], 404);
        }

        return response()->json([
            'intersection_id' => $intersectionId,
            'current_state' => $trafficLight->state->value,
            'cycle_count' => $trafficLight->state->context->cycleCount,
            'maintenance_mode' => $trafficLight->state->context->maintenanceMode,
            'last_state_change' => $trafficLight->state->context->lastStateChange
        ]);
    }

    public function triggerMaintenance(string $intersectionId): JsonResponse
    {
        $trafficLight = TrafficLightMachine::find($intersectionId);

        if (!$trafficLight) {
            return response()->json(['error' => 'Intersection not found'], 404);
        }

        $trafficLight = $trafficLight->send('MAINTENANCE_MODE');

        return response()->json([
            'message' => 'Maintenance mode activated',
            'current_state' => $trafficLight->state->value
        ]);
    }

    public function triggerEmergency(string $intersectionId): JsonResponse
    {
        $trafficLight = TrafficLightMachine::find($intersectionId);

        if (!$trafficLight) {
            return response()->json(['error' => 'Intersection not found'], 404);
        }

        $trafficLight = $trafficLight->send('EMERGENCY');

        return response()->json([
            'message' => 'Emergency mode activated',
            'current_state' => $trafficLight->state->value
        ]);
    }
}
```

### Command for Managing Traffic Lights

```php
<?php

namespace App\Console\Commands;

use App\Machines\TrafficLightMachine;
use Illuminate\Console\Command;

class TrafficLightCommand extends Command
{
    protected $signature = 'traffic-light {action} {intersection_id?}';
    protected $description = 'Manage traffic light intersections';

    public function handle(): int
    {
        $action = $this->argument('action');
        $intersectionId = $this->argument('intersection_id');

        return match($action) {
            'list' => $this->listIntersections(),
            'status' => $this->showStatus($intersectionId),
            'maintenance' => $this->enableMaintenance($intersectionId),
            'resume' => $this->resumeOperation($intersectionId),
            default => $this->error('Unknown action')
        };
    }

    private function listIntersections(): int
    {
        // This would typically query a database of traffic light machines
        $this->info('Active Traffic Light Intersections:');
        $this->table(['ID', 'State', 'Cycles', 'Last Change'], [
            // Sample data - in real app, query from storage
        ]);
        
        return 0;
    }

    private function showStatus(?string $intersectionId): int
    {
        if (!$intersectionId) {
            $this->error('Intersection ID required for status command');
            return 1;
        }

        $trafficLight = TrafficLightMachine::find($intersectionId);
        
        if (!$trafficLight) {
            $this->error("Intersection {$intersectionId} not found");
            return 1;
        }

        $context = $trafficLight->state->context;
        
        $this->info("Traffic Light Status for {$intersectionId}:");
        $this->line("Current State: {$trafficLight->state->value}");
        $this->line("Cycle Count: {$context->cycleCount}");
        $this->line("Maintenance Mode: " . ($context->maintenanceMode ? 'YES' : 'NO'));
        $this->line("Last State Change: {$context->lastStateChange}");
        
        return 0;
    }
}
```

## Testing

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Machines\TrafficLightMachine;
use Illuminate\Support\Facades\Queue;

class TrafficLightMachineTest extends TestCase
{
    public function test_traffic_light_starts_in_red_state()
    {
        $light = TrafficLightMachine::create(['intersectionId' => 'test-001']);
        
        $this->assertEquals('operational.red', $light->state->value);
        $this->assertEquals(0, $light->state->context->cycleCount);
    }

    public function test_traffic_light_cycles_correctly()
    {
        $light = TrafficLightMachine::create(['intersectionId' => 'test-001']);
        
        // Red -> Green
        $light = $light->send('TIMER_EXPIRED');
        $this->assertEquals('operational.green', $light->state->value);
        $this->assertEquals(1, $light->state->context->cycleCount);
        
        // Green -> Yellow
        $light = $light->send('TIMER_EXPIRED');
        $this->assertEquals('operational.yellow', $light->state->value);
        
        // Yellow -> Red
        $light = $light->send('TIMER_EXPIRED');
        $this->assertEquals('operational.red', $light->state->value);
    }

    public function test_maintenance_mode_can_be_activated()
    {
        $light = TrafficLightMachine::create(['intersectionId' => 'test-001']);
        
        $light = $light->send('MAINTENANCE_MODE');
        
        $this->assertEquals('maintenance', $light->state->value);
        $this->assertTrue($light->state->context->maintenanceMode);
    }

    public function test_night_mode_activation()
    {
        // Mock night time
        $this->travelTo(now()->setHour(2));
        
        $light = TrafficLightMachine::create(['intersectionId' => 'test-001']);
        $light = $light->send('TIMER_EXPIRED');
        
        $this->assertEquals('nightMode', $light->state->value);
    }

    public function test_timer_jobs_are_dispatched()
    {
        Queue::fake();
        
        $light = TrafficLightMachine::create(['intersectionId' => 'test-001']);
        
        // Timer should be started on state entry
        Queue::assertPushed(TrafficLightTimerJob::class);
    }
}
```

## Key Concepts Demonstrated

This traffic light example showcases:

1. **Hierarchical States**: Operational state contains red/green/yellow substates
2. **Parallel States**: Multiple traffic lights operating simultaneously  
3. **Timed Transitions**: Using Laravel jobs for timer-based state changes
4. **Context-Driven Logic**: Rush hour adjustments and night mode
5. **Guard Conditions**: Preventing certain transitions based on context
6. **Entry/Exit Actions**: Automatic actions when entering/leaving states
7. **Emergency Handling**: Override mechanisms for emergency situations
8. **Laravel Integration**: Controllers, commands, jobs, and events
9. **Real-world Complexity**: Maintenance modes, logging, and error handling

The traffic light pattern is excellent for any time-based system with cyclical behavior, such as:
- Manufacturing process control
- Automated task scheduling  
- System monitoring and alerting
- Game state management
- Workflow automation