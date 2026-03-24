<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Machine where compound @done routes to a state with @always.
 *
 * Flow: verification(checking → done[final]) → @done → routing(@always) → completed
 *
 * The original CHECK_COMPLETED event should survive through @done → @always.
 */
class CompoundDoneAlwaysMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'compound_done_always',
                'initial' => 'verification',
                'context' => [
                    'done_event_type'    => null,
                    'done_event_payload' => null,
                ],
                'states' => [
                    'verification' => [
                        '@done'   => 'routing',
                        'initial' => 'checking',
                        'states'  => [
                            'checking' => [
                                'on' => [
                                    'CHECK_COMPLETED' => 'done',
                                ],
                            ],
                            'done' => ['type' => 'final'],
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'completed',
                                'actions' => 'captureDoneEventAction',
                            ],
                        ],
                    ],
                    'completed' => [],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'actions' => [
                    'captureDoneEventAction' => CaptureDoneEventAction::class,
                ],
            ],
        );
    }
}
