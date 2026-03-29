<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\Endpoint\LockContention;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\EventBehavior;
use Tarfinlabs\EventMachine\Behavior\OutputBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

class LockContentionEndpointMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'             => 'lock_contention_endpoint',
                'initial'        => 'idle',
                'should_persist' => true,
                'context'        => [
                    'counter' => 0,
                    'label'   => 'initial',
                ],
                'states' => [
                    'idle' => [
                        'on' => [
                            'START'            => 'started',
                            'STATUS_REQUESTED' => null,
                        ],
                    ],
                    'started' => [
                        'on' => [
                            'COMPLETE'         => 'completed',
                            'STATUS_REQUESTED' => null,
                        ],
                    ],
                    'completed' => ['type' => 'final'],
                ],
            ],
            behavior: [
                'events' => [
                    'START'            => LockContentionStartEvent::class,
                    'STATUS_REQUESTED' => LockContentionStatusEvent::class,
                    'COMPLETE'         => LockContentionCompleteEvent::class,
                ],
                'outputs' => [
                    'statusOutput' => LockContentionStatusOutput::class,
                ],
            ],
            endpoints: [
                'START',
                'COMPLETE' => [
                    'status' => 201,
                ],
                'STATUS_REQUESTED' => [
                    'uri'    => '/status',
                    'method' => 'GET',
                    'output' => 'statusOutput',
                ],
            ],
        );
    }
}

class LockContentionStartEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'START';
    }
}

class LockContentionCompleteEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'COMPLETE';
    }
}

class LockContentionStatusEvent extends EventBehavior
{
    public static function getType(): string
    {
        return 'STATUS_REQUESTED';
    }
}

class LockContentionStatusOutput extends OutputBehavior
{
    public function __invoke(ContextManager $context): array
    {
        return [
            'counter' => $context->get('counter'),
            'label'   => $context->get('label'),
        ];
    }
}
