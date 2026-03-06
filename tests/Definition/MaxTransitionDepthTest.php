<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\State;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Exceptions\MaxTransitionDepthExceededException;

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
            'guards' => [
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
                    'onDone' => 'completed',
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
            'guards' => [
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
