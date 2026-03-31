<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine with OutputBehavior that returns status from context.
 * Immediately completes with a result.
 */
class FinalOutputChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'result_child',
                'initial' => 'done',
                'context' => [
                    'status'    => 'approved',
                    'paymentId' => 'pay_abc',
                ],
                'states' => [
                    'done' => [
                        'type'   => 'final',
                        'output' => function (ContextManager $ctx): array {
                            return [
                                'status'    => $ctx->get('status'),
                                'paymentId' => $ctx->get('paymentId'),
                            ];
                        },
                    ],
                ],
            ],
        );
    }
}
