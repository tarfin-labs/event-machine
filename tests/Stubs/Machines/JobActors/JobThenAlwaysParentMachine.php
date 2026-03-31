<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\JobActors;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Jobs\SuccessfulTestJob;

/**
 * Job actor @done → @always chain.
 *
 * Flow: idle → (START) → processing [job] → (@done) → routing → (@always) → completed
 * Tests that @always fires correctly after job actor completion via Horizon.
 */
class JobThenAlwaysParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'job_then_always',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'orderId' => 'ord_jta_001',
                    'routed'  => false,
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
                            'target'  => 'routing',
                            'actions' => 'markRoutedAction',
                        ],
                        '@fail' => 'failed',
                    ],
                    'routing' => [
                        'on' => [
                            '@always' => 'completed',
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                    'failed'    => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'markRoutedAction' => function (ContextManager $ctx): void {
                        $ctx->set('routed', true);
                    },
                ],
            ],
        );
    }
}
