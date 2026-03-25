<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ListenerMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that has its OWN listen config.
 * Used to verify child listeners fire independently of parent listeners.
 *
 * Flow: processing → @always done (final)
 * Listen: entry counter on every state entry
 */
class ChildWithListenMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'child_with_listen',
                'initial' => 'processing',
                'context' => ['childListenCount' => 0],
                'listen'  => [
                    'entry' => 'childCountAction',
                ],
                'states' => [
                    'processing' => ['on' => ['@always' => 'done']],
                    'done'       => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'childCountAction' => function (ContextManager $context): void {
                        $context->set('childListenCount', $context->get('childListenCount') + 1);
                    },
                ],
            ],
        );
    }
}
