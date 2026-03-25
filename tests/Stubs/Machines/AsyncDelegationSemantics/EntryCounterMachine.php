<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AsyncDelegationSemantics;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine with an entry action that increments a counter.
 * Used to verify that restore from DB does NOT replay entry actions.
 */
class EntryCounterMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'entry_counter',
                'initial' => 'idle',
                'context' => [
                    'entryCount' => 0,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['GO' => 'active'],
                    ],
                    'active' => [
                        'entry' => 'incrementAction',
                        'on'    => ['NEXT' => 'finished'],
                    ],
                    'finished' => [
                        'type' => 'final',
                    ],
                ],
            ],
            behavior: [
                'actions' => [
                    'incrementAction' => function (ContextManager $ctx): void {
                        $ctx->set('entryCount', $ctx->get('entryCount') + 1);
                    },
                ],
            ],
        );
    }
}
