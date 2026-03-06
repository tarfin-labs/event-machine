<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\EEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Events\SEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\AAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\DAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\SAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Asd\Actions\SleepAction;

class AsdMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'initial' => 'state_a',
            'states'  => [
                'state_a' => [
                    'on' => [
                        SEvent::class => [
                            'target'  => 'state_s',
                            'actions' => [AAction::class],
                        ],
                        EEvent::class => [
                            'actions' => [SleepAction::class],
                        ],
                    ],
                ],
                'state_s' => [
                    'on' => [
                        '@always' => [
                            'target'  => 'state_d',
                            'actions' => [SAction::class],
                        ],
                    ],
                ],
                'state_d' => [
                    'entry' => [DAction::class],
                ],
                'state_e' => [],
            ],
        ]);
    }
}
