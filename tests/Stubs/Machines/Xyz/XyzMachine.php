<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Events\YEvent;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions\XAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions\YAction;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Xyz\Actions\ZAction;

class XyzMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'xyz',
                'context' => [
                    'value' => '',
                ],
                'initial' => '#a',
                'states'  => [
                    '#a' => [
                        'entry' => '!x',
                        'on'    => [
                            '@x' => '#x',
                        ],
                    ],
                    '#x' => [
                        'entry' => '!y',
                        'on'    => [
                            YEvent::class => '#y',
                        ],
                    ],
                    '#y' => [
                        'entry' => '!z',
                        'on'    => [
                            '@z' => '#z',
                        ],
                    ],
                    '#z' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    '!x' => XAction::class,
                    '!y' => YAction::class,
                    '!z' => ZAction::class,
                ],
            ],
        );
    }
}
