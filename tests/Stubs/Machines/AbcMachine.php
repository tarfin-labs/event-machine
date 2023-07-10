<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines;

use Tarfinlabs\EventMachine\Definition\EventMachine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class AbcMachine extends EventMachine
{
    public static function build(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'initial' => 'stateB',
            'context' => [
                'modelA' => null,
                'value'  => 4,
            ],
            'states' => [
                'stateA' => [
                    'on' => [
                        'EVENT' => 'stateB',
                    ],
                ],
                'stateB' => [
                    'on' => [
                        '@always' => 'stateC',
                    ],
                ],
                'stateC' => [],
            ],
        ]);
    }
}
