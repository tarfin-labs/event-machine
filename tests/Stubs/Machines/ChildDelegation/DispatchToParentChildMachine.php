<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ChildDelegation;

use Tarfinlabs\EventMachine\Actor\Machine;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;
use Tarfinlabs\EventMachine\Definition\MachineDefinition;

/**
 * Child machine that calls dispatchToParent when receiving REPORT_PROGRESS event.
 *
 * Note: dispatchToParent cannot be used in entry actions during start() because
 * parent identity (setMachineIdentity) is set AFTER start() in ChildMachineJob.
 * So we use an external event to trigger the dispatch after the child is persisted.
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
                        'on' => [
                            'REPORT_PROGRESS' => [
                                'target'  => 'working',
                                'actions' => SendProgressToParentAction::class,
                            ],
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

        $this->dispatchToParent($context, [
            'type'    => 'CHILD_PROGRESS',
            'payload' => ['progress' => 50],
        ]);
    }
}
