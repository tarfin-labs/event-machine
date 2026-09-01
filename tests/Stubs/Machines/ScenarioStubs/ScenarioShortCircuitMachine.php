<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Jobs\ProcessJob;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Guards\IsEligibleGuard;

/**
 * The one shape that separates resolve() from an implementation returning the first target
 * it reaches.
 *
 *   long   a(0) → b(0) → c(0) → d(DELEGATION 3) → t(0)   weight 3, 5 steps
 *   short  a(0) → m(DELEGATION 3) → n(0) → t(0)          weight 3, 4 steps
 *
 * Both weigh 3, so they agree on the first sort key and differ on the second. The long route
 * accumulates its weight LATE, so its last pre-target step reaches the cost-3 band while the
 * short route's is still being produced, and it is recorded first. Sorting then returns the
 * short one; returning the first target reached would return the long one.
 */
class ScenarioShortCircuitMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'scenario_short_circuit',
                'initial' => 'start',
                'states'  => [
                    'start' => [
                        'on' => [
                            'GO' => 'a',
                        ],
                    ],
                    'a' => [
                        'on' => [
                            '@always' => [
                                ['target' => 'b', 'guards' => IsEligibleGuard::class],
                                ['target' => 'm'],
                            ],
                        ],
                    ],
                    'b' => ['on' => ['@always' => 'c']],
                    'c' => ['on' => ['@always' => 'd']],
                    'd' => [
                        'job'   => ProcessJob::class,
                        '@done' => 't',
                    ],
                    'm' => [
                        'job'   => ProcessJob::class,
                        '@done' => 'n',
                    ],
                    'n' => ['on' => ['@always' => 't']],
                    't' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => ['IsEligibleGuard' => IsEligibleGuard::class],
            ],
        );
    }
}
