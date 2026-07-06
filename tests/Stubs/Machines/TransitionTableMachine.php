<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RecordAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;

/**
 * Stub machine for Machine::assertTransitions() table-driven tests.
 *
 * Covers: plain transitions, guard-blocked transitions (isAllowedGuard),
 * guard-blocked self-transitions, validation-guard rejections, and an
 * action-carrying transition for faking-list tests.
 */
class TransitionTableMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'transition_table',
                'initial' => 'idle',
                'context' => [
                    'allowed' => false,
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START'           => 'working',
                            'GUARDED_START'   => ['target' => 'working', 'guards' => 'isAllowedGuard'],
                            'SELF_LOOP'       => ['target' => 'idle', 'guards' => 'isAllowedGuard'],
                            'VALIDATED_START' => ['target' => 'working', 'guards' => AlwaysFailValidationGuard::class],
                            'RECORDED_START'  => ['target' => 'working', 'actions' => RecordAction::class],
                        ],
                    ],
                    'working' => [
                        'on' => [
                            'FINISH' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'isAllowedGuard' => fn (ContextManager $context): bool => (bool) $context->get('allowed'),
                ],
            ],
        );
    }
}
