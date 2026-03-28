<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestStartEvent;

/**
 * Parent machine with forward + endpoints (mixed Format 1/3).
 *
 * Flow: idle → (START) → processing (async child with forward) → completed/failed
 * Forwards PROVIDE_CARD (plain) and CONFIRM_PAYMENT (with output).
 */
class ForwardParentEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'forward_endpoint_parent',
                'initial' => 'idle',
                'context' => [
                    'orderId' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'with'    => ['orderId'],
                        'forward' => [
                            'PROVIDE_CARD',
                            'CONFIRM_PAYMENT' => [
                                'output' => PaymentStepResult::class,
                                'status' => 200,
                            ],
                        ],
                        'on'    => ['CANCEL' => 'cancelled'],
                        '@done' => 'completed',
                        '@fail' => 'failed',
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                    'cancelled' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'  => TestStartEvent::class,
                    'CANCEL' => TestStartEvent::class,
                ],
                'outputs' => [
                    'paymentStepResult' => PaymentStepResult::class,
                ],
            ],
            endpoints: [
                'START',
                'CANCEL',
            ],
        );
    }
}

class PaymentStepResult extends OutputBehavior
{
    public function __invoke(ContextManager $context, ForwardContext $forwardContext): array
    {
        return [
            'orderId'   => $context->get('orderId'),
            'cardLast4' => $forwardContext->childContext->get('cardLast4'),
            'childStep' => $forwardContext->childState->value[0] ?? null,
        ];
    }
}
