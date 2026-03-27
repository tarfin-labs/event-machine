<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\ForwardEndpoint;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that accepts forwarded events from a parent.
 *
 * Flow: awaiting_card → (PROVIDE_CARD) → awaiting_confirmation → (CONFIRM_PAYMENT) → charged
 *                                                                 (ABORT) → aborted
 */
class ForwardChildEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'forward_endpoint_child',
                'initial' => 'awaiting_card',
                'context' => [
                    'orderId'   => null,
                    'cardLast4' => null,
                    'status'    => 'pending',
                ],
                'states' => [
                    'awaiting_card' => [
                        'on' => [
                            'PROVIDE_CARD' => [
                                'target'  => 'awaiting_confirmation',
                                'actions' => 'storeCardAction',
                            ],
                        ],
                    ],
                    'awaiting_confirmation' => [
                        'on' => [
                            'CONFIRM_PAYMENT' => 'charged',
                            'ABORT'           => 'aborted',
                        ],
                    ],
                    'charged' => ['type' => 'final'],
                    'aborted' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'PROVIDE_CARD'    => ProvideCardEvent::class,
                    'CONFIRM_PAYMENT' => ConfirmPaymentEvent::class,
                    'ABORT'           => AbortEvent::class,
                ],
                'actions' => [
                    'storeCardAction' => function (ContextManager $ctx, EventBehavior $event): void {
                        $cardNumber = $event->payload['cardNumber'] ?? '';
                        $ctx->set('cardLast4', substr($cardNumber, -4));
                        $ctx->set('status', 'card_provided');
                    },
                ],
            ],
        );
    }
}
