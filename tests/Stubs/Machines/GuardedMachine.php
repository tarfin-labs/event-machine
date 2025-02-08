<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Actions\RecordAction;

class GuardedMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'initial' => 'active',
                'context' => [
                    'count' => 1,
                ],
                'states' => [
                    'active' => [
                        'on' => [
                            'CHECK' => [
                                [
                                    'guards'  => 'isEvenGuard',
                                    'actions' => RecordAction::class,
                                ],
                                [
                                    'target' => 'processed',
                                ],
                            ],
                            'INC' => [
                                'actions' => 'incrementAction',
                            ],
                        ],
                    ],
                    'processed' => [],
                ],
            ],
            behavior: [
                'guards' => [
                    'isEvenGuard' => function (ContextManager $context): bool {
                        return $context->get('count') % 2 === 0;
                    },
                ],
                'actions' => [
                    'incrementAction' => function (ContextManager $context): void {
                        $context->set('count', $context->get('count') + 1);
                    },
                ],
            ],
        );
    }
}
