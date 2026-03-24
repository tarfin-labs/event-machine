<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\IsValuePositiveValidationGuard;

class RejectRetryMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'reject_retry',
                'initial' => 'awaiting_input',
                'context' => [
                    'value' => 0,
                ],
                'states' => [
                    'awaiting_input' => [
                        'on' => [
                            'SUBMIT' => [
                                'target'  => 'accepted',
                                'guards'  => IsValuePositiveValidationGuard::class,
                                'actions' => 'storeValueAction',
                            ],
                        ],
                    ],
                    'accepted' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    IsValuePositiveValidationGuard::class,
                ],
                'actions' => [
                    'storeValueAction' => function (ContextManager $context, EventBehavior $event): void {
                        $context->set('value', $event->payload['value']);
                    },
                ],
            ],
        );
    }
}
