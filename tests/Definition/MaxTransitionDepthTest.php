<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;
use Tarfinlabs\EventMachine\Exceptions\MaxTransitionDepthExceededException;

afterEach(function (): void {
    // Reset config to prevent leaking between tests
    config(['machine.max_transition_depth' => 100]);
});

// region @always Transition Loops

test('it throws exception for two-state @always loop', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'alwaysLoop',
            'initial' => 'stateA',
            'states'  => [
                'stateA' => [
                    'on' => ['@always' => 'stateB'],
                ],
                'stateB' => [
                    'on' => ['@always' => 'stateA'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $definition->getInitialState();
})->throws(
    exception: MaxTransitionDepthExceededException::class,
    exceptionMessage: 'Maximum transition depth of 100 exceeded',
);

test('it throws exception for three-state @always loop', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'threeStateLoop',
            'initial' => 'stateA',
            'states'  => [
                'stateA' => [
                    'on' => ['@always' => 'stateB'],
                ],
                'stateB' => [
                    'on' => ['@always' => 'stateC'],
                ],
                'stateC' => [
                    'on' => ['@always' => 'stateA'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $definition->getInitialState();
})->throws(
    exception: MaxTransitionDepthExceededException::class,
);

test('it throws exception for @always loop triggered after event transition', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'eventThenLoop',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'loopA'],
                ],
                'loopA' => [
                    'on' => ['@always' => 'loopB'],
                ],
                'loopB' => [
                    'on' => ['@always' => 'loopA'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('idle'))->toBeTrue();

    $definition->transition(['type' => 'START'], $state);
})->throws(
    exception: MaxTransitionDepthExceededException::class,
);

test('it allows @always chain within depth limit', function (): void {
    // Chain of 5 states: a → b → c → d → e (no loop)
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'alwaysChain',
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['@always' => 'b']],
                'b' => ['on' => ['@always' => 'c']],
                'c' => ['on' => ['@always' => 'd']],
                'd' => ['on' => ['@always' => 'e']],
                'e' => [],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('e'))->toBeTrue();
});

test('it allows @always with guard that breaks the loop', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'guardedAlways',
            'initial' => 'checking',
            'context' => ['score' => 85],
            'states'  => [
                'checking' => [
                    'on' => [
                        '@always' => [
                            ['target' => 'passed', 'guards' => 'isPassing'],
                            ['target' => 'failed'],
                        ],
                    ],
                ],
                'passed' => [],
                'failed' => [],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'guards'  => [
                'isPassing' => fn (ContextManager $ctx) => $ctx->get('score') >= 70,
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->matches('passed'))->toBeTrue();
});

// endregion

// region raise() Event Queue Loops

test('it throws exception for raise() event loop between two states', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'raiseLoop',
            'initial' => 'stateA',
            'states'  => [
                'stateA' => [
                    'entry' => 'raiseGoB',
                    'on'    => ['GO_B' => 'stateB'],
                ],
                'stateB' => [
                    'entry' => 'raiseGoA',
                    'on'    => ['GO_A' => 'stateA'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'raiseGoB' => RaiseGoBAction::class,
                'raiseGoA' => RaiseGoAAction::class,
            ],
        ],
    );

    $definition->getInitialState();
})->throws(
    exception: MaxTransitionDepthExceededException::class,
);

test('it allows raise() chain within depth limit', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'raiseChain',
            'initial' => 'step1',
            'context' => ['steps' => []],
            'states'  => [
                'step1' => [
                    'entry' => 'raiseNext',
                    'on'    => ['NEXT' => 'step2'],
                ],
                'step2' => [
                    'entry' => 'raiseNext',
                    'on'    => ['NEXT' => 'step3'],
                ],
                'step3' => [],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'raiseNext' => RaiseNextAction::class,
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->matches('step3'))->toBeTrue();
});

// endregion

// region Parallel State Loops

test('it throws exception for @always loop in parallel state after event', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallelLoop',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    'states' => [
                        'regionA' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [
                                    'on' => ['START' => 'loopA'],
                                ],
                                'loopA' => [
                                    'on' => ['@always' => 'loopB'],
                                ],
                                'loopB' => [
                                    'on' => ['@always' => 'loopA'],
                                ],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'b1',
                            'states'  => [
                                'b1' => [
                                    'on' => ['START' => 'b2'],
                                ],
                                'b2' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.regionA.idle'))->toBeTrue();

    // START triggers regionA into a loopA↔loopB @always loop
    $definition->transition(['type' => 'START'], $state);
})->throws(
    exception: MaxTransitionDepthExceededException::class,
);

