<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Fixtures\InvalidMachines;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\TrafficLights\Actions\IncrementAction;

/**
 * Declares no typed context while its action expects one.
 *
 * The engine injects the machine's context unconditionally and PHP rejects it at the
 * call, so driving this machine raises a TypeError. It lives outside the PSR-4 roots
 * so the sweep never sees it; tests name it explicitly.
 */
class MiswiredContextMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'miswired_context',
                'initial' => 'active',
                'states'  => [
                    'active' => [
                        'on' => [
                            'INCREASE' => ['actions' => IncrementAction::class],
                        ],
                    ],
                ],
            ],
        );
    }
}
