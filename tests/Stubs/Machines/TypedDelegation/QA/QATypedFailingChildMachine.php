<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\TypedDelegation\QA;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Failures\SimpleFailure;

/**
 * QA child machine that throws on entry for testing failure propagation via Horizon.
 *
 * Uses SimpleFailure (only $message) instead of PaymentFailure (has required $errorCode)
 * because SimpleFailure auto-resolves from Throwable::getMessage() without override.
 * PaymentFailure requires $errorCode which has no Throwable getter mapping,
 * causing MachineFailureResolutionException.
 */
class QATypedFailingChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'qa_typed_failing_child',
                'failure' => SimpleFailure::class,
                'initial' => 'processing',
                'context' => [],
                'states'  => [
                    'processing' => [
                        'entry' => function (): void {
                            throw new \RuntimeException('Payment gateway timeout');
                        },
                    ],
                ],
            ],
        );
    }
}
