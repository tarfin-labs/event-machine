<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Enums\BehaviorType;
use Tarfinlabs\EventMachine\Testing\TestMachine;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\EventDefinition;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Behavior\ValidationGuardBehavior;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\FormatOutput;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\SetLevelAction;
use Tarfinlabs\EventMachine\Exceptions\MachineValidationException;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAmountInRangeGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\NamedParamsMachine;
use Tarfinlabs\EventMachine\Tests\Stubs\Guards\IsAboveThresholdGuard;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\AddValueByParamAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\MultiplyByParamAction;
use Tarfinlabs\EventMachine\Exceptions\MissingBehaviorParameterException;
use Tarfinlabs\EventMachine\Exceptions\InvalidBehaviorDefinitionException;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\NamedParamsAlwaysMachine;

// ─── Test-local classes ──────────────────────────────────────────────────────

class ContextOnlyTestOutput extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): array
    {
        return ['total' => $ctx->get('total')];
    }
}

class MinAmountValidationGuard extends ValidationGuardBehavior
{
    public ?string $errorMessage = 'Amount below minimum';

    public function __invoke(ContextManager $ctx, int $minimum): bool
    {
        return $ctx->get('amount') >= $minimum;
    }
}

// ═══════════════════════════════════════════════════════════════
//  §A — Parsing: tuple detection and extraction (Tests 1–10)
// ═══════════════════════════════════════════════════════════════

describe('§A — Parsing', function (): void {

    // Test 1: Single parameterized behavior (inner array)
    it('resolves a single parameterized guard from inner array tuple', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 500],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [[IsAmountInRangeGuard::class, 'min' => 100, 'max' => 1000]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 2: Single parameterized inline key
    it('resolves a parameterized inline key tuple', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 500],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [['rangeGuard', 'min' => 100, 'max' => 1000]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'rangeGuard' => IsAmountInRangeGuard::class,
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 3: Multiple parameterized behaviors
    it('resolves multiple parameterized guards with their respective params', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 75],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [
                                    [IsAmountInRangeGuard::class, 'min' => 10, 'max' => 200],
                                    [IsAboveThresholdGuard::class, 'threshold' => 50],
                                ],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 4: Mixed parameterized + parameterless
    it('handles mixed parameterized and parameterless behaviors in same list', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['total' => 100],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'APPLY' => [
                                'actions' => [
                                    [AddValueByParamAction::class, 'value' => 50],
                                    [MultiplyByParamAction::class, 'factor' => 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'APPLY']);

        // 100 + 50 = 150, then 150 * 2 = 300
        expect($state->context->get('total'))->toBe(300);
    });

    // Test 6: Mixed parameterized + inline key (parameterless)
    it('handles tuple and inline key coexisting in same behavior list', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['total' => 100, 'level' => null],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'APPLY' => [
                                'actions' => [
                                    [SetLevelAction::class, 'level' => 'info'],
                                    'incrementAction',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementAction' => function (ContextManager $ctx): void {
                        $ctx->set('total', $ctx->get('total') + 1);
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'APPLY']);

        expect($state->context->get('level'))->toBe('info');
        expect($state->context->get('total'))->toBe(101);
    });

    // Test 7: Parameterless behaviors (unchanged — regression)
    it('continues to work with parameterless behaviors', function (): void {
        // Single class string
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 75],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => 'aboveGuard',
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'aboveGuard' => fn (ContextManager $ctx): bool => $ctx->get('amount') > 50,
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();

        // Inline key string
        $machine2 = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 75],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => 'inlineGuard',
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'inlineGuard' => fn (ContextManager $ctx): bool => $ctx->get('amount') > 50,
                ],
            ],
        );

        $state2 = $machine2->transition(event: ['type' => 'CHECK']);

        expect($state2->matches('passed'))->toBeTrue();
    });

    // Test 8: Invalid tuple without class at [0]
    it('throws InvalidBehaviorDefinitionException for tuple without class at [0]', function (): void {
        MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'guards' => [['min' => 100, 'max' => 200]],
                            ],
                        ],
                    ],
                ],
            ],
        );
    })->throws(InvalidBehaviorDefinitionException::class);

    // Test 9: Invalid empty tuple
    it('throws InvalidBehaviorDefinitionException for empty tuple', function (): void {
        MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'guards' => [[]],
                            ],
                        ],
                    ],
                ],
            ],
        );
    })->throws(InvalidBehaviorDefinitionException::class);

    // Test 10: Invalid closure in tuple
    it('throws InvalidBehaviorDefinitionException for closure in tuple', function (): void {
        MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => [],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'actions' => [[fn (ContextManager $ctx, int $min) => $ctx->set('x', $min), 'min' => 100]],
                            ],
                        ],
                    ],
                ],
            ],
        );
    })->throws(InvalidBehaviorDefinitionException::class);
});

