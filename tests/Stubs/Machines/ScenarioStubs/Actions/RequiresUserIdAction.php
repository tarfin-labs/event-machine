<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Machines\ScenarioStubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Action that requires 'userId' in context.
 * Used to trigger MissingMachineContextException when userId is absent.
 */
class RequiresUserIdAction extends ActionBehavior
{
    public static array $requiredContext = ['userId' => 'int'];

    public function __invoke(ContextManager $ctx): void
    {
        $ctx->set('processed', true);
    }
}