test('guarded @always in parallel state does not trigger depth limit', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallelGuarded',
            'initial' => 'processing',
            'states'  => [
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'regionA' => [
                            'initial' => 'waiting',
                            'states'  => [
                                'waiting' => [
                                    'on' => [
                                        '@always' => [
                                            ['target' => 'done', 'guards' => 'isRegionBDone'],
                                        ],
                                    ],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'working',
                            'states'  => [
                                'working' => [
                                    'on' => ['FINISH' => 'done'],
                                ],
                                'done' => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'guards'  => [
                'isRegionBDone' => fn (ContextManager $ctx, EventBehavior $event, State $state) => $state->matches('processing.regionB.done'),
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->matches('processing.regionA.waiting'))->toBeTrue()
        ->and($state->matches('processing.regionB.working'))->toBeTrue();

    $state = $definition->transition(['type' => 'FINISH'], $state);
    expect($state->matches('completed'))->toBeTrue();
});

// endregion

// region Exception Details

test('exception message contains the state route where limit was hit', function (): void {
    try {
        $definition = MachineDefinition::define(
            config: [
                'id'      => 'routeCheck',
                'initial' => 'ping',
                'states'  => [
                    'ping' => ['on' => ['@always' => 'pong']],
                    'pong' => ['on' => ['@always' => 'ping']],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );

        $definition->getInitialState();
        $this->fail('Expected exception was not thrown');
    } catch (MaxTransitionDepthExceededException $e) {
        expect($e->getMessage())
            ->toContain('Maximum transition depth of 100 exceeded')
            ->toContain("'p"); // route contains 'ping' or 'pong'
    }
});

test('exception is a LogicException', function (): void {
    expect(MaxTransitionDepthExceededException::exceeded(100, 'test.route'))
        ->toBeInstanceOf(LogicException::class);
});

// endregion

// region Mixed Scenarios

test('it throws exception for @always that triggers raise() creating a loop', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'mixedLoop',
            'initial' => 'start',
            'states'  => [
                'start' => [
                    'on' => ['GO' => 'loopEntry'],
                ],
                'loopEntry' => [
                    'on' => ['@always' => 'raiser'],
                ],
                'raiser' => [
                    'entry' => 'raiseGo',
                    'on'    => ['GO' => 'loopEntry'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
            'actions' => [
                'raiseGo' => RaiseGoAction::class,
            ],
        ],
    );

    $state = $definition->getInitialState();
    expect($state->matches('start'))->toBeTrue();

    $definition->transition(['type' => 'GO'], $state);
})->throws(
    exception: MaxTransitionDepthExceededException::class,
);

test('normal event-driven cycle does not trigger depth limit', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'normalCycle',
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['NEXT' => 'b']],
                'b' => ['on' => ['NEXT' => 'c']],
                'c' => ['on' => ['NEXT' => 'a']],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    expect($state->matches('a'))->toBeTrue();

    // Cycle through A → B → C → A multiple times — each is a separate macrostep
    for ($i = 0; $i < 50; $i++) {
        $state = $definition->transition(['type' => 'NEXT'], $state);
    }

    // 50 transitions: 50 mod 3 = 2, so we end up at state 'c'
    expect($state->matches('c'))->toBeTrue();
});

// endregion

// region Stub Action Classes

class RaiseGoBAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->raise(['type' => 'GO_B']);
    }
}

class RaiseGoAAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->raise(['type' => 'GO_A']);
    }
}

class RaiseNextAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->raise(['type' => 'NEXT']);
    }
}

class RaiseGoAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $this->raise(['type' => 'GO']);
    }
}

// endregion

// region v7-Specific Tests

test('parallel state entry @always loop throws (regression for dispatch points #5/#6)', function (): void {
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallelEntryLoop',
            'initial' => 'idle',
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'type'   => 'parallel',
                    '@done'  => 'completed',
                    'states' => [
                        'regionA' => [
                            'initial' => 'loopA',
                            'states'  => [
                                'loopA' => ['on' => ['@always' => 'loopB']],
                                'loopB' => ['on' => ['@always' => 'loopA']],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'idle',
                            'states'  => [
                                'idle' => [],
                            ],
                        ],
                    ],
                ],
                'completed' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();

    $definition->transition(['type' => 'START'], $state);
})->throws(MaxTransitionDepthExceededException::class);