// ═══════════════════════════════════════════════════════════════
//  §B — Injection: parameter resolution in __invoke (Tests 11–19)
// ═══════════════════════════════════════════════════════════════

describe('§B — Injection', function (): void {

    // Test 11: Named params injected by name
    it('injects named params by name into __invoke', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 500],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [[IsAmountInRangeGuard::class, 'min' => 100, 'max' => 10000]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 12: Framework types + named params coexist
    it('injects framework types alongside named params', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 75],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [['thresholdGuard', 'threshold' => 50]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'thresholdGuard' => function (ContextManager $ctx, EventDefinition $event, int $threshold): bool {
                        return $ctx->get('amount') > $threshold;
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 13: Default values used when param not in config
    it('uses default values when param is not in config', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 500],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [['rangeGuard', 'min' => 100]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'rangeGuard' => function (ContextManager $ctx, int $min, int $max = 99999): bool {
                        return $ctx->get('amount') >= $min && $ctx->get('amount') <= $max;
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 15: Missing required param throws MissingBehaviorParameterException
    it('throws MissingBehaviorParameterException when required param is missing', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 500],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [[IsAmountInRangeGuard::class, 'min' => 100]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
        );

        $machine->transition(event: ['type' => 'CHECK']);
    })->throws(MissingBehaviorParameterException::class);

    // Test 16: Extra config params silently ignored
    it('silently ignores extra config params not in __invoke signature', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['total' => 100],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'ADD' => [
                                'actions' => [[AddValueByParamAction::class, 'value' => 25, 'extra' => 'ignored', 'another' => 999]],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'ADD']);

        expect($state->context->get('total'))->toBe(125);
    });

    // Test 17: Type coercion — string into int throws TypeError under strict_types=1
    it('throws TypeError when string value is injected into int parameter in strict mode', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 500],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [[IsAboveThresholdGuard::class, 'threshold' => '100']],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
        );

        $machine->transition(event: ['type' => 'CHECK']);
    })->throws(TypeError::class);

    // Test 18: Complex value types — array param
    it('injects array param value into __invoke', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['result' => null],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'DO' => [
                                'actions' => [['arrayAction', 'channels' => ['sms', 'email']]],
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'arrayAction' => function (ContextManager $ctx, array $channels): void {
                        $ctx->set('result', implode(',', $channels));
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'DO']);

        expect($state->context->get('result'))->toBe('sms,email');
    });

    // Test 19: Parameter order independence
    it('matches params by name regardless of __invoke parameter order', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 50],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [['reverseGuard', 'min' => 1, 'max' => 100]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    // Note: max comes before ContextManager, min comes after — order doesn't matter
                    'reverseGuard' => function (int $max, ContextManager $ctx, int $min): bool {
                        return $ctx->get('amount') >= $min && $ctx->get('amount') <= $max;
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════════
//  §C — All behavior types (Tests 20–33)
// ═══════════════════════════════════════════════════════════════

describe('§C — All behavior types', function (): void {

    // Test 20: Guard with named params — passes
    it('guard with named params passes and transition proceeds', function (): void {
        $test = NamedParamsMachine::test();

        $test->context()->set('amount', 500);
        $test->send('CHECK_RANGE');

        $test->assertState('in_range');
    });

    // Test 21: Guard with named params — fails
    it('guard with named params fails and transition is blocked', function (): void {
        $test = NamedParamsMachine::test();

        $test->context()->set('amount', 5); // below min=10
        $test->send('CHECK_RANGE');

        $test->assertState('idle'); // stays in idle
    });

    // Test 22: ValidationGuard with named params
    it('validation guard with named params throws MachineValidationException', function (): void {
        $definition = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 5],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'VALIDATE' => [
                                'target' => 'validated',
                                'guards' => [[MinAmountValidationGuard::class, 'minimum' => 10]],
                            ],
                        ],
                    ],
                    'validated' => ['type' => 'final'],
                ],
            ],
        );

        $m = Machine::withDefinition($definition);

        expect(fn () => $m->send(['type' => 'VALIDATE']))
            ->toThrow(MachineValidationException::class);
    });

    // Test 23: Action with named params
    it('action with named params modifies context using injected param values', function (): void {
        $test = NamedParamsMachine::test();

        $test->send('ADD_VALUE');

        $test->assertContext('total', 125); // 100 + 25
    });

    // Test 24: Calculator with named params
    it('calculator with named params pre-computes value before guard evaluates', function (): void {
        $test = NamedParamsMachine::test();

        // amount must be > 0 for the guard (IsAboveThresholdGuard threshold=0) to pass
        $test->context()->set('amount', 1);
        $test->send('APPLY_DISCOUNT');

        // total = 100 - (100 * 0.15) = 85, guard threshold=0: amount(1) > 0 passes
        $test->assertState('discounted');
        $test->assertContext('total', 85.0);
    });

    // Test 25: Entry action with named params
    it('entry action with named params runs on state entry', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['level' => null],
                'states'  => [
                    'idle' => [
                        'on' => ['GO' => 'active'],
                    ],
                    'active' => [
                        'entry' => [[SetLevelAction::class, 'level' => 'warning']],
                    ],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'GO']);

        expect($state->context->get('level'))->toBe('warning');
    });

    // Test 26: Exit action with named params
    it('exit action with named params runs on state exit', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['level' => null],
                'states'  => [
                    'idle' => [
                        'exit' => [[SetLevelAction::class, 'level' => 'exiting']],
                        'on'   => ['GO' => 'active'],
                    ],
                    'active' => [],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'GO']);

        expect($state->context->get('level'))->toBe('exiting');
    });

    // Test 27: Output behavior with named params (final state — state config output)
    it('output behavior with named params on final state via state config', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['total' => 42],
                'states'  => [
                    'idle'      => ['on' => ['FINISH' => 'completed']],
                    'completed' => [
                        'type'   => 'final',
                        'output' => [[FormatOutput::class, 'format' => 'json']],
                    ],
                ],
            ],
        );

        $m = Machine::withDefinition($machine);
        $m->send(['type' => 'FINISH']);
        $output = $m->output();

        expect($output)->toBe(['format' => 'json', 'total' => 42]);
    });

    // Test 28: Output behavior with named params (state-level config)
    it('output behavior with named params on state-level output config', function (): void {
        $test = TestMachine::define([
            'initial' => 'active',
            'context' => ['total' => 77],
            'states'  => [
                'active' => [
                    'output' => [[FormatOutput::class, 'format' => 'xml']],
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]);

        expect($test->machine()->output())->toBe(['format' => 'xml', 'total' => 77]);
    });

    // Test 29: Output hierarchical resolution with named params
    it('output walks up hierarchy to find parent output with named params', function (): void {
        $test = TestMachine::define([
            'initial' => 'parent',
            'context' => ['total' => 55],
            'states'  => [
                'parent' => [
                    'initial' => 'child_a',
                    'output'  => [[FormatOutput::class, 'format' => 'csv']],
                    'states'  => [
                        'child_a' => ['on' => ['NEXT' => 'child_b']],
                        'child_b' => [],
                    ],
                ],
            ],
        ]);

        // child_a has no output → parent's output applies
        expect($test->machine()->output())->toBe(['format' => 'csv', 'total' => 55]);
    });

    // Test 30: Output array filter still works (regression)
    it('output as array of strings still returns context key filter', function (): void {
        $test = TestMachine::define([
            'initial' => 'active',
            'context' => ['orderId' => 'ORD-1', 'total' => 200, 'internal' => 'hidden'],
            'states'  => [
                'active' => [
                    'output' => ['orderId', 'total'],
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]);

        expect($test->machine()->output())->toBe(['orderId' => 'ORD-1', 'total' => 200]);
    });

    // Test 31: Output — filter and parameterized in same machine (disambiguation)
    it('disambiguates filter and parameterized output in same machine', function (): void {
        $test = TestMachine::define([
            'initial' => 'state_a',
            'context' => ['orderId' => 'ORD-1', 'total' => 200],
            'states'  => [
                'state_a' => [
                    'output' => ['orderId', 'total'],
                    'on'     => ['NEXT' => 'state_b'],
                ],
                'state_b' => [
                    'output' => [[FormatOutput::class, 'format' => 'json']],
                ],
            ],
        ]);

        // state_a uses filter
        expect($test->machine()->output())->toBe(['orderId' => 'ORD-1', 'total' => 200]);

        $test->send('NEXT');

        // state_b uses parameterized output behavior
        expect($test->machine()->output())->toBe(['format' => 'json', 'total' => 200]);
    });

    // Test 33: Multiple actions in transition — mixed params
    it('multiple actions in transition each receive correct params', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['total' => 100, 'level' => null],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'APPLY' => [
                                'actions' => [
                                    [AddValueByParamAction::class, 'value' => 10],
                                    [SetLevelAction::class, 'level' => 'debug'],
                                    [MultiplyByParamAction::class, 'factor' => 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'APPLY']);

        // 100 + 10 = 110, then set level, then 110 * 2 = 220
        expect($state->context->get('total'))->toBe(220);
        expect($state->context->get('level'))->toBe('debug');
    });
});

// ═══════════════════════════════════════════════════════════════
//  §J — Edge cases (Tests 79–89)
// ═══════════════════════════════════════════════════════════════

describe('§J — Edge cases', function (): void {

    // Test 79: Config param with null value
    it('passes null value to named param via array_key_exists', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['result' => 'initial'],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'DO' => [
                                'actions' => [['nullAction', 'value' => null]],
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'nullAction' => function (ContextManager $ctx, ?string $value): void {
                        $ctx->set('result', $value === null ? 'was_null' : $value);
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'DO']);

        expect($state->context->get('result'))->toBe('was_null');
    });

    // Test 80: Config param with false value
    it('passes false value to named param', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['result' => 'initial'],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'DO' => [
                                'actions' => [['toggleAction', 'enabled' => false]],
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'toggleAction' => function (ContextManager $ctx, bool $enabled): void {
                        $ctx->set('result', $enabled ? 'on' : 'off');
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'DO']);

        expect($state->context->get('result'))->toBe('off');
    });

    // Test 81: Same inline key, different params on different transitions
    it('same inline key reused with different params per transition', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'a',
                'context' => ['amount' => 75],
                'states'  => [
                    'a' => [
                        'on' => [
                            'X' => [
                                'target' => 'passed_a',
                                'guards' => [['rangeGuard', 'min' => 0, 'max' => 100]],
                            ],
                        ],
                    ],
                    'passed_a' => [
                        'on' => [
                            'Y' => [
                                'target' => 'passed_b',
                                'guards' => [['rangeGuard', 'min' => 50, 'max' => 80]],
                            ],
                        ],
                    ],
                    'passed_b' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'rangeGuard' => IsAmountInRangeGuard::class,
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'X']);
        expect($state->matches('passed_a'))->toBeTrue();

        $state = $machine->transition(event: ['type' => 'Y'], state: $state);
        expect($state->matches('passed_b'))->toBeTrue();
    });

    // Test 82: Parameterless tuple — class only, no string keys
    it('parameterless tuple with class only is equivalent to bare class', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 75],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [['aboveGuard']],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'aboveGuard' => fn (ContextManager $ctx): bool => $ctx->get('amount') > 50,
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });

    // Test 83: Output inner-array with parameterless tuple — class that needs no named params
    it('output inner-array with class needing no named params works as parameterless tuple', function (): void {
        $test = TestMachine::define([
            'initial' => 'active',
            'context' => ['total' => 99],
            'states'  => [
                'active' => [
                    'output' => [[ContextOnlyTestOutput::class]],
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]);

        expect($test->machine()->output())->toBe(['total' => 99]);
    });

    it('output inner-array with all-default-params class works without config params', function (): void {
        $test = TestMachine::define([
            'initial' => 'active',
            'context' => ['total' => 99],
            'states'  => [
                'active' => [
                    'output' => [[FormatOutput::class, 'format' => 'json']],
                    'on'     => ['DONE' => 'completed'],
                ],
                'completed' => ['type' => 'final'],
            ],
        ]);

        expect($test->machine()->output())->toBe(['format' => 'json', 'total' => 99]);
    });

    // Test 84: Multiple @-prefixed keys in listener tuple stripped before injection
    it('strips all @-prefixed keys before injection to __invoke', function (): void {
        $test = TestMachine::define(
            config: [
                'id'      => 'audit_test',
                'initial' => 'idle',
                'context' => ['result' => null],
                'listen'  => [
                    'entry' => [
                        ['auditAction', 'verbose' => true, '@queue' => false, '@connection' => 'redis'],
                    ],
                ],
                'states' => [
                    'idle'   => ['on' => ['GO' => 'active']],
                    'active' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'auditAction' => function (ContextManager $ctx, bool $verbose): void {
                        $ctx->set('result', $verbose ? 'verbose' : 'quiet');
                    },
                ],
            ],
        );

        $test->send('GO');

        expect($test->machine()->state->context->get('result'))->toBe('verbose');
    });

    // Test 85: Config param value is an enum
    it('injects enum value from config param', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['result' => null],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'DO' => [
                                'actions' => [['enumAction', 'type' => BehaviorType::Guard]],
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'enumAction' => function (ContextManager $ctx, BehaviorType $type): void {
                        $ctx->set('result', $type->value);
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'DO']);

        expect($state->context->get('result'))->toBe('guards');
    });

    // Test 86: Config param value is a nested associative array
    it('injects nested associative array from config param', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['result' => null],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'DO' => [
                                'actions' => [['configAction', 'settings' => ['retries' => 3, 'timeout' => 30]]],
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'configAction' => function (ContextManager $ctx, array $settings): void {
                        $ctx->set('result', $settings['retries'] + $settings['timeout']);
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'DO']);

        expect($state->context->get('result'))->toBe(33);
    });

    // Test 87: Deep delegation chain — named params at every level (simplified inline version)
    it('named params work independently at different machine levels', function (): void {
        // Child machine with named param guard
        $childDef = MachineDefinition::define(
            config: [
                'initial' => 'checking',
                'context' => ['amount' => 200],
                'states'  => [
                    'checking' => [
                        'on' => [
                            'VERIFY' => [
                                'target' => 'verified',
                                'guards' => [[IsAboveThresholdGuard::class, 'threshold' => 100]],
                            ],
                        ],
                    ],
                    'verified' => ['type' => 'final'],
                ],
            ],
        );

        $childState = $childDef->transition(event: ['type' => 'VERIFY']);
        expect($childState->matches('verified'))->toBeTrue();

        // Parent machine with named param action
        $parentDef = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['total' => 100],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'PROCESS' => [
                                'actions' => [[AddValueByParamAction::class, 'value' => 50]],
                                'target'  => 'done',
                            ],
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
        );

        $parentState = $parentDef->transition(event: ['type' => 'PROCESS']);
        expect($parentState->context->get('total'))->toBe(150);
    });

    // Test 88: @always chain with named params — multiple hops
    it('@always chain with different named params per hop', function (): void {
        $test = NamedParamsAlwaysMachine::test();

        // amount=0 → threshold=100 fails, threshold=50 fails → low
        $test->send('START');
        $test->assertState('low');
    });

    it('@always chain routes to medium when amount is between thresholds', function (): void {
        $test = NamedParamsAlwaysMachine::test();
        $test->context()->set('amount', 75);

        // amount=75 → threshold=100 fails, threshold=50 passes → medium
        $test->send('START');
        $test->assertState('medium');
    });

    it('@always chain routes to high when amount exceeds all thresholds', function (): void {
        $test = NamedParamsAlwaysMachine::test();
        $test->context()->set('amount', 200);

        // amount=200 → threshold=100 passes → high
        $test->send('START');
        $test->assertState('high');
    });

    // Test 89: Behavior with only framework types + named params (no ContextManager)
    it('behavior with framework EventBehavior + named param works without ContextManager', function (): void {
        $machine = MachineDefinition::define(
            config: [
                'initial' => 'idle',
                'context' => ['amount' => 75],
                'states'  => [
                    'idle' => [
                        'on' => [
                            'CHECK' => [
                                'target' => 'passed',
                                'guards' => [['noCtxGuard', 'threshold' => 50]],
                            ],
                        ],
                    ],
                    'passed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'noCtxGuard' => function (EventDefinition $event, int $threshold): bool {
                        // Cannot access context — just check threshold is injected
                        return $threshold === 50;
                    },
                ],
            ],
        );

        $state = $machine->transition(event: ['type' => 'CHECK']);

        expect($state->matches('passed'))->toBeTrue();
    });
});

