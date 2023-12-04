<?php

declare(strict_types=1);

namespace Tarfinlabs\EventMachine\Tests\Stubs\Actions;

use Closure;
use Tarfinlabs\EventMachine\ContextManager;
use Tarfinlabs\EventMachine\Behavior\ActionBehavior;

class IsOddAction extends ActionBehavior
{
    public array $requiredContext = [
        'counts.oddCount' => 'integer',
    ];

    public function definition(): Closure
    {
        return function (ContextManager $context): void {
            $context->set('counts.oddCount', 1);
        };
    }
}
