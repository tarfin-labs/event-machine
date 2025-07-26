# Calculator Machine Example

This example demonstrates a simple calculator state machine that performs basic arithmetic operations. It showcases context management, actions, and event handling.

## Overview

Our calculator machine will:
- Start in a "ready" state
- Accept arithmetic operations (ADD, SUB, MUL, DIV)
- Maintain a running result in the context
- Handle division by zero errors
- Provide a clear/reset function

## Machine Definition

```php
<?php

namespace App\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class CalculatorMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'ready',
                'context' => [
                    'result' => 0,
                    'lastOperation' => null,
                    'lastValue' => null,
                    'error' => null
                ],
                'states' => [
                    'ready' => [
                        'on' => [
                            'ADD' => [
                                'actions' => 'performAddition'
                            ],
                            'SUB' => [
                                'actions' => 'performSubtraction'
                            ],
                            'MUL' => [
                                'actions' => 'performMultiplication'
                            ],
                            'DIV' => [
                                [
                                    'guards' => 'isNotDivisionByZero',
                                    'actions' => 'performDivision'
                                ],
                                [
                                    'target' => 'error',
                                    'actions' => 'setDivisionByZeroError'
                                ]
                            ],
                            'CLEAR' => [
                                'actions' => 'clearCalculator'
                            ]
                        ]
                    ],
                    'error' => [
                        'on' => [
                            'CLEAR' => [
                                'target' => 'ready',
                                'actions' => 'clearCalculator'
                            ]
                        ]
                    ]
                ]
            ],
            behavior: [
                'actions' => [
                    'performAddition' => function (ContextManager $context, EventDefinition $event): void {
                        $value = $event->payload['value'] ?? 0;
                        $context->result = $context->result + $value;
                        $context->lastOperation = 'ADD';
                        $context->lastValue = $value;
                        $context->error = null;
                    },
                    
                    'performSubtraction' => function (ContextManager $context, EventDefinition $event): void {
                        $value = $event->payload['value'] ?? 0;
                        $context->result = $context->result - $value;
                        $context->lastOperation = 'SUB';
                        $context->lastValue = $value;
                        $context->error = null;
                    },
                    
                    'performMultiplication' => function (ContextManager $context, EventDefinition $event): void {
                        $value = $event->payload['value'] ?? 1;
                        $context->result = $context->result * $value;
                        $context->lastOperation = 'MUL';
                        $context->lastValue = $value;
                        $context->error = null;
                    },
                    
                    'performDivision' => function (ContextManager $context, EventDefinition $event): void {
                        $value = $event->payload['value'] ?? 1;
                        $context->result = $context->result / $value;
                        $context->lastOperation = 'DIV';
                        $context->lastValue = $value;
                        $context->error = null;
                    },
                    
                    'setDivisionByZeroError' => function (ContextManager $context): void {
                        $context->error = 'Division by zero is not allowed';
                    },
                    
                    'clearCalculator' => function (ContextManager $context): void {
                        $context->result = 0;
                        $context->lastOperation = null;
                        $context->lastValue = null;
                        $context->error = null;
                    }
                ],
                
                'guards' => [
                    'isNotDivisionByZero' => function (ContextManager $context, EventDefinition $event): bool {
                        $value = $event->payload['value'] ?? 0;
                        return $value !== 0;
                    }
                ]
            ]
        );
    }
}
```

## Enhanced Version with Context Class

For better type safety and organization, let's create a dedicated context class:

```php
<?php

namespace App\Contexts;

use Tarfinlabs\EventMachine\ContextManager;
use Spatie\LaravelData\Optional;

class CalculatorContext extends ContextManager
{
    public function __construct(
        public float|Optional $result,
        public string|Optional $lastOperation,
        public float|Optional $lastValue,
        public string|Optional $error,
        public array|Optional $history
    ) {
        parent::__construct();
        
        if ($this->result instanceof Optional) {
            $this->result = 0.0;
        }
        if ($this->history instanceof Optional) {
            $this->history = [];
        }
    }

    // Helper methods
    public function hasError(): bool
    {
        return !empty($this->error);
    }

    public function getLastCalculation(): ?string
    {
        if (!$this->lastOperation || $this->lastValue === null) {
            return null;
        }

        $operation = match($this->lastOperation) {
            'ADD' => '+',
            'SUB' => '-',
            'MUL' => '×',
            'DIV' => '÷',
            default => '?'
        };

        return "{$this->lastValue} {$operation}";
    }

    public function addToHistory(string $operation, float $value, float $result): void
    {
        $this->history[] = [
            'operation' => $operation,
            'value' => $value,
            'result' => $result,
            'timestamp' => now()->toISOString()
        ];

        // Keep only last 10 operations
        if (count($this->history) > 10) {
            $this->history = array_slice($this->history, -10);
        }
    }
}
```

