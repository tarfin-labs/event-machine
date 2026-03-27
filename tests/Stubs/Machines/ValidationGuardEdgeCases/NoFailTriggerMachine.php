<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ValidationGuardEdgeCases\Guards\AlwaysFailValidationGuard;

class NoFailTriggerMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'no_fail_trigger',
                'initial' => 'processing',
                'context' => [
                    'failReached' => false,
                ],
                'states' => [
                    'processing' => [
                        'on' => [
                            'VALIDATE' => [
                                'target' => 'completed',
                                'guards' => AlwaysFailValidationGuard::class,
                            ],
                        ],
                        '@fail' => [
                            'target'  => 'failed',
                            'actions' => 'markFailAction',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    AlwaysFailValidationGuard::class,
                ],
                'actions' => [
                    'markFailAction' => function (ContextManager $context): void {
                        $context->set('failReached', true);
                    },
                ],
            ],
        );
    }
}
