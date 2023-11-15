<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\SleepAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\SEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\AAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\DAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\SAction;

class AsdMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'initial' => 'stateA',
            'states'  => [
                'stateA' => [
                    'on' => [
                        SEvent::class => [
                            'target'  => 'stateS',
                            'actions' => [AAction::class],
                        ],
                        EEvent::class => [
                            'actions' => [SleepAction::class],
                        ]
                    ],
                ],
                'stateS' => [
                    'on' => [
                        '@always' => [
                            'target'  => 'stateD',
                            'actions' => [SAction::class],
                        ],
                    ],
                ],
                'stateD' => [
                    'entry' => [DAction::class],
                ],
                'stateE' => [],
            ],
        ]);
    }
}