## Enhanced Machine with Action Classes

```php
<?php

namespace App\Actions;

use App\Contexts\CalculatorContext;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;

class PerformAdditionAction extends ActionBehavior
{
    public function __invoke(CalculatorContext $context, EventDefinition $event): void
    {
        $value = $event->payload['value'] ?? 0;
        $previousResult = $context->result;
        
        $context->result += $value;
        $context->lastOperation = 'ADD';
        $context->lastValue = $value;
        $context->error = null;
        
        $context->addToHistory('ADD', $value, $context->result);
        
        Log::info('Calculator addition performed', [
            'previous_result' => $previousResult,
            'value_added' => $value,
            'new_result' => $context->result
        ]);
    }
}

class PerformDivisionAction extends ActionBehavior
{
    public function __invoke(CalculatorContext $context, EventDefinition $event): void
    {
        $value = $event->payload['value'] ?? 1;
        $previousResult = $context->result;
        
        $context->result /= $value;
        $context->lastOperation = 'DIV';
        $context->lastValue = $value;
        $context->error = null;
        
        $context->addToHistory('DIV', $value, $context->result);
        
        Log::info('Calculator division performed', [
            'previous_result' => $previousResult,
            'divisor' => $value,
            'new_result' => $context->result
        ]);
    }
}

class SetErrorAction extends ActionBehavior
{
    public function __invoke(CalculatorContext $context, EventDefinition $event): void
    {
        $errorMessage = $event->payload['error'] ?? 'An error occurred';
        $context->error = $errorMessage;
        
        Log::warning('Calculator error', [
            'error' => $errorMessage,
            'last_operation' => $context->lastOperation,
            'last_value' => $context->lastValue
        ]);
    }
}
```

## Usage Examples

### Basic Calculations

```php
// Create a new calculator
$calculator = CalculatorMachine::create();
echo $calculator->state->context['result']; // 0

// Perform calculations
$calculator = $calculator->send('ADD', ['value' => 10]);
echo $calculator->state->context['result']; // 10

$calculator = $calculator->send('MUL', ['value' => 5]);
echo $calculator->state->context['result']; // 50

$calculator = $calculator->send('SUB', ['value' => 20]);
echo $calculator->state->context['result']; // 30

$calculator = $calculator->send('DIV', ['value' => 3]);
echo $calculator->state->context['result']; // 10
```

### Error Handling

```php
$calculator = CalculatorMachine::create();
$calculator = $calculator->send('ADD', ['value' => 100]);

// Try to divide by zero
$calculator = $calculator->send('DIV', ['value' => 0]);
echo $calculator->state->value; // 'error'
echo $calculator->state->context['error']; // 'Division by zero is not allowed'

// Clear the error
$calculator = $calculator->send('CLEAR');
echo $calculator->state->value; // 'ready'
echo $calculator->state->context['result']; // 0
```

### With Enhanced Context

```php
$calculator = CalculatorMachine::create([
    'result' => 0,
    'history' => []
]);

$calculator = $calculator->send('ADD', ['value' => 15]);
$calculator = $calculator->send('MUL', ['value' => 2]);

$context = $calculator->state->context;
echo $context->result; // 30
echo $context->getLastCalculation(); // '2 ×'
echo count($context->history); // 2
```

## Laravel Integration

### Controller Usage

```php
<?php

namespace App\Http\Controllers;

use App\Machines\CalculatorMachine;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CalculatorController extends Controller
{
    public function create(): JsonResponse
    {
        $calculator = CalculatorMachine::create();
        
        // Store in session or database
        session(['calculator_id' => $calculator->id]);
        
        return response()->json([
            'calculator_id' => $calculator->id,
            'result' => $calculator->state->context->result,
            'state' => $calculator->state->value
        ]);
    }

    public function calculate(Request $request): JsonResponse
    {
        $request->validate([
            'operation' => 'required|in:ADD,SUB,MUL,DIV,CLEAR',
            'value' => 'nullable|numeric'
        ]);

        $calculatorId = session('calculator_id');
        $calculator = CalculatorMachine::find($calculatorId);

        if (!$calculator) {
            return response()->json(['error' => 'Calculator not found'], 404);
        }

        try {
            $calculator = $calculator->send($request->operation, [
                'value' => $request->value
            ]);

            return response()->json([
                'result' => $calculator->state->context->result,
                'state' => $calculator->state->value,
                'error' => $calculator->state->context->error,
                'last_operation' => $calculator->state->context->getLastCalculation()
            ]);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Calculation failed',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function history(): JsonResponse
    {
        $calculatorId = session('calculator_id');
        $calculator = CalculatorMachine::find($calculatorId);

        if (!$calculator) {
            return response()->json(['error' => 'Calculator not found'], 404);
        }

        return response()->json([
            'history' => $calculator->state->context->history,
            'current_result' => $calculator->state->context->result
        ]);
    }
}
```

