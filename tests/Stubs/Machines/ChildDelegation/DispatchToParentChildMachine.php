<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that calls dispatchToParent in entry action.
 * Used for testing child→parent communication via Horizon.
 */
class DispatchToParentChildMachine extends Machine
{
    public static function definition(): MachineDefinition
    {
        return MachineDefinition::define(
            config: [
                'id'      => 'dispatch_to_parent_child',
                'initial' => 'working',
                'context' => [
                    'progress' => 0,
                ],
                'states' => [
                    'working' => [
                        'entry' => SendProgressToParentAction::class,
                        'on'    => [
                            'FINISH' => 'done',
                        ],
                    ],
                    'done' => ['type' => 'final'],
                ],
            ],
        );
    }
}

class SendProgressToParentAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('progress', 50);

        $this->dispatchToParent([
            'type'    => 'CHILD_PROGRESS',
            'payload' => ['progress' => 50],
        ]);
    }
}
