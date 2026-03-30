<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;

/**
 * Job actor with guarded @done branches.
 *
 * Flow: idle → (START) → processing [job] → @done with guards → approved / manual_review
 * Guard checks if output contains 'autoApproved' flag.
 */
class GuardedDoneJobParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'guarded_done_job',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'orderId'  => 'ord_guarded_001',
                    'approved' => false,
                ],
                'states' => [
                    'idle' => [
                        'on' => ['START' => 'processing'],
                    ],
                    'processing' => [
                        'job'   => SuccessfulTestJob::class,
                        'input' => ['orderId'],
                        'queue' => 'default',
                        '@done' => [
                            [
                                'target' => 'approved',
                                'guards' => 'isAutoApprovedGuard',
                            ],
                            [
                                'target' => 'manual_review',
                            ],
                        ],
                        '@fail' => 'failed',
                    ],
                    'approved'      => ['type' => 'final'],
                    'manual_review' => ['type' => 'final'],
                    'failed'        => ['type' => 'final'],
                ],
            ],
            behavior: [
                'guards' => [
                    'isAutoApprovedGuard' => function (ContextManager $ctx): bool {
                        // Guard checks if the context has autoApproved flag
                        return $ctx->get('approved') === true;
                    },
                ],
            ],
        );
    }
}
