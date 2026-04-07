<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

/**
 * Entry action that logs and raises AUTO_ADVANCE event.
 * Used by ActionOrderingTest #10 to verify raise is processed after entry completes.
 */
class EntryBAndRaiseAutoAdvanceAction extends ActionBehavior
{
    public function __invoke(ContextManager $context): void
    {
        $context->set('actionLog', [...$context->get('actionLog'), 'entry:B']);
        $this->raise(['type' => 'AUTO_ADVANCE']);
    }
}
