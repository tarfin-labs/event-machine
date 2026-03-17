<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Routing\ForwardContext;
use Tarfinlabs\EventMachine\Behavior\ResultBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\TestStartEvent;

/**
 * Parent machine with forward + endpoints (mixed Format 1/3).
 *
 * Flow: idle → (START) → processing (async child with forward) → completed/failed
 * Forwards PROVIDE_CARD (plain) and CONFIRM_PAYMENT (with result + contextKeys).
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
                    'order_id' => null,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'machine' => ForwardChildEndpointMachine::class,
                        'queue'   => 'default',
                        'with'    => ['order_id'],
                        'forward' => [
                            'PROVIDE_CARD',
                            'CONFIRM_PAYMENT' => [
                                'result'      => PaymentStepResult::class,
                                'contextKeys' => ['card_last4', 'status'],
                                'status'      => 200,
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
                'results' => [
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

class PaymentStepResult extends ResultBehavior
{
    public function __invoke(ContextManager $context, ForwardContext $forwardContext): array
    {
        return [
            'order_id'   => $context->get('order_id'),
            'card_last4' => $forwardContext->childContext->get('card_last4'),
            'child_step' => $forwardContext->childState->value[0] ?? null,
        ];
    }
}