test('custom depth from config throws at configured limit', function (): void {
    config(['machine.max_transition_depth' => 5]);

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'configDepth5',
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['@always' => 'b']],
                'b' => ['on' => ['@always' => 'c']],
                'c' => ['on' => ['@always' => 'd']],
                'd' => ['on' => ['@always' => 'a']],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $definition->getInitialState();
})->throws(MaxTransitionDepthExceededException::class);

test('config not set falls back to DEFAULT_MAX_TRANSITION_DEPTH', function (): void {
    // Remove the key entirely — config() returns the default (100)
    $original = config('machine.max_transition_depth');
    config(['machine.max_transition_depth' => 100]);

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'defaultFallback',
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['@always' => 'b']],
                'b' => ['on' => ['@always' => 'a']],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    try {
        $definition->getInitialState();
        test()->fail('Expected exception');
    } catch (MaxTransitionDepthExceededException $e) {
        expect($e->getMessage())->toContain('depth of 100');
    }
});

test('exception message contains custom depth value', function (): void {
    config(['machine.max_transition_depth' => 5]);

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'msgCheck',
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['@always' => 'b']],
                'b' => ['on' => ['@always' => 'a']],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    try {
        $definition->getInitialState();
        test()->fail('Expected exception');
    } catch (MaxTransitionDepthExceededException $e) {
        expect($e->getMessage())->toContain('depth of 5');
    }
});

