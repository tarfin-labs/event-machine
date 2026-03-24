<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Child machine with ResultBehavior that returns status from context.
 * Immediately completes with a result.
 */
class ResultChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'result_child',
                'initial' => 'done',
                'context' => [
                    'status'     => 'approved',
                    'payment_id' => 'pay_abc',
                ],
                'states' => [
                    'done' => [
                        'type'   => 'final',
                        'result' => function (ContextManager $ctx): array {
                            return [
                                'status'     => $ctx->get('status'),
                                'payment_id' => $ctx->get('payment_id'),
                            ];
                        },
                    ],
                ],
            ],
            behavior: [
                'context' => GenericContext::class,
            ]
        );
    }
}
