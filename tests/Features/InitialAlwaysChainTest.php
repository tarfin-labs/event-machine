<?php

declare(strict_types=1);

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Test that @always chains from an initial state resolve completely
 * within a single macrostep — the machine must never be observable
 * in an intermediate transient state.
 */

// ── Stub Machine ──────────────────────────────────────────────

class InitialAlwaysChainMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'initial_always_chain',
                'initial' => 'workflow',
                'context' => [
                    'actionLog' => [],
                ],
                'states' => [
                    'workflow' => [
                        'initial' => 'step_one',
                        'states'  => [
                            'step_one' => [
                                'entry' => 'logStepOneAction',
                                'on'    => [
                                    '@always' => [
                                        'target' => 'step_two',
                                        'guards' => 'alwaysTrueGuard',
                                    ],
                                ],
                            ],
                            'step_two' => [
                                'entry' => 'logStepTwoAction',
                                'on'    => [
                                    '@always' => [
                                        'target' => 'step_three',
                                        'guards' => 'alwaysTrueGuard',
                                    ],
                                ],
                            ],
                            'step_three' => [
                                'entry' => 'logStepThreeAction',
                            ],
                        ],
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'logStepOneAction' => function (ContextManager $context): void {
                        $log   = $context->get('actionLog');
                        $log[] = 'entry:step_one';
                        $context->set('actionLog', $log);
                    },
                    'logStepTwoAction' => function (ContextManager $context): void {
                        $log   = $context->get('actionLog');
                        $log[] = 'entry:step_two';
                        $context->set('actionLog', $log);
                    },
                    'logStepThreeAction' => function (ContextManager $context): void {
                        $log   = $context->get('actionLog');
                        $log[] = 'entry:step_three';
                        $context->set('actionLog', $log);
                    },
                ],
                'guards' => [
                    'alwaysTrueGuard' => fn (): bool => true,
                ],
            ],
        );
    }
}

// ── Tests ─────────────────────────────────────────────────────

it('resolves initial @always chain to final stable state in a single macrostep', function (): void {
    $machine = InitialAlwaysChainMachine::create();

    // Machine must have resolved through the entire @always chain
    // and be sitting at step_three, not step_one or step_two.
    expect($machine->state->value)
        ->toBe(['initial_always_chain.workflow.step_three']);
});

it('executes all entry actions through the @always chain', function (): void {
    $machine = InitialAlwaysChainMachine::create();

    $actionLog = $machine->state->context->get('actionLog');

    expect($actionLog)->toBe([
        'entry:step_one',
        'entry:step_two',
        'entry:step_three',
    ]);
});

it('does not remain in any transient intermediate state', function (): void {
    $machine = InitialAlwaysChainMachine::create();

    // Verify we are NOT stuck in step_one or step_two
    expect($machine->state->value)
        ->not->toBe(['initial_always_chain.workflow.step_one'])
        ->not->toBe(['initial_always_chain.workflow.step_two']);

    // Confirm the final stable state
    expect($machine->state->currentStateDefinition->id)
        ->toContain('step_three');
});