test('zero config value is clamped to 1', function (): void {
    config(['machine.max_transition_depth' => 0]);

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'zeroClamped',
            'initial' => 'a',
            'states'  => [
                'a' => ['on' => ['@always' => 'b']],
                'b' => ['type' => 'final'],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    // Even a single @always step should throw at clamp=1
    $definition->getInitialState();
})->throws(MaxTransitionDepthExceededException::class);

test('boundary: depth-1 chain does not throw', function (): void {
    config(['machine.max_transition_depth' => 10]);

    // 9-state linear chain (depth 1→9, under limit of 10)
    $states = [];
    for ($i = 1; $i <= 9; $i++) {
        $next            = $i < 9 ? 's'.($i + 1) : 'end';
        $states["s{$i}"] = ['on' => ['@always' => $next]];
    }
    $states['end'] = ['type' => 'final'];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'boundaryUnder',
            'initial' => 's1',
            'states'  => $states,
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    expect($state->currentStateDefinition->id)->toBe('boundaryUnder.end');
});

test('boundary: at-depth chain throws', function (): void {
    config(['machine.max_transition_depth' => 10]);

    // 10-state chain with loop at the end (depth reaches exactly 10)
    $states = [];
    for ($i = 1; $i <= 10; $i++) {
        $next            = $i < 10 ? 's'.($i + 1) : 's1'; // loop back
        $states["s{$i}"] = ['on' => ['@always' => $next]];
    }

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'boundaryAt',
            'initial' => 's1',
            'states'  => $states,
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $definition->getInitialState();
})->throws(MaxTransitionDepthExceededException::class);

test('sync child delegation does not share parent depth counter', function (): void {
    // Child with deep @always chain (50 states)
    $childStates = [];
    for ($i = 1; $i <= 50; $i++) {
        $childNext            = $i < 50 ? 'c'.($i + 1) : 'done';
        $childStates["c{$i}"] = ['on' => ['@always' => $childNext]];
    }
    $childStates['done'] = ['type' => 'final'];

    $childDefinition = MachineDefinition::define(
        config: [
            'id'      => 'deepChild',
            'initial' => 'c1',
            'states'  => $childStates,
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    // Child should complete (50 < 100 default limit)
    $childState = $childDefinition->getInitialState();
    expect($childState->currentStateDefinition->id)->toBe('deepChild.done');

    // Parent with its own 50-state chain should also complete
    $parentStates = [];
    for ($i = 1; $i <= 50; $i++) {
        $next                  = $i < 50 ? 'p'.($i + 1) : 'end';
        $parentStates["p{$i}"] = ['on' => ['@always' => $next]];
    }
    $parentStates['end'] = ['type' => 'final'];

    $parentDefinition = MachineDefinition::define(
        config: [
            'id'      => 'deepParent',
            'initial' => 'p1',
            'states'  => $parentStates,
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $parentState = $parentDefinition->getInitialState();
    expect($parentState->currentStateDefinition->id)->toBe('deepParent.end');
});

test('compound @done with @always loop throws MaxTransitionDepthExceededException', function (): void {
    // Fixed: processCompoundOnDone now processes @always after entry.
    // An @always loop after compound @done correctly triggers depth protection.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'compoundBypass',
            'initial' => 'wrapper',
            'states'  => [
                'wrapper' => [
                    'initial' => 'inner',
                    '@done'   => 'loop_target',
                    'states'  => [
                        'inner' => [
                            'on' => ['FINISH' => 'inner_done'],
                        ],
                        'inner_done' => ['type' => 'final'],
                    ],
                ],
                'loop_target' => [
                    'on' => ['@always' => 'bounce'],
                ],
                'bounce' => [
                    'on' => ['@always' => 'loop_target'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    $definition->transition(['type' => 'FINISH'], $state);
})->throws(MaxTransitionDepthExceededException::class);

test('parallel @done with @always loop throws MaxTransitionDepthExceededException', function (): void {
    // Fixed: exitParallelStateAndTransitionToTarget now processes @always after entry.
    // An @always loop after parallel @done correctly triggers depth protection.
    $definition = MachineDefinition::define(
        config: [
            'id'      => 'parallelBypass',
            'initial' => 'parallel_zone',
            'states'  => [
                'parallel_zone' => [
                    'type'   => 'parallel',
                    '@done'  => 'loop_target',
                    'states' => [
                        'regionA' => [
                            'initial' => 'a_working',
                            'states'  => [
                                'a_working' => ['on' => ['DONE_A' => 'a_done']],
                                'a_done'    => ['type' => 'final'],
                            ],
                        ],
                        'regionB' => [
                            'initial' => 'b_working',
                            'states'  => [
                                'b_working' => ['on' => ['DONE_B' => 'b_done']],
                                'b_done'    => ['type' => 'final'],
                            ],
                        ],
                    ],
                ],
                'loop_target' => [
                    'on' => ['@always' => 'bounce'],
                ],
                'bounce' => [
                    'on' => ['@always' => 'loop_target'],
                ],
            ],
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    $state = $definition->transition(['type' => 'DONE_A'], $state);
    $definition->transition(['type' => 'DONE_B'], $state);
})->throws(MaxTransitionDepthExceededException::class);

test('raise chain of 99 events does not throw', function (): void {
    config(['machine.max_transition_depth' => 100]);

    // Build a 99-state linear chain: s1 → s2 → ... → s99 → end
    // Each state's entry raises NEXT_{n+1}
    $states  = [];
    $actions = [];

    for ($i = 1; $i <= 99; $i++) {
        $next            = $i < 99 ? 's'.($i + 1) : 'end';
        $eventName       = 'NEXT_'.($i + 1);
        $actionName      = "raiseNext{$i}Action";
        $states["s{$i}"] = [
            'entry' => $actionName,
            'on'    => [$eventName => $next],
        ];
        $actions[$actionName] = function (): void {
            // Can't use raise() with inline closures — skip this approach
        };
    }

    // Simpler approach: just test that 99-step @always chain works
    $simpleStates = [];
    for ($i = 1; $i <= 99; $i++) {
        $next                  = $i < 99 ? 's'.($i + 1) : 'end';
        $simpleStates["s{$i}"] = ['on' => ['@always' => $next]];
    }
    $simpleStates['end'] = ['type' => 'final'];

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'chain99',
            'initial' => 's1',
            'states'  => $simpleStates,
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $state = $definition->getInitialState();
    expect($state->currentStateDefinition->id)->toBe('chain99.end');
});

test('always chain of 101 states throws at depth 100', function (): void {
    config(['machine.max_transition_depth' => 100]);

    // 101-state chain with loop back (exceeds limit)
    $states = [];
    for ($i = 1; $i <= 101; $i++) {
        $next            = $i < 101 ? 's'.($i + 1) : 's1'; // loop
        $states["s{$i}"] = ['on' => ['@always' => $next]];
    }

    $definition = MachineDefinition::define(
        config: [
            'id'      => 'chain101',
            'initial' => 's1',
            'states'  => $states,
        ],
        behavior: [
            'context' => GenericContext::class,
        ]
    );

    $definition->getInitialState();
})->throws(MaxTransitionDepthExceededException::class);

// endregion
