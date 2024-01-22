<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Yns;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Yns\Events\SEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Yns\Actions\YAction;

class YnsMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'should_persist' => false,
            'initial'        => 'stateY',
            'states'         => [
                'stateY' => [
                    'on' => [
                        SEvent::class => [
                            'target'  => 'stateS',
                            'actions' => [YAction::class],
                        ],
                    ],
                ],
                'stateS' => [],
            ],
        ]);
    }
}
