<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Child machine that mutates context (for isolation testing).
 * Entry action changes order_id and adds extra field.
 */
class ContextMutatingChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'mutating_child',
                'initial' => 'working',
                'context' => [
                    'order_id' => null,
                    'extra'    => null,
                ],
                'states' => [
                    'working' => [
                        'entry' => 'mutateAction',
                        'on'    => ['DONE' => 'done'],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
                'actions' => [
                    'mutateAction' => function (ContextManager $ctx): void {
                        $ctx->set('order_id', 'CHANGED_BY_CHILD');
                        $ctx->set('extra', 'child_added_this');
                    },
                ],
            ],
        );
    }
}
