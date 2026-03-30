<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\LockContention;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Machine stub for LocalQA lock contention tests.
 *
 * Has a slow entry action (2s sleep) on 'processing' state to hold the lock
 * long enough for concurrent access testing. GET /status is targetless.
 */
class SlowLockContentionMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'slow_lock_contention',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'counter' => 0,
                    'label'   => 'initial',
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START'            => 'processing',
                            'STATUS_REQUESTED' => null,
                        ],
                    ],
                    'processing' => [
                        'entry' => 'slowEntryAction',
                        'on'    => [
                            'COMPLETE'         => 'completed',
                            'STATUS_REQUESTED' => null,
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'actions' => [
                    'slowEntryAction' => SlowEntryAction::class,
                ],
                'events' => [
                    'START'            => SlowStartEvent::class,
                    'STATUS_REQUESTED' => SlowStatusEvent::class,
                    'COMPLETE'         => SlowCompleteEvent::class,
                ],
                'outputs' => [
                    'statusOutput' => SlowStatusOutput::class,
                ],
            ],
            endpoints: [
                'START',
                'COMPLETE',
                'STATUS_REQUESTED' => [
                    'uri'    => '/status',
                    'method' => 'GET',
                    'output' => 'statusOutput',
                ],
            ],
        );
    }
}

class SlowEntryAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        sleep(2);

        $context->set('counter', $context->get('counter') + 1);
        $context->set('label', 'processed');
    }
}

class SlowStartEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'START';
    }
}

class SlowCompleteEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'COMPLETE';
    }
}

class SlowStatusEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'STATUS_REQUESTED';
    }
}

class SlowStatusOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'counter' => $context->get('counter'),
            'label'   => $context->get('label'),
        ];
    }
}
