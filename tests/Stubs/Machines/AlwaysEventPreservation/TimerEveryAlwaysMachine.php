<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\AlwaysEventPreservation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine where a recurring timer event triggers an @always chain.
 *
 * Flow: active → (BILLING) → routing(@always) → billed
 *
 * Simulates @every timer behavior. The @always action should receive
 * the BILLING event, not the synthetic @always event.
 */
class TimerEveryAlwaysMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'timer_every_always',
                'initial' => 'active',
                'context' => [
                    'billingEventType'    => null,
                    'billingEventPayload' => null,
                ],
                'states' => [
                    'active' => [
                        'on' => [
                            'BILLING' => 'routing',
                        ],
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => [
                                'target'  => 'billed',
                                'actions' => 'captureBillingEventAction',
                            ],
                        ],
                    ],
                    'billed' => [],
                ],
            ],
            behavior: [
                'actions' => [
                    'captureBillingEventAction' => CaptureBillingEventAction::class,
                ],
            ],
        );
    }
}