### API Routes

```php
// routes/api.php
Route::prefix('calculator')->group(function () {
    Route::post('/', [CalculatorController::class, 'create']);
    Route::post('/calculate', [CalculatorController::class, 'calculate']);
    Route::get('/history', [CalculatorController::class, 'history']);
});
```

## Advanced Features

### Precision Handling

```php
class PrecisionCalculatorAction extends ActionBehavior
{
    public function __invoke(CalculatorContext $context, EventDefinition $event): void
    {
        $value = $event->payload['value'] ?? 0;
        $precision = $event->payload['precision'] ?? 2;
        
        // Use BC Math for precise calculations
        $context->result = round(
            bcadd((string)$context->result, (string)$value, $precision + 2),
            $precision
        );
        
        $context->lastOperation = 'ADD';
        $context->lastValue = $value;
    }
}
```

### Memory Functions

```php
'states' => [
    'ready' => [
        'on' => [
            // ... existing operations
            'MEMORY_STORE' => [
                'actions' => 'storeInMemory'
            ],
            'MEMORY_RECALL' => [
                'actions' => 'recallFromMemory'
            ],
            'MEMORY_CLEAR' => [
                'actions' => 'clearMemory'
            ]
        ]
    ]
]
```

### Scientific Functions

```php
'on' => [
    'SIN' => ['actions' => 'calculateSine'],
    'COS' => ['actions' => 'calculateCosine'],
    'TAN' => ['actions' => 'calculateTangent'],
    'SQRT' => [
        'guards' => 'isNotNegative',
        'actions' => 'calculateSquareRoot'
    ],
    'POW' => ['actions' => 'calculatePower']
]
```

## Testing

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Machines\CalculatorMachine;

class CalculatorMachineTest extends TestCase
{
    public function test_calculator_starts_with_zero_result()
    {
        $calculator = CalculatorMachine::create();
        
        $this->assertEquals('ready', $calculator->state->value);
        $this->assertEquals(0, $calculator->state->context->result);
    }

    public function test_addition_works_correctly()
    {
        $calculator = CalculatorMachine::create();
        
        $calculator = $calculator->send('ADD', ['value' => 10]);
        $this->assertEquals(10, $calculator->state->context->result);
        
        $calculator = $calculator->send('ADD', ['value' => 5]);
        $this->assertEquals(15, $calculator->state->context->result);
    }

    public function test_division_by_zero_creates_error_state()
    {
        $calculator = CalculatorMachine::create();
        $calculator = $calculator->send('ADD', ['value' => 10]);
        
        $calculator = $calculator->send('DIV', ['value' => 0]);
        
        $this->assertEquals('error', $calculator->state->value);
        $this->assertNotNull($calculator->state->context->error);
        $this->assertStringContains('Division by zero', $calculator->state->context->error);
    }

    public function test_clear_resets_calculator()
    {
        $calculator = CalculatorMachine::create();
        $calculator = $calculator->send('ADD', ['value' => 100]);
        $calculator = $calculator->send('MUL', ['value' => 2]);
        
        $calculator = $calculator->send('CLEAR');
        
        $this->assertEquals('ready', $calculator->state->value);
        $this->assertEquals(0, $calculator->state->context->result);
        $this->assertNull($calculator->state->context->lastOperation);
    }

    public function test_calculation_history_is_maintained()
    {
        $calculator = CalculatorMachine::create(['history' => []]);
        
        $calculator = $calculator->send('ADD', ['value' => 10]);
        $calculator = $calculator->send('MUL', ['value' => 2]);
        
        $history = $calculator->state->context->history;
        
        $this->assertCount(2, $history);
        $this->assertEquals('ADD', $history[0]['operation']);
        $this->assertEquals(10, $history[0]['value']);
        $this->assertEquals('MUL', $history[1]['operation']);
        $this->assertEquals(2, $history[1]['value']);
    }
}
```

## Key Takeaways

This calculator example demonstrates:

1. **Simple State Management**: Two states (ready/error) with clear transitions
2. **Context Usage**: Storing calculation results and operation history
3. **Guard Implementation**: Preventing division by zero
4. **Action Organization**: Both inline functions and dedicated action classes
5. **Error Handling**: Graceful error states and recovery
6. **Laravel Integration**: Controllers, validation, and session management
7. **Testing Strategies**: Comprehensive test coverage for all scenarios

The calculator pattern can be extended for more complex computational workflows, scientific calculators, or any scenario where you need to maintain state through a series of operations.