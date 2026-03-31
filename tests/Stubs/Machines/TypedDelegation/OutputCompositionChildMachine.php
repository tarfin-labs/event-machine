<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Outputs\PaymentOutput;

/**
 * Child machine with OutputBehavior that returns a MachineOutput instance (composition pattern).
 */
class OutputCompositionChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'output_composition_child',
                'initial' => 'processing',
                'context' => [
                    'paymentId' => 'pay_composed',
                    'status'    => 'success',
                ],
                'states' => [
                    'processing' => [
                        'on' => ['DONE' => 'completed'],
                    ],
                    'completed' => [
                        'type'   => 'final',
                        'output' => 'computedPaymentOutput',
                    ],
                ],
            ],
            behavior: [
                'outputs' => [
                    'computedPaymentOutput' => ComputedPaymentOutputBehavior::class,
                ],
            ],
        );
    }
}

class ComputedPaymentOutputBehavior extends OutputBehavior
{
    public function __invoke(ContextManager $ctx): PaymentOutput
    {
        return new PaymentOutput(
            paymentId: $ctx->get('paymentId'),
            status: $ctx->get('status').'_computed',
        );
    }
}
