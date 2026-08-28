<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * A valid parent that delegates to a child whose definition() throws.
 *
 * It routes with `@done.{state}` and no catch-all `@done`, which is exactly the shape
 * unhandledChildOutcomes() inspects — so the child has to be built to answer the question,
 * and when building throws the check skips it. Before unanalysableChildren() that skip was
 * silent: machine:paths exited 0, listed the child, and printed no unhandled-outcome section,
 * which reads identically to "every outcome is handled".
 *
 * In fixtures/ rather than tests/Stubs/ so machine:validate --all does not sweep it.
 */
class UnanalysableChildParentMachine extends Machine
{
    public static function definition(): ?MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'unanalysable_child_parent',
            'initial' => 'delegating',
            'states'  => [
                'delegating' => [
                    'machine'  => ThrowingDefinitionMachine::class,
                    '@done.ok' => 'finished',
                ],
                'finished' => ['type' => 'final'],
            ],
        ]);
    }
}
