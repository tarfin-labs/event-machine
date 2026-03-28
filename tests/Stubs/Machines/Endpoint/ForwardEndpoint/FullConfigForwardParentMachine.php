<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestStartEvent;

/**
 * Parent using ALL Format 3 keys to verify complete parsing + routing.
 */
class FullConfigForwardParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'full_config_forward_parent',
                'initial' => 'idle',
                'context' => ['orderId' => null],
                'states'  => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'with'    => ['orderId'],
                        'forward' => [
                            ProvideCardEvent::class => [
                                'child_event'      => 'PROVIDE_CARD',
                                'uri'              => '/enter-payment-details',
                                'method'           => 'PATCH',
                                'middleware'       => ['throttle:10'],
                                'action'           => ForwardEndpointAction::class,
                                'output'           => PaymentStepResult::class,
                                'status'           => 202,
                                'available_events' => false,
                            ],
                        ],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START' => TestStartEvent::class,
                ],
                'outputs' => [
                    'paymentStepResult' => PaymentStepResult::class,
                ],
            ],
            endpoints: ['START'],
        );
    }
}
