<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class IsOddAction extends ActionBehavior
{
    public static array $requiredContext = [
        'counts.oddCount' => 'integer',
    ];

    public function __invoke(ContextManager $context): void
    {
        $context->set('counts.oddCount', 1);
    }
}
