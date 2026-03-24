<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;
use Tarfinlabs\EventMachine\Tests\Stubs\Contexts\GenericContext;

/**
 * Parent machine that delegates to MultiOutcomeChildMachine with @done.{state} routing.
 * Used for LocalQA async tests.
 */
class DoneDotParentMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(config: [
            'id'      => 'done_dot_parent',
            'initial' => 'idle',
            'context' => [],
            'states'  => [
                'idle' => [
                    'on' => ['START' => 'processing'],
                ],
                'processing' => [
                    'machine'        => ImmediateApprovedChildMachine::class,
                    'queue'          => 'child-queue',
                    '@done.approved' => 'completed',
                    '@done.rejected' => 'declined',
                    '@fail'          => 'error',
                ],
                'completed' => ['type' => 'final'],
                'declined'  => ['type' => 'final'],
                'error'     => ['type' => 'final'],
            ],
        ],
            behavior: [
                'context' => GenericContext::class,
            ]);
    }
}