// ═══════════════════════════════════════════════════════════════
//  Machine stub integration
// ═══════════════════════════════════════════════════════════════

describe('Machine stub integration', function (): void {

    it('NamedParamsMachine guard passes with amount in range', function (): void {
        $test = NamedParamsMachine::test();

        $test->context()->set('amount', 500);
        $test->send('CHECK_RANGE');

        $test->assertState('in_range');
    });

    it('NamedParamsMachine guard fails with amount out of range', function (): void {
        $test = NamedParamsMachine::test();

        $test->context()->set('amount', 5000); // above max=1000
        $test->send('CHECK_RANGE');

        $test->assertState('idle');
    });

    it('NamedParamsMachine threshold guard works', function (): void {
        $test = NamedParamsMachine::test();

        $test->context()->set('amount', 100);
        $test->send('CHECK_THRESHOLD');

        $test->assertState('above_threshold');
    });

    it('NamedParamsMachine action adds value by param', function (): void {
        $test = NamedParamsMachine::test();

        $test->send('ADD_VALUE');

        $test->assertContext('total', 125);
    });

    it('NamedParamsMachine multiply action works', function (): void {
        $test = NamedParamsMachine::test();

        $test->send('MULTIPLY');

        $test->assertContext('total', 300);
    });

    it('NamedParamsMachine set level action works', function (): void {
        $test = NamedParamsMachine::test();

        $test->send('SET_LEVEL');

        $test->assertContext('level', 'info');
    });

    it('NamedParamsMachine calculator + guard chain works', function (): void {
        $test = NamedParamsMachine::test();

        $test->context()->set('amount', 1); // guard checks amount > 0
        $test->send('APPLY_DISCOUNT');

        $test->assertState('discounted');
        $test->assertContext('total', 85.0);
    });

    it('NamedParamsMachine final state output with named params', function (): void {
        $test = NamedParamsMachine::test();

        $test->send('FINISH');

        $test->assertState('completed');
        $test->assertOutput(['format' => 'json', 'total' => 100]);
    });

    it('NamedParamsAlwaysMachine routes based on amount', function (): void {
        // Low
        $test = NamedParamsAlwaysMachine::test();
        $test->send('START');
        $test->assertState('low');

        // Medium
        $test2 = NamedParamsAlwaysMachine::test();
        $test2->context()->set('amount', 75);
        $test2->send('START');
        $test2->assertState('medium');

        // High
        $test3 = NamedParamsAlwaysMachine::test();
        $test3->context()->set('amount', 200);
        $test3->send('START');
        $test3->assertState('high');
    });
});
